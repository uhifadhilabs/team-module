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

namespace Uhifadhi\Team\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Service\PermissionCatalogue;

/**
 * Decides a granular permission (e.g. `is_granted('area.create')`) from the user's tier and
 * position. Super Admin / Admin / Manager hold every permission by tier; a Staff user holds
 * exactly the permissions of their assigned {@see \Uhifadhi\Team\Entity\Position}.
 *
 * The catalogue — the app's own PermissionEnum plus what installed modules declare — is the
 * single source of what counts as a permission: core and module-declared values are decided
 * identically. Attributes outside the catalogue are none of this voter's business — it
 * abstains so the role voters can decide them, which also means a permission of an
 * UNINSTALLED module is simply no longer decidable here.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionCatalogue $catalogue,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->catalogue->has($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Super Admin / Admin / Manager hold every permission by tier.
        if ($user->getTeamRole()->canManageContent()) {
            return true;
        }

        // Staff: exactly their position's permissions, module-declared included.
        $position = $user->getPosition();

        return null !== $position && $position->hasPermissionValue($attribute);
    }
}
