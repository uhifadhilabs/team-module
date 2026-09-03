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

namespace Uhifadhi\Team\Enum;

/**
 * A user's tier within the single authority that runs uhifadhi. There is no
 * "Owner" — nobody owns a national park — and there are THREE tiers, not four.
 *
 * TWO OF THEM STAND ABOVE THE MATRIX. Super Admin is what an installation is
 * bootstrapped with and the only tier that may sign in as somebody else; Admin
 * is the recovery escape hatch for when a position has been mis-composed and
 * somebody has to be able to fix it. Both hold every permission by tier
 * ({@see canManageContent()}), which is the whole of what a tier decides.
 *
 * EVERYBODY ELSE IS STAFF, including the people who run the place. A Staff
 * member holds exactly what their {@see \Uhifadhi\Team\Entity\Position} grants
 * and nothing else — and a Staff member with no position holds nothing at all,
 * which is a real state of the model rather than an unfinished one.
 *
 * THERE WAS A FOURTH TIER, MANAGER, AND IT IS GONE. It named a job rather than
 * a system level, and it created the model's single most misread rule: a
 * Manager could administer the team and yet hold no capability at all, which
 * made the permission matrix decorative for half the people in it. Administering
 * the team is now an ordinary catalogue permission — `team.manage`, the seventh
 * core case ({@see PermissionEnum::TeamManage}) — granted through a position.
 *
 * So "a manager" is now something an administrator COMPOSES: a position
 * carrying `team.manage` plus whatever else that role needs. The authority is
 * visible in the matrix, countable across the roster, and revocable in one
 * click. None of those three things was true of a tier, and all three are why
 * the tier went.
 *
 * The cost is honest and worth stating: "who administers this installation" is
 * no longer answerable from the tier column alone, because a Staff member
 * holding `team.manage` can. Every screen that asks gates on
 * `is_granted('team.manage')`, never on a tier.
 *
 * Permission queries live here to keep the user entity thin.
 */
enum TeamRoleEnum: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Staff => 'Staff',
        };
    }

    /**
     * The sentence the member record's tier card carries. Three cards rather
     * than a dropdown, because the words do not explain themselves — and the
     * explanation belongs to the tier rather than to the template, so every
     * screen that draws a tier draws the same sentence.
     */
    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Above the matrix. Holds every permission by tier, and may sign in as another person to reproduce what they are seeing. The account an installation is bootstrapped with.',
            self::Admin => 'Above the matrix. Holds every permission by tier — the recovery escape hatch for when a position has been mis-composed and somebody has to be able to fix it.',
            self::Staff => 'Everybody else, including the people who run the place. Holds exactly what their position grants — and if that position carries team.manage, that includes administering this team.',
        };
    }

    /** The short answer under the card: what this tier hands somebody on its own. */
    public function grants(): string
    {
        return match ($this) {
            self::SuperAdmin, self::Admin => 'every permission, by tier',
            self::Staff => 'exactly their position',
        };
    }

    /** Super Admin may impersonate any user (switch_user / ROLE_ALLOWED_TO_SWITCH). */
    public function canSwitch(): bool
    {
        return self::SuperAdmin === $this;
    }

    /**
     * Whether the tier stands ABOVE the matrix — Super Admin and Admin hold
     * every permission by tier, and the voter answers for them before it ever
     * looks at a position. Staff are position-driven and answer false.
     *
     * There is deliberately no companion method asking whether the tier may
     * administer the team. That question left this axis with the Manager tier
     * and is now `is_granted('team.manage')`, which these two tiers pass through
     * this method and a Staff member passes through their position.
     */
    public function canManageContent(): bool
    {
        return \in_array($this, [self::SuperAdmin, self::Admin], true);
    }
}
