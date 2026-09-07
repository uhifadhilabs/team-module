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

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Uhifadhi\ModuleContracts\Entity\AreaInterface;
use Uhifadhi\Team\Entity\User;

/**
 * WHAT THE SIGNED-IN ADMINISTRATOR'S REACH IS — the read side of area-scoped
 * `team.manage` (DECISIONS §5.6, docs/area-scoped-authority.md §7.6).
 *
 * The voter answers "may this person do X here?" for a single permission. This
 * answers the coarser structural question the department writes need: is the
 * administrator UNBOUNDED (a tier, or an org-level team.manage holder — able to
 * mint org departments, change scope, touch any area), or confined to ONE area
 * (an area-X admin, who may manage only area-level departments in X)?
 *
 * The ruling it enforces: an area-X `team.manage` holder MAY create, rename and
 * deactivate area-level departments in X; may NOT create org-level departments,
 * change any department's scope, or touch org-level or other areas' departments.
 * Any of the forbidden acts widens power past the admin's own boundary — minting
 * an org department, promoting to org, reaching another area — which is
 * escalation. This service is where "past their boundary" is computed; the
 * controller is where it is refused.
 *
 * IT READS THE SAME DERIVED AUTHORITY-AREA THE VOTER DOES —
 * `actor.position.department.scope`, org-level (null) meaning every area — so the
 * two can never disagree about where an administrator's authority reaches.
 */
final readonly class AreaAuthority
{
    public function __construct(
        private TokenStorageInterface $tokens,
    ) {
    }

    public function actor(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * UNBOUNDED — reaches every area. A tier (Super Admin / Admin, which bypass
     * area-scoping) or an org-level team.manage holder (department scope null).
     * These are the only administrators who may mint an org-level department,
     * change a scope, or manage a department outside a single area.
     */
    public function isUnbounded(): bool
    {
        $actor = $this->actor();
        if (null === $actor) {
            return false;
        }

        if ($actor->getTeamRole()->canManageContent()) {
            return true;
        }

        // An org-level position (department scope null) is org-wide authority.
        return null === $actor->getDepartment()?->getArea();
    }

    /**
     * The one area a bounded administrator is confined to, or null when they are
     * unbounded (or not signed in).
     */
    public function authorityArea(): ?AreaInterface
    {
        return $this->isUnbounded() ? null : $this->actor()?->getDepartment()?->getArea();
    }

    /**
     * Whether the administrator's authority reaches the given area. Unbounded
     * reaches everywhere; a bounded one reaches only its own area, and never the
     * org-level bucket (a null area), because managing an org-level department is
     * an unbounded act.
     */
    public function covers(?AreaInterface $area): bool
    {
        if ($this->isUnbounded()) {
            return true;
        }

        $authority = $this->authorityArea();
        if (null === $authority || null === $area) {
            return false;
        }

        $authorityUuid = $authority->getUuidString();
        $areaUuid = $area->getUuidString();
        if (null !== $authorityUuid && null !== $areaUuid) {
            return $authorityUuid === $areaUuid;
        }

        $authorityId = $authority->getId();

        return null !== $authorityId && $authorityId === $area->getId();
    }
}
