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

namespace Uhifadhi\Team\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Team\Enum\TeamRoleEnum;

/**
 * THE TIERS ARE THREE, AND THE LIST IS WRITTEN OUT HERE.
 *
 * Super Admin and Admin stand ABOVE the matrix — they are the bootstrap and the
 * recovery escape hatch, and they hold every permission by tier. Everybody else
 * is Staff and holds exactly what their position grants.
 *
 * THERE IS NO MANAGER, and its removal is the ruling that changed the most. The
 * tier named a job rather than a system level, and it produced the model's most
 * misread rule: a Manager could administer the team and yet hold no capability
 * at all. Administering the team is now an ordinary catalogue permission,
 * `team.manage`, granted through a position — so a "manager" is something an
 * administrator composes, and it is visible in the matrix, countable and
 * revocable, none of which was true of a tier.
 */
#[CoversClass(TeamRoleEnum::class)]
final class TeamRoleEnumTest extends TestCase
{
    public function testThereAreExactlyThreeTiers(): void
    {
        self::assertSame(
            ['super_admin', 'admin', 'staff'],
            array_map(static fn (TeamRoleEnum $r): string => $r->value, TeamRoleEnum::cases()),
        );
    }

    /**
     * Named rather than merely absent, because "we deleted it on purpose" is the
     * fact worth keeping. A stored `manager` string no longer resolves to a case,
     * which is what makes an installation's migration off the tier a decision it
     * has to take rather than one it can drift past.
     */
    public function testManagerIsNotATier(): void
    {
        // Asserted over the VALUES rather than with tryFrom(): a literal that is
        // not a case is a fact static analysis already knows, so that assertion
        // would be a tautology rather than a guard.
        self::assertNotContains(
            'manager',
            array_map(static fn (TeamRoleEnum $r): string => $r->value, TeamRoleEnum::cases()),
        );
    }

    public function testOnlySuperAdminMayImpersonate(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canSwitch());
        self::assertFalse(TeamRoleEnum::Admin->canSwitch());
        self::assertFalse(TeamRoleEnum::Staff->canSwitch());
    }

    /**
     * The two system levels hold everything; Staff hold nothing by tier. This is
     * the whole of what a tier decides now — administering the team left this
     * axis entirely and became `team.manage`.
     */
    public function testTheTwoSystemLevelsHoldEveryPermissionByTier(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canManageContent());
        self::assertTrue(TeamRoleEnum::Admin->canManageContent());
        self::assertFalse(TeamRoleEnum::Staff->canManageContent());
    }

    /**
     * A TIER NO LONGER ANSWERS "MAY THIS PERSON ADMINISTER THE TEAM". The method
     * that used to is gone rather than deprecated: a call site left compiling
     * would be a screen still gated on the retired axis, and the whole point of
     * the ruling is that the question is now asked of the permission catalogue.
     */
    public function testTheTierNoLongerAnswersWhoMayAdministerTheTeam(): void
    {
        // Through reflection rather than method_exists(): static analysis knows
        // the literal answer to the latter and narrows the assertion away.
        self::assertFalse(
            new \ReflectionEnum(TeamRoleEnum::class)->hasMethod('canManageTeam'),
            'Team administration is is_granted("team.manage"), not a tier question.',
        );
    }

    public function testEveryTierHasALabel(): void
    {
        self::assertSame('Super Admin', TeamRoleEnum::SuperAdmin->label());
        self::assertSame('Admin', TeamRoleEnum::Admin->label());
        self::assertSame('Staff', TeamRoleEnum::Staff->label());
    }

    /**
     * The member record draws three cards rather than a dropdown, because the
     * words do not explain themselves. The sentence on each card is the tier's
     * own, so the screen has nothing to invent.
     */
    public function testEveryTierExplainsItself(): void
    {
        foreach (TeamRoleEnum::cases() as $tier) {
            self::assertNotSame('', trim($tier->description()), $tier->value.' has no sentence.');
            self::assertNotSame('', trim($tier->grants()), $tier->value.' does not say what it grants.');
        }

        self::assertStringContainsString('every permission', TeamRoleEnum::SuperAdmin->grants());
        self::assertStringContainsString('position', TeamRoleEnum::Staff->grants());
    }
}
