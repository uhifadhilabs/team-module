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

namespace Uhifadhi\Team\Model;

use Uhifadhi\Team\Entity\User;

/**
 * WHAT THE TEAM PAGE KNOWS BEFORE IT DRAWS A ROW — the five counts on the strip
 * and the decisions waiting in the attention pane, in one object so that the
 * page asks the database once rather than once per widget.
 *
 * EVERY NUMBER IS A QUERY, never a stored counter. A person who is deactivated
 * stops being counted as active because the column says so, not because
 * something remembered to decrement — which is the only version of these
 * numbers that stays true after a migration nobody wrote a hook for.
 */
final readonly class RosterOverview
{
    /**
     * @param int        $people                     everybody, deactivated included: the roster is where
     *                                               "left" and "never existed" are told apart
     * @param int        $positionsHeld              how many positions have at least one holder — a position
     *                                               nobody holds is a real thing, created before its first person
     * @param list<User> $neverSignedIn              ACTIVE accounts with isVerified false. Deactivating
     *                                               somebody resolves their never-signed-in nag, so a
     *                                               switched-off account is deliberately absent
     * @param list<User> $holdNothing                active Staff with no position — the model's zero
     * @param User|null  $soleSuperAdmin             set only when there is exactly one ACTIVE Super Admin
     * @param int        $administratorsByTier       Super Admin + Admin, active
     * @param int        $administratorsByPermission active Staff whose position carries team.manage
     */
    public function __construct(
        public int $people,
        public int $active,
        public int $deactivated,
        public int $positions,
        public int $departments,
        public int $positionsHeld,
        public array $neverSignedIn,
        public array $holdNothing,
        public ?User $soleSuperAdmin,
        public int $administratorsByTier,
        public int $administratorsByPermission,
        public bool $isFirstRun,
    ) {
    }

    /**
     * ONE NUMBER, TWO MECHANISMS — and the sub-line beside it has to name both,
     * or the number is unreadable. Some administrators hold the power by tier
     * and some because a position they hold carries `team.manage`; since the
     * Manager tier went, the tier column cannot answer this on its own.
     */
    public function administrators(): int
    {
        return $this->administratorsByTier + $this->administratorsByPermission;
    }

    /**
     * THE PANE COLLAPSES TO NOTHING when this is false. A pane that stayed on
     * screen to report that nothing is wrong would spend the top of the page
     * saying so, every visit, forever.
     */
    public function needsAttention(): bool
    {
        return [] !== $this->neverSignedIn
            || [] !== $this->holdNothing
            || null !== $this->soleSuperAdmin;
    }

    /**
     * What the pane's heading prints. A person can be two decisions at once —
     * never signed in AND holding nothing — and is counted twice, because the
     * pane is a list of decisions rather than a list of people.
     */
    public function attentionCount(): int
    {
        return \count($this->neverSignedIn)
            + \count($this->holdNothing)
            + (null !== $this->soleSuperAdmin ? 1 : 0);
    }
}
