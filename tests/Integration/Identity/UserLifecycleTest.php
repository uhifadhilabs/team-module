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

namespace Uhifadhi\Team\Tests\Integration\Identity;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Exception\LastSuperAdminException;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Security\ActiveUserChecker;
use Uhifadhi\Team\Service\SuperAdminInvariant;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * AN ACCOUNT IS NEVER HARD-DELETED, and this suite is where that stays true.
 *
 * "This ranger left in March" and "this ranger never existed" are different
 * facts, and a DELETE makes them the same operation. Every patrol somebody
 * recorded keeps its author, so leaving is DEACTIVATION: `isActive` goes false,
 * `disabledAt` records when, the row stays in every list under an inactive
 * filter, and coming back is one click. There is no destructive control
 * anywhere in this module.
 *
 * A separate `deletedAt` exists and nothing writes it. It is reserved so a
 * future recycle bin — removed records, listed, with an explicit purge — is not
 * foreclosed by a schema that has nowhere to put the marker.
 *
 * AND AN INSTALLATION ALWAYS KEEPS ONE. At least one ACTIVE Super Admin, or
 * there is nobody who can administer the team and no way back in. The invariant
 * is a refusal in the code rather than a greyed-out button: the last active
 * Super Admin cannot be demoted and cannot be deactivated, and the refusal
 * carries its reason. There is deliberately no owner flag and no break-glass
 * account — ownership is transferable, and the only rule is that it may not
 * reach zero.
 */
final class UserLifecycleTest extends IntegrationTestCase
{
    private function user(string $email, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('A')
            ->setLastName('B')
            ->setPassword('x')
            ->setTeamRole($tier);

        $this->em->persist($user);

        return $user;
    }

    private function invariant(): SuperAdminInvariant
    {
        return $this->service(SuperAdminInvariant::class);
    }

    public function testAnAccountIsActiveWhenItIsMade(): void
    {
        $user = $this->user('new@example.test');
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneByEmail('new@example.test');
        self::assertInstanceOf(User::class, $stored);

        self::assertTrue($stored->isActive());
        self::assertNull($stored->getDisabledAt());
        self::assertNull($stored->getDeletedAt(), 'Nothing writes the recycle-bin marker yet.');
        self::assertNull($stored->getInvitedAt());
        self::assertNull($stored->getInvitedBy());
        unset($user);
    }

    public function testDeactivatingStampsTheMomentAndReactivatingClearsIt(): void
    {
        $user = $this->user('leaver@example.test');
        $this->em->flush();

        $user->deactivate();
        self::assertFalse($user->isActive());
        self::assertNotNull($user->getDisabledAt());

        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneByEmail('leaver@example.test');
        self::assertInstanceOf(User::class, $stored);
        self::assertFalse($stored->isActive());
        self::assertNotNull($stored->getDisabledAt());

        $stored->reactivate();
        self::assertTrue($stored->isActive());
        self::assertNull($stored->getDisabledAt(), 'Coming back leaves no stale timestamp behind.');
    }

    /**
     * A deactivated account is STILL LISTED. The roster is where the difference
     * between "left" and "never existed" is read, so the row does not vanish.
     */
    public function testADeactivatedAccountStaysInTheRepository(): void
    {
        $user = $this->user('gone@example.test');
        $this->em->flush();
        $user->deactivate();
        $this->em->flush();
        $this->em->clear();

        self::assertInstanceOf(
            User::class,
            $this->service(UserRepository::class)->findOneByEmail('gone@example.test'),
        );
    }

    /** The firewall refuses them, and it says why rather than pretending the password is wrong. */
    public function testADeactivatedAccountCannotSignIn(): void
    {
        $user = $this->user('gone@example.test');
        $user->deactivate();

        $checker = new ActiveUserChecker();

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $checker->checkPreAuth($user);
    }

    public function testAnActiveAccountPassesTheChecker(): void
    {
        $user = $this->user('here@example.test');

        // Void, so the assertion is that it returns at all.
        new ActiveUserChecker()->checkPreAuth($user);
        self::assertTrue($user->isActive());
    }

    /** The invitation is two nullable columns, and the null is meaningful. */
    public function testAnInvitationRecordsWhoAndWhen(): void
    {
        $inviter = $this->user('boss@example.test', TeamRoleEnum::SuperAdmin);
        $invited = $this->user('invited@example.test');
        $invited->markInvitedBy($inviter);

        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneByEmail('invited@example.test');
        self::assertInstanceOf(User::class, $stored);

        self::assertNotNull($stored->getInvitedAt());
        self::assertInstanceOf(User::class, $stored->getInvitedBy());
        self::assertSame('boss@example.test', $stored->getInvitedBy()->getEmail());
    }

    /**
     * AN ACCOUNT CREATED WITH A PASSWORD WAS NEVER INVITED BY ANYBODY, and its
     * null says so. Two kinds of never-signed-in account want two different
     * things done about them, so the roster has to be able to tell them apart.
     */
    public function testAnAccountCreatedDirectlyHasNoInvitation(): void
    {
        $user = $this->user('direct@example.test');
        $this->em->flush();

        self::assertNull($user->getInvitedAt());
        self::assertNull($user->getInvitedBy());
    }

    public function testTheLastActiveSuperAdminIsRecognised(): void
    {
        $only = $this->user('only@example.test', TeamRoleEnum::SuperAdmin);
        $this->user('staff@example.test');
        $this->em->flush();

        self::assertTrue($this->invariant()->isLastActiveSuperAdmin($only));
    }

    public function testDemotingTheLastActiveSuperAdminIsRefusedWithAReason(): void
    {
        $only = $this->user('only@example.test', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $this->expectException(LastSuperAdminException::class);
        $this->expectExceptionMessageMatches('/only|last/i');

        $this->invariant()->assertMayChangeTier($only, TeamRoleEnum::Admin);
    }

    public function testDeactivatingTheLastActiveSuperAdminIsRefusedWithAReason(): void
    {
        $only = $this->user('only@example.test', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $this->expectException(LastSuperAdminException::class);
        $this->invariant()->assertMayDeactivate($only);
    }

    /**
     * TRANSFER, THEN LEAVE — and this is the order the code allows. Grant Super
     * Admin to a successor and both refusals lift in the same moment.
     */
    public function testGrantingItToSomebodyElseUnlocksBoth(): void
    {
        $leaving = $this->user('leaving@example.test', TeamRoleEnum::SuperAdmin);
        $successor = $this->user('successor@example.test', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        self::assertFalse($this->invariant()->isLastActiveSuperAdmin($leaving));

        // Neither refusal fires. Asserted by calling them: these methods are
        // void, so "it did not throw" is the whole of what there is to see.
        $this->invariant()->assertMayChangeTier($leaving, TeamRoleEnum::Staff);
        $this->invariant()->assertMayDeactivate($leaving);
        self::assertTrue($successor->isActive());
    }

    /**
     * A DEACTIVATED SUPER ADMIN DOES NOT COUNT. The invariant is about who can
     * actually sign in and fix things, so a second Super Admin who has left is
     * not a second Super Admin.
     */
    public function testADeactivatedSuperAdminDoesNotSatisfyTheInvariant(): void
    {
        $active = $this->user('active@example.test', TeamRoleEnum::SuperAdmin);
        $left = $this->user('left@example.test', TeamRoleEnum::SuperAdmin);
        $left->deactivate();
        $this->em->flush();

        self::assertTrue($this->invariant()->isLastActiveSuperAdmin($active));

        $this->expectException(LastSuperAdminException::class);
        $this->invariant()->assertMayDeactivate($active);
    }

    /** Nobody else is caught by it — an ordinary account deactivates freely. */
    public function testAnOrdinaryAccountIsNotCaughtByTheInvariant(): void
    {
        $this->user('boss@example.test', TeamRoleEnum::SuperAdmin);
        $staff = $this->user('staff@example.test');
        $this->em->flush();

        self::assertFalse($this->invariant()->isLastActiveSuperAdmin($staff));

        $this->invariant()->assertMayDeactivate($staff);
        $this->invariant()->assertMayChangeTier($staff, TeamRoleEnum::Admin);
        self::assertSame(TeamRoleEnum::Staff, $staff->getTeamRole());
    }

    /** Promoting the last Super Admin to Super Admin is not a demotion. */
    public function testKeepingTheLastSuperAdminAtTheirTierIsAllowed(): void
    {
        $only = $this->user('only@example.test', TeamRoleEnum::SuperAdmin);
        $this->em->flush();

        $this->invariant()->assertMayChangeTier($only, TeamRoleEnum::SuperAdmin);
        self::assertTrue($this->invariant()->isLastActiveSuperAdmin($only));
    }
}
