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
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Model\RosterOverview;
use Uhifadhi\Team\Repository\DepartmentRepository;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;

/**
 * Builds the {@see RosterOverview} the team page's KPI strip and attention pane
 * are drawn from.
 *
 * ONE PLACE, ASKED ONCE. Every widget on that surface wants some of the same
 * facts — the counts, who has not arrived, who holds nothing — and a page that
 * let each widget query for itself would issue the same COUNT five times and
 * could still show five numbers that disagreed, because a preset can turn any
 * of them off independently.
 *
 * A SERVICE RATHER THAN A REPOSITORY METHOD: the interesting part is not any
 * one query but the rules about which rows count, and those rules are the
 * page's rather than the table's. Deactivating somebody resolves their
 * never-signed-in nag; a Super Admin with no position holds everything rather
 * than nothing; a deactivated administrator administers nothing. None of those
 * is a fact about `team_user`.
 */
final readonly class TeamOverview
{
    public function __construct(
        private UserRepository $users,
        private PositionRepository $positions,
        private DepartmentRepository $departments,
    ) {
    }

    public function build(): RosterOverview
    {
        $people = $this->users->countAll();
        $active = $this->users->countActive();

        $soleSuperAdmin = 1 === $this->users->countActiveSuperAdmins()
            ? $this->users->findFirstActiveSuperAdmin()
            : null;

        return new RosterOverview(
            people: $people,
            active: $active,
            deactivated: $people - $active,
            positions: $this->positions->countAll(),
            departments: \count($this->departments->findAllOrdered()),
            positionsHeld: $this->positions->countHeld(),
            neverSignedIn: $this->users->findActiveNeverSignedIn(),
            holdNothing: $this->users->findActiveWithoutPosition(),
            soleSuperAdmin: $soleSuperAdmin,
            administratorsByTier: $this->users->countActiveInTiers(TeamRoleEnum::SuperAdmin, TeamRoleEnum::Admin),
            administratorsByPermission: $this->countAdministratorsByPermission(),
            // ONE ACCOUNT IS A FRESH INSTALLATION. Not "no accounts" — a page
            // nobody can reach cannot be drawn — and not "no positions", because
            // an installation whose second person arrived before its first
            // position is past its first run whatever the matrix says.
            isFirstRun: 1 >= $people,
        );
    }

    /**
     * The Staff who administer this team because a position they hold carries
     * `team.manage` — what the retired Manager tier became.
     *
     * COUNTED IN PHP over the positions that grant it, rather than in SQL over
     * the JSON column. The grants are a JSON list of strings and every engine
     * spells a containment test differently; a bundle does not get to assume
     * which database an installation runs. There are as many positions as an
     * organisation has jobs, so the list this walks is tens of rows and the
     * query it replaces would have been the one thing in this module that only
     * worked on Postgres.
     */
    private function countAdministratorsByPermission(): int
    {
        $granting = array_values(array_filter(
            $this->positions->findAllOrdered(),
            static fn ($position): bool => $position->hasPermissionValue(PermissionEnum::TeamManage->value),
        ));

        if ([] === $granting) {
            return 0;
        }

        return $this->users->countActiveHoldingAnyPosition($granting);
    }

    /** Whether this person administers the team, by either mechanism — what a roster row marks. */
    public function administers(User $user): bool
    {
        if ($user->getTeamRole()->canManageContent()) {
            return true;
        }

        return $user->getPosition()?->hasPermissionValue(PermissionEnum::TeamManage->value) ?? false;
    }
}
