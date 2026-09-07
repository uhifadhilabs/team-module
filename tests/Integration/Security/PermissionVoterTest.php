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

namespace Uhifadhi\Team\Tests\Integration\Security;

use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Security\PermissionVoter;
use Uhifadhi\Team\Service\PermissionCatalogue;
use Uhifadhi\Team\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * WHO HOLDS WHAT. Super Admin and Admin hold every permission by tier; Staff —
 * which is now everybody else — hold exactly their position's, module-declared
 * values among them. Anything outside the catalogue is none of this voter's
 * business.
 *
 * `team.manage` is decided here like any other row, which is the whole of what
 * retiring the Manager tier bought: administering the team is a permission a
 * position grants and this voter answers for, not a column beside the matrix.
 */
final class PermissionVoterTest extends IntegrationTestCase
{
    private function voter(): PermissionVoter
    {
        return $this->service(PermissionVoter::class);
    }

    /** @param list<string> $attributes */
    private function vote(User $user, array $attributes): int
    {
        return $this->voter()->vote(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            null,
            $attributes,
        );
    }

    /** @param list<string> $values */
    private function positionGranting(string $name, array $values): Position
    {
        return (new Position())->setName($name)->setPermissionValues(
            $values,
            $this->service(PermissionCatalogue::class)->values(),
        );
    }

    private function staffWith(Position $position): User
    {
        return (new User())->setEmail('s@example.test')->setFirstName('S')->setLastName('T')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Staff)->setPosition($position);
    }

    public function testAdminAndAboveHoldEveryPermissionByTier(): void
    {
        $admin = (new User())->setEmail('a@example.test')->setFirstName('A')->setLastName('D')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Admin);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, ['area.delete']));
        // Including one no module of this deployment owns but a module declared.
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($admin, ['surveys.record']));
    }

    /**
     * WHAT THE MANAGER TIER BECAME. A Staff member whose position carries
     * `team.manage` administers the team, and holds nothing else they were not
     * given. This is the test the retired tier's own test turned into.
     */
    public function testAStaffMemberAdministersTheTeamWhenTheirPositionSaysSo(): void
    {
        $position = $this->positionGranting('Warden', [
            PermissionEnum::AreaView->value,
            PermissionEnum::TeamManage->value,
        ]);
        $grace = $this->staffWith($position);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($grace, ['team.manage']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($grace, ['area.view']));
        // And nothing beyond it: the tier grants her nothing at all.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($grace, ['area.delete']));
    }

    /** Take the row off the position and the authority goes with it, in one click. */
    public function testRevokingTeamManageEndsTheAuthority(): void
    {
        $position = $this->positionGranting('Warden', [PermissionEnum::TeamManage->value]);
        $grace = $this->staffWith($position);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($grace, ['team.manage']));

        $position->setPermissionValues([], $this->service(PermissionCatalogue::class)->values());

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($grace, ['team.manage']));
    }

    public function testStaffHoldExactlyTheirPositionIncludingAModulesDeclaration(): void
    {
        $position = $this->positionGranting('Recorder', ['area.view', 'surveys.record']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->staffWith($position), ['area.view']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->staffWith($position), ['surveys.record']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->staffWith($position), ['module.create']));
    }

    public function testStaffWithNoPositionHoldNothing(): void
    {
        $orphan = (new User())->setEmail('o@example.test')->setFirstName('O')->setLastName('R')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Staff);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($orphan, ['area.view']));
    }

    public function testItAbstainsOnAnythingOutsideTheCatalogue(): void
    {
        $admin = (new User())->setEmail('a2@example.test')->setFirstName('A')->setLastName('D')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Admin);

        // Roles are the role voters' business, and so is anything invented.
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($admin, ['ROLE_ADMIN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->vote($admin, ['invented.power']));
    }

    // ---- AREA-SCOPED: "may this person do X *here*?" -----------------------

    /**
     * AN AREA-LEVEL STAFF MEMBER holds their permission IN THEIR OWN AREA and
     * NOT in another. This is the thesis of area-scoped authority: the same
     * grant answers differently per area.
     */
    public function testAnAreaLevelStaffMemberIsGrantedInTheirAreaAndDeniedInAnother(): void
    {
        $serengeti = $this->area('Serengeti');
        $ngorongoro = $this->area('Ngorongoro');
        $ranger = $this->areaLevelStaffWith([PermissionEnum::AreaView->value], $serengeti);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($ranger, 'area.view', $serengeti));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voteOn($ranger, 'area.view', $ngorongoro));
    }

    /**
     * A NULL SUBJECT — the nav/flag question "may I ever…?" — grants for an
     * area-level holder (grant-if-any-authority), leaving the real per-area gate
     * for once an area is named.
     */
    public function testANullSubjectGrantsForAnAreaLevelHolder(): void
    {
        $serengeti = $this->area('Serengeti');
        $ranger = $this->areaLevelStaffWith([PermissionEnum::AreaView->value], $serengeti);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($ranger, 'area.view', null));
    }

    /** AN ORG-LEVEL STAFF MEMBER (department scope null) holds it in every area. */
    public function testAnOrgLevelStaffMemberIsGrantedInEveryArea(): void
    {
        $serengeti = $this->area('Serengeti');
        $ngorongoro = $this->area('Ngorongoro');

        $orgDept = (new Department())->setName('Ecology'); // no area → org-level
        $this->em->persist($orgDept);
        $position = (new Position())->setName('Analyst')->setDepartment($orgDept)
            ->setPermissionValues([PermissionEnum::AreaView->value], $this->service(PermissionCatalogue::class)->values());
        $this->em->persist($position);
        $this->em->flush();
        $analyst = $this->staffWith($position);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($analyst, 'area.view', $serengeti));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($analyst, 'area.view', $ngorongoro));
    }

    /**
     * A GLOBAL permission (only `area.create` among the core seven) skips the
     * area comparison — an area-level holder is granted regardless of target.
     */
    public function testAGlobalPermissionSkipsTheAreaComparison(): void
    {
        $serengeti = $this->area('Serengeti');
        $ngorongoro = $this->area('Ngorongoro');
        $planner = $this->areaLevelStaffWith([PermissionEnum::AreaCreate->value], $serengeti);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($planner, 'area.create', $ngorongoro));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($planner, 'area.create', null));
    }

    /** A MODULE-DECLARED permission is area-scoped by default — it compares too. */
    public function testAModuleDeclaredPermissionIsAreaScopedByDefault(): void
    {
        $serengeti = $this->area('Serengeti');
        $ngorongoro = $this->area('Ngorongoro');
        $recorder = $this->areaLevelStaffWith(['surveys.record'], $serengeti);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($recorder, 'surveys.record', $serengeti));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voteOn($recorder, 'surveys.record', $ngorongoro));
    }

    /** THE TIER BYPASS is total — an Admin holds it in every area, subject or not. */
    public function testTheTierBypassIgnoresTheTargetArea(): void
    {
        $serengeti = $this->area('Serengeti');
        $admin = (new User())->setEmail('a3@example.test')->setFirstName('A')->setLastName('D')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Admin);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voteOn($admin, 'area.view', $serengeti));
    }

    private function area(string $name): HostArea
    {
        $area = (new HostArea())->setName($name);
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    /** @param list<string> $values */
    private function areaLevelStaffWith(array $values, HostArea $area): User
    {
        $department = (new Department())->setName('Unit · '.$area->getName())->setArea($area);
        $this->em->persist($department);
        $position = (new Position())->setName('Field')->setDepartment($department)
            ->setPermissionValues($values, $this->service(PermissionCatalogue::class)->values());
        $this->em->persist($position);
        $this->em->flush();

        return $this->staffWith($position);
    }

    private function voteOn(User $user, string $attribute, mixed $subject): int
    {
        return $this->voter()->vote(
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            $subject,
            [$attribute],
        );
    }
}
