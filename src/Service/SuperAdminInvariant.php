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

namespace Uhifadhi\Team\Service;

use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Exception\LastSuperAdminException;
use Uhifadhi\Team\Repository\UserRepository;

/**
 * AN INSTALLATION ALWAYS KEEPS AT LEAST ONE ACTIVE SUPER ADMIN.
 *
 * Demote or deactivate the last one and there is nobody who can administer the
 * team, nobody who can promote a replacement, and no way back in short of a
 * console session on the server. So the change is REFUSED — in code, here,
 * before anything is written.
 *
 * IT IS A REFUSAL AND NOT A DISABLED CONTROL. A greyed-out button says "not
 * now" and leaves the reader to guess; the screens call this, catch the
 * exception and print its reason where the control would have been. The
 * difference matters because the reason is also the instruction: grant Super
 * Admin to a successor and both refusals lift in the same moment.
 *
 * THERE IS NO OWNER FLAG AND NO BREAK-GLASS ACCOUNT. Both were considered and
 * both are worse. An owner flag makes one row in the table structurally
 * different and gives an installation a person it cannot replace when they
 * leave; a break-glass account is a credential nobody rotates. Ownership here is
 * simply transferable, and the only rule is that it may not reach zero.
 *
 * DEACTIVATED DOES NOT COUNT. The invariant is about who can actually sign in
 * and fix things, so a second Super Admin who left in March is not a second
 * Super Admin.
 *
 * WHY A SERVICE AND NOT THE ENTITY: an entity cannot count its own siblings,
 * and an invariant that only holds when the caller remembers to ask is not an
 * invariant. Every write path in this module goes through here.
 */
final readonly class SuperAdminInvariant
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * Whether this account is the only ACTIVE Super Admin left. The screens ask
     * before they draw, so the refusal appears in place of the control rather
     * than after somebody has pressed it.
     */
    public function isLastActiveSuperAdmin(User $user): bool
    {
        if (TeamRoleEnum::SuperAdmin !== $user->getTeamRole() || !$user->isActive()) {
            return false;
        }

        return 1 >= $this->users->countActiveSuperAdmins();
    }

    /**
     * @throws LastSuperAdminException if this would leave nobody able to administer the team
     */
    public function assertMayChangeTier(User $user, TeamRoleEnum $to): void
    {
        // Not a demotion: leaving the last Super Admin exactly where they are
        // is the one tier change that is always safe.
        if (TeamRoleEnum::SuperAdmin === $to) {
            return;
        }

        if ($this->isLastActiveSuperAdmin($user)) {
            throw LastSuperAdminException::cannotDemote($user->getFullName());
        }
    }

    /**
     * @throws LastSuperAdminException if this would leave nobody able to administer the team
     */
    public function assertMayDeactivate(User $user): void
    {
        if ($this->isLastActiveSuperAdmin($user)) {
            throw LastSuperAdminException::cannotDeactivate($user->getFullName());
        }
    }
}
