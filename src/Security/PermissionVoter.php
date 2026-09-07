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
use Uhifadhi\ModuleContracts\Entity\AreaInterface;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Service\PermissionCatalogue;

/**
 * Decides a granular permission (e.g. `is_granted('patrols.record', $area)`) from the user's
 * tier, position, and — for an area-scoped permission — the TARGET AREA it is asked about.
 * Super Admin / Admin hold every permission in every area by tier; a Staff user holds exactly
 * the permissions of their assigned {@see \Uhifadhi\Team\Entity\Position}, confined to their
 * authority-area.
 *
 * The catalogue — the app's own PermissionEnum plus what installed modules declare — is the
 * single source of what counts as a permission: core and module-declared values are decided
 * identically. Attributes outside the catalogue are none of this voter's business — it
 * abstains so the role voters can decide them, which also means a permission of an
 * UNINSTALLED module is simply no longer decidable here.
 *
 * AREA-SCOPED (docs/area-scoped-authority.md §4, module-contracts). A permission now answers
 * "may this person do X *here*?" A Staff member's authority-area is a DERIVED fact — the scope
 * of their position's department (`user.getDepartment()?.getArea()`), org-level when null —
 * and nothing else stores it. The voter compares the passed target area against it:
 *
 *   1. TIER SHORT-CIRCUIT — Super Admin / Admin bypass area-scoping entirely; area never
 *      consulted. Area-scoping only ever narrows a Staff member.
 *   2. DOES THE POSITION CARRY THIS PERMISSION AT ALL? No position, or a position without the
 *      value → deny. Unchanged from before.
 *   3. IS THE PERMISSION EVEN AREA-SCOPED? A GLOBAL permission (only `area.create` among the
 *      core seven) is granted here with no area check — it has no area to compare against.
 *   4. AREA COMPARISON. Org-level authority (scope null) grants for any target — "all areas"
 *      is the absence of a boundary. A NULL subject means "no area in context" (a nav
 *      question, a "may I ever…?" flag): granted, because the actor has authority in some
 *      area, with the real per-area gate applying once an area is known. Otherwise the target
 *      area must equal the authority area.
 *
 * The subject is PASSED EXPLICITLY (DECISIONS §5.1), which is what makes the voter
 * unit-testable and usable off-route (commands, the API); {@see AreaValueResolver} is the
 * convenience that turns a `{uuid}` route param into the Area for controllers that want it.
 *
 * @extends Voter<string, ?AreaInterface>
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

        // 1. Tier escape hatch — Super Admin / Admin hold everything, everywhere.
        if ($user->getTeamRole()->canManageContent()) {
            return true;
        }

        // 2. Does the position carry this permission at all?
        $position = $user->getPosition();
        if (null === $position || !$position->hasPermissionValue($attribute)) {
            return false;
        }

        // 3. A global permission has no area to compare against — grant on the
        //    permission alone.
        if (!$this->catalogue->isAreaScoped($attribute)) {
            return true;
        }

        // 4. Area comparison. Authority-area is the department's scope, derived,
        //    org-level (null) meaning every area.
        $authority = $user->getDepartment()?->getArea();
        if (null === $authority) {
            return true; // org-level → authority in every area
        }

        // A null (or non-area) subject is "no area in context": grant, because
        // this area-level actor has authority in their one area, and the genuine
        // per-area gate applies once an area is named downstream.
        if (!$subject instanceof AreaInterface) {
            return true;
        }

        return $this->sameArea($authority, $subject);
    }

    /**
     * The two areas are the same one — compared on the public address (uuid)
     * first, then the persistence id, so it holds whether or not the two are the
     * same managed instance.
     */
    private function sameArea(AreaInterface $authority, AreaInterface $target): bool
    {
        $authorityUuid = $authority->getUuidString();
        $targetUuid = $target->getUuidString();
        if (null !== $authorityUuid && null !== $targetUuid) {
            return $authorityUuid === $targetUuid;
        }

        $authorityId = $authority->getId();
        $targetId = $target->getId();

        return null !== $authorityId && $authorityId === $targetId;
    }
}
