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
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Security\PermissionVoter;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * WHO HOLDS WHAT. Admin and above hold every permission by tier; everyone else
 * — Manager included — holds exactly their position's, module-declared values
 * among them. Anything outside the catalogue is none of this voter's business.
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

    public function testAManagerHoldsOnlyTheirPosition(): void
    {
        $position = (new Position())->setName('Warden')->setPermissions([PermissionEnum::AreaView]);
        $manager = (new User())->setEmail('m@example.test')->setFirstName('M')->setLastName('G')
            ->setPassword('x')->setTeamRole(TeamRoleEnum::Manager)->setPosition($position);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($manager, ['area.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($manager, ['area.delete']));
    }

    public function testStaffHoldExactlyTheirPositionIncludingAModulesDeclaration(): void
    {
        $position = (new Position())->setName('Recorder')
            ->setPermissionValues(['area.view', 'surveys.record']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->staffWith($position), ['area.view']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->staffWith($position), ['surveys.record']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->staffWith($position), ['ingestion.run']));
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
}
