<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Team Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Team\Tests\Integration\Roster;

use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Service\TeamOverview;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * WHAT THE TEAM PAGE KNOWS BEFORE IT DRAWS A ROW: the five counts on the strip
 * and the rows in the attention pane.
 *
 * EVERY COUNT IS A QUERY, never a stored number. A person who is deactivated
 * stops being counted as active because the column says so, not because
 * something remembered to decrement.
 *
 * THE PANE COLLAPSES TO NOTHING when nobody needs attention, and the sole-Super
 * -Admin risk is the one row that is not a task: it has no done, so it carries
 * no action. Resolving it means promoting somebody, which is a decision and not
 * a button on a warning.
 */
final class TeamOverviewTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->madeDepartments = [];
    }

    private function overview(): TeamOverview
    {
        return $this->service(TeamOverview::class);
    }

    private function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = (new User())
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')->setTeamRole($tier)
            ->setVerified(true);
        $this->em->persist($user);

        return $user;
    }

    /** @var array<string, Department> departments made in this test, before any flush */
    private array $madeDepartments = [];

    /** @param list<PermissionEnum> $permissions */
    private function position(string $name, string $department, array $permissions = []): Position
    {
        $dept = $this->madeDepartments[$department] ??= new Department()->setName($department);
        if (null === $dept->getId()) {
            $this->em->persist($dept);
        }

        $position = (new Position())->setName($name)->setDepartment($dept);
        $position->setPermissionValues(
            array_map(static fn (PermissionEnum $p): string => $p->value, $permissions),
            array_map(static fn (PermissionEnum $p): string => $p->value, PermissionEnum::all()),
        );
        $this->em->persist($position);

        return $position;
    }

    /**
     * A FRESH INSTALLATION IS ONE SUPER ADMIN AND NOTHING ELSE, and the page
     * says so rather than drawing a strip of zeros. Four of the five counts
     * would be nothing, and a strip of zeros is a strip that says nothing.
     */
    public function testAFreshInstallationSaysSo(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertTrue($overview->isFirstRun);
        self::assertSame(1, $overview->people);
    }

    public function testAsSoonAsThereIsASecondPersonItIsNotAFirstRun(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Grace', 'Ndosi');
        $this->em->flush();

        self::assertFalse($this->overview()->build()->isFirstRun);
    }

    public function testThePeopleCountSplitsActiveFromDeactivated(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Grace', 'Ndosi');
        $this->person('Hawa', 'Rajabu')->deactivate();
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertSame(3, $overview->people);
        self::assertSame(2, $overview->active);
        self::assertSame(1, $overview->deactivated);
    }

    public function testThePositionsCountNamesItsDepartmentsAndHowManyAreHeld(): void
    {
        $ranger = $this->position('Ranger', 'Protection Service');
        $this->position('Analyst', 'Ecology');
        // A position nobody holds, created before its first person — the twin
        // the per-department-uniqueness ruling exists for.
        $this->position('Analyst', 'Protection Service');
        $this->person('Grace', 'Ndosi')->setPosition($ranger);
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertSame(3, $overview->positions);
        self::assertSame(2, $overview->departments);
        self::assertSame(1, $overview->positionsHeld);
    }

    /**
     * ONE NUMBER, TWO MECHANISMS. Some administrators hold the power by tier and
     * some because a position they hold carries team.manage — and the sub-line
     * has to name both, or the number is unreadable. This is the count the tier
     * column no longer answers on its own.
     */
    public function testTheAdministratorCountNamesBothMechanisms(): void
    {
        $senior = $this->position('Senior Ranger', 'Protection Service', [PermissionEnum::TeamManage]);
        $liaison = $this->position('Community Liaison Officer', 'Administration', [PermissionEnum::TeamManage]);
        $plain = $this->position('Ranger', 'Protection Service', [PermissionEnum::AreaView]);

        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Salum', 'Mwaipopo', TeamRoleEnum::Admin);
        $this->person('Grace', 'Ndosi')->setPosition($senior);
        $this->person('Peter', 'Sanga')->setPosition($liaison);
        $this->person('Zawadi', 'Naisenya')->setPosition($plain);
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertSame(2, $overview->administratorsByTier);
        self::assertSame(2, $overview->administratorsByPermission);
        self::assertSame(4, $overview->administrators());
    }

    /** A deactivated administrator administers nothing, so they are not counted. */
    public function testADeactivatedAdministratorIsNotCounted(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Salum', 'Mwaipopo', TeamRoleEnum::Admin)->deactivate();
        $this->em->flush();

        self::assertSame(1, $this->overview()->build()->administratorsByTier);
    }

    /**
     * DEACTIVATING SOMEBODY RESOLVES THEIR NEVER-SIGNED-IN NAG. Hawa Rajabu has
     * also never signed in and is deliberately absent from the pane: the
     * decision it asks for has already been taken about her. An attention pane
     * that kept chasing a switched-off account is a pane nobody trusts.
     */
    public function testTheAttentionPaneChasesOnlyActiveAccounts(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $joseph = $this->person('Joseph', 'Mrema')->setVerified(false);
        $this->person('Hawa', 'Rajabu')->setVerified(false)->deactivate();
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertSame([$joseph->getEmail()], array_map(
            static fn (User $u): ?string => $u->getEmail(),
            $overview->neverSignedIn,
        ));
    }

    public function testSomebodyWithNoPositionIsAnAttentionRow(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $frank = $this->person('Frank', 'Massawe');
        $this->person('Grace', 'Ndosi')->setPosition($this->position('Ranger', 'Protection Service'));
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertSame([$frank->getEmail()], array_map(
            static fn (User $u): ?string => $u->getEmail(),
            $overview->holdNothing,
        ));
    }

    /**
     * A TIER ABOVE THE MATRIX NEEDS NO POSITION, so a Super Admin with none is
     * not somebody who holds nothing — they hold everything. Listing them would
     * make the pane's most alarming row its least accurate one.
     */
    public function testASuperAdminWithNoPositionIsNotHoldingNothing(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Salum', 'Mwaipopo', TeamRoleEnum::Admin);
        $this->em->flush();

        self::assertSame([], $this->overview()->build()->holdNothing);
    }

    /**
     * THE STANDING RISK. Not a task — it has no done — so it carries no action,
     * and it does not go away by being read.
     */
    public function testTheSoleSuperAdminIsAStandingRisk(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertInstanceOf(User::class, $overview->soleSuperAdmin);
        self::assertSame($naomi->getEmail(), $overview->soleSuperAdmin->getEmail());
    }

    public function testASecondSuperAdminEndsTheRisk(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Asha', 'Mollel', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        self::assertNull($this->overview()->build()->soleSuperAdmin);
    }

    /**
     * THE PANE COLLAPSES TO NOTHING. An installation where everybody has signed
     * in, everybody holds a position and there are two Super Admins has no
     * decisions waiting — and a pane that stayed on screen to say so would be a
     * pane that spends the top of the page reporting that nothing is wrong.
     */
    public function testThePaneIsEmptyWhenNobodyNeedsADecision(): void
    {
        $ranger = $this->position('Ranger', 'Protection Service');
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Asha', 'Mollel', TeamRoleEnum::SuperAdmin);
        $this->person('Grace', 'Ndosi')->setPosition($ranger);
        $this->em->flush();

        $overview = $this->overview()->build();

        self::assertFalse($overview->needsAttention());
        self::assertSame(0, $overview->attentionCount());
    }

    public function testTheAttentionCountIsWhatTheHeadingPrints(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Joseph', 'Mrema')->setVerified(false);
        $this->person('Frank', 'Massawe');
        $this->em->flush();

        $overview = $this->overview()->build();

        // One never signed in, two hold nothing (Joseph and Frank), one standing
        // risk. Joseph is counted once per row he appears in, because the pane
        // is a list of decisions and he is two of them.
        self::assertTrue($overview->needsAttention());
        self::assertSame(
            \count($overview->neverSignedIn) + \count($overview->holdNothing) + 1,
            $overview->attentionCount(),
        );
    }
}
