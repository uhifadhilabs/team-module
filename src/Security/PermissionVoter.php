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

        /*
         * ── PARKED SEAM: THE AREA-AWARE VOTER PLUGS IN HERE ──────────────────
         *
         * The area-aware department model is now in place: a department carries a
         * nullable area ({@see \Uhifadhi\Team\Entity\Department}), a Staff member's
         * authority-area is the derived chain
         * `user.getDepartment()?.getArea()` (org-level when null), and
         * docs/area-scoped-authority.md (module-contracts) rules how a target area
         * is compared against it. What is NOT built — deliberately, because it has
         * open verdicts still awaiting a ruling — is this voter becoming area-aware.
         *
         * When it is wired, the two returns above become the first steps of the
         * algorithm in §4 of that document, and the area comparison follows once
         * the permission is confirmed:
         *
         *   1. tier short-circuit (above) — unchanged, area never consulted.
         *   2. position carries the permission at all (above) — unchanged.
         *   3. is the permission even area-scoped? A GLOBAL permission grants here
         *      with no area check — this needs the scope axis on ModulePermission
         *      / PermissionEnum, which is part of the parked contract work.
         *   4. area comparison: authority = user.getDepartment()?.getArea();
         *      null authority (org-level) grants for any target; otherwise grant
         *      iff the target area (the voter SUBJECT) equals the authority area.
         *
         * The blocking forks live in §7 of the same document and MUST be ruled
         * before this lands: whether the subject is passed vs route-derived (§7.1),
         * null-subject semantics (§7.3), and above all what an area-scoped
         * `team.manage` holder may touch (§7.6). Until those are ruled, this voter
         * stays permission-only and the scope is an organizational fact that gates
         * nothing — see the Department class banner. Do not wire area logic here
         * without those verdicts.
         */
    }
}
