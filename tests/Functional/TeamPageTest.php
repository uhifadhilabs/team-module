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

namespace Uhifadhi\Team\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;

/**
 * THE TEAM PAGE, RENDERED — the design's key structures asserted as markup
 * rather than as intentions.
 *
 * What is checked here is what the design argues about and a template can get
 * wrong: the attention pane collapsing to NOTHING when nobody needs a decision,
 * the three-line position cell, the tier pills as links carrying whole-roster
 * counts, the pager drawn in the state it is actually in — and the gate, which
 * is a PERMISSION and not a tier.
 */
final class TeamPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    private function person(string $first, string $last, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = (new User())
            ->setEmail(strtolower($first[0].'.'.$last).'@example.test')
            ->setFirstName($first)->setLastName($last)->setPassword('x')
            ->setTeamRole($tier)->setVerified(true);
        $this->em->persist($user);

        return $user;
    }

    /** @param list<PermissionEnum> $permissions */
    private function position(string $name, Department $department, array $permissions = []): Position
    {
        $position = (new Position())->setName($name)->setDepartment($department);
        $position->setPermissionValues(
            array_map(static fn (PermissionEnum $p): string => $p->value, $permissions),
            array_map(static fn (PermissionEnum $p): string => $p->value, PermissionEnum::all()),
        );
        $this->em->persist($position);

        return $position;
    }

    private function department(string $name): Department
    {
        $department = (new Department())->setName($name);
        $this->em->persist($department);

        return $department;
    }

    /** A settled installation: everybody arrived, everybody holds something, two Super Admins. */
    private function settled(): User
    {
        $protection = $this->department('Protection Service');
        $senior = $this->position('Senior Ranger', $protection, [PermissionEnum::TeamManage, PermissionEnum::AreaView]);
        $ranger = $this->position('Ranger', $protection, [PermissionEnum::AreaView]);

        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Asha', 'Mollel', TeamRoleEnum::SuperAdmin);
        $this->person('Grace', 'Ndosi')->setPosition($senior)->setRangerCode('R-104');
        $this->person('Zawadi', 'Naisenya')->setPosition($ranger);
        $this->em->flush();

        return $naomi;
    }

    /**
     * THE GATE IS A PERMISSION, NOT A TIER. A Staff member whose position
     * carries team.manage administers the team — that is the whole of what
     * retiring the Manager tier bought.
     */
    public function testAStaffMemberHoldingTeamManageReachesThePage(): void
    {
        $protection = $this->department('Protection Service');
        $senior = $this->position('Senior Ranger', $protection, [PermissionEnum::TeamManage]);
        $grace = $this->person('Grace', 'Ndosi')->setPosition($senior);
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $this->client->loginUser($grace);
        $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
    }

    /** And a Staff member whose position does not carry it is refused. */
    public function testAStaffMemberWithoutItIsRefused(): void
    {
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $this->client->loginUser($frank);
        $this->client->request('GET', '/team');

        self::assertResponseStatusCodeSame(403);
    }

    /** The two tiers above the matrix pass the same check, by tier. */
    public function testASuperAdminReachesThePage(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame('Team', $crawler->filter('h1.pg')->text());
    }

    /**
     * THE ATTENTION PANE COLLAPSES TO NOTHING. Not an empty plate saying
     * everything is fine — absent. A pane that stayed on screen to report that
     * nothing is wrong would spend the top of the page saying so, every visit.
     */
    public function testTheAttentionPaneIsAbsentWhenNobodyNeedsADecision(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(0, $crawler->filter('.tm-att'));
        self::assertStringNotContainsString('Needs a decision', $crawler->html());
    }

    public function testTheAttentionPaneAppearsWhenSomebodyHasNeverSignedIn(): void
    {
        $this->settled();
        $joseph = $this->person('Joseph', 'Mrema')->setVerified(false);
        $naomi = $this->em->getRepository(User::class)->findOneBy(['email' => 'n.kileo@example.test']);
        self::assertInstanceOf(User::class, $naomi);
        $joseph->markInvitedBy($naomi);
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(1, $crawler->filter('.tm-att'));
        self::assertStringContainsString('Joseph Mrema has never signed in', $crawler->html());
        self::assertStringContainsString('Naomi Kileo invited them', $crawler->html());
    }

    /**
     * THE STANDING RISK IS NOT A TASK: it carries no action, because resolving
     * it means promoting somebody and that is a decision rather than a button.
     */
    public function testTheSoleSuperAdminRowCarriesNoAction(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Grace', 'Ndosi')->setPosition(
            $this->position('Ranger', $this->department('Protection Service'), [PermissionEnum::AreaView]),
        );
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        $standing = $crawler->filter('.tm-attrow.standing');
        self::assertCount(1, $standing);
        self::assertCount(0, $standing->filter('.ad'), 'A standing risk has no done, so it has no button.');
    }

    /**
     * THE THREE-LINE POSITION CELL: the qualified name, what it grants, and the
     * administrator mark. Never a bare name — two departments may own the word.
     */
    public function testThePositionCellIsQualifiedAndSaysWhatItGrants(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        $cell = $crawler->filter('.tm-pos.qual')->first();
        self::assertSame('Protection Service', $cell->filter('.q')->text());
        self::assertSame('Senior Ranger', $cell->filter('.n')->text());

        self::assertStringContainsString('2 granted', $crawler->html());
        // Scoped to the table: the tier explainer below it also spells
        // team.manage, and rightly — that widget's whole job is to say what the
        // permission means.
        self::assertCount(
            1,
            $crawler->filter('[data-w="roster_a"] .tm-mgr'),
            'The one position carrying team.manage is marked, in the roster.',
        );
    }

    /**
     * A SUPER ADMIN'S POSITION CELL IS NOT A COUNT. The voter grants by tier
     * before it ever looks at a position, so a number there would name the
     * wrong cause.
     */
    public function testATierAboveTheMatrixReadsEverythingByTier(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertStringContainsString('everything, by tier', $crawler->html());
    }

    /**
     * THE TIER PILLS ARE LINKS carrying whole-roster counts. Links because the
     * page must work with no JavaScript; whole-roster because a chip reading
     * "Admin 0" while you search for a name is a chip lying about the
     * installation.
     */
    public function testTheTierPillsAreParamLinksWithWholeRosterCounts(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team?q=grace');

        $chips = $crawler->filter('.tm-tools a.fchip');
        self::assertCount(4, $chips, 'All, then one per tier.');
        self::assertStringContainsString('tier=super_admin', $chips->eq(1)->attr('href') ?? '');
        // Two Super Admins exist; the search for "grace" must not change that.
        self::assertSame('2', $chips->eq(1)->filter('.n')->text());
    }

    /** The tool row is a GET form, so the filter state is the URL. */
    public function testTheToolRowIsAGetFormAndTheSearchNarrowsTheTable(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team?q=ndosi');

        self::assertSame('get', strtolower($crawler->filter('form.tm-tools')->attr('method') ?? ''));
        self::assertCount(1, $crawler->filter('table.tbl tbody tr'));
        self::assertStringContainsString('showing <b>1</b> of 1', $crawler->html());
    }

    /**
     * THE POSITION FILTER IS GROUPED BY DEPARTMENT, and it has to be: two
     * positions may be called "Analyst", and a flat list would offer the same
     * word twice with no way to tell which is which.
     */
    public function testThePositionFilterIsGroupedByDepartment(): void
    {
        $naomi = $this->settled();
        $ecology = $this->department('Ecology');
        $this->position('Analyst', $ecology);
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        $groups = $crawler->filter('select[name="position"] optgroup');
        self::assertGreaterThanOrEqual(2, $groups->count());
        self::assertContains('Ecology', $groups->each(static fn ($g): string => $g->attr('label') ?? ''));
        // And "— no position —" is a value, because holding nothing is a state.
        self::assertStringContainsString('no position', $crawler->filter('select[name="position"]')->text());
    }

    /**
     * THE PAGER IS DRAWN IN THE STATE IT IS ACTUALLY IN. Four people is one
     * page, so there are no arrows: a disabled ‹ › on page 1 of 1 is chrome
     * pretending there is somewhere to go.
     */
    public function testASinglePageDrawsNoArrows(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        $foot = $crawler->filter('.rdf-foot');
        self::assertStringContainsString('25 per page', $foot->text());
        self::assertStringContainsString('page 1 of 1', $foot->text());
        self::assertCount(0, $foot->filter('a'));
    }

    public function testASecondPageGetsItsArrow(): void
    {
        $naomi = $this->settled();
        for ($i = 1; $i <= 30; ++$i) {
            $this->person('Person', \sprintf('%02d', $i));
        }
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(25, $crawler->filter('table.tbl tbody tr'));
        self::assertGreaterThan(0, $crawler->filter('.rdf-page a')->count());
    }

    /**
     * A DEACTIVATED ACCOUNT STAYS IN THE LIST, marked, with the action that
     * undoes it. "This ranger left in March" and "this ranger never existed"
     * are different facts and the roster is where the difference is read.
     */
    public function testADeactivatedAccountIsStillListedAndOffersToComeBack(): void
    {
        $naomi = $this->settled();
        $this->person('Hawa', 'Rajabu')->setVerified(false)->deactivate();
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertStringContainsString('Hawa Rajabu', $crawler->html());
        self::assertCount(1, $crawler->filter('tr.tm-off'));
        // Two pills, not one merged state: the axes never merge.
        self::assertStringContainsString('Deactivated', $crawler->filter('tr.tm-off')->text());
        self::assertStringContainsString('Never signed in', $crawler->filter('tr.tm-off')->text());
        // And the honest invitation line for an account nobody invited.
        self::assertStringContainsString('created directly', $crawler->filter('tr.tm-off')->text());
        self::assertStringContainsString('Reactivate', $crawler->filter('tr.tm-off')->text());
    }

    /** The KPI strip leads the page — standing rule, no exceptions. */
    public function testTheCountsAreTheFirstThingOnThePage(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        $first = $crawler->filter('.w-grid > div')->first();
        self::assertSame('kpis', $first->attr('data-w'));
    }

    /**
     * ONE NUMBER, TWO MECHANISMS — and the sub-line names both, because the
     * tier column can no longer answer this on its own.
     */
    public function testTheAdministratorCountNamesBothMechanisms(): void
    {
        $naomi = $this->settled();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        $kpi = $crawler->filter('[data-w="kpis"]')->text();
        self::assertStringContainsString('Can administer', $kpi);
        self::assertStringContainsString('2 by tier', $kpi);
        self::assertStringContainsString('1 by', $kpi);
    }

    /**
     * A FRESH INSTALLATION GETS NO STRIP OF ZEROS. One account is the whole
     * roster, and the page says so in a sentence with something to do next.
     */
    public function testAFreshInstallationIsASentenceRatherThanAStripOfZeros(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $this->client->loginUser($naomi);
        $crawler = $this->client->request('GET', '/team');

        self::assertCount(0, $crawler->filter('.dp-kstrip'));
        self::assertStringContainsString('You are the only person here', $crawler->html());
        // And no tool row: a table with one row and a filter bar filtering
        // nothing is a table pretending to be a list.
        self::assertCount(0, $crawler->filter('form.tm-tools'));
    }
}
