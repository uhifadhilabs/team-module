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
 * The tier capability gradient: only Super Admin impersonates; Super Admin / Admin / Manager
 * both administer the team (create/assign positions) and hold every content permission by tier;
 * Staff do neither and are limited to their assigned position.
 */
#[CoversClass(TeamRoleEnum::class)]
final class TeamRoleEnumTest extends TestCase
{
    public function testOnlySuperAdminMayImpersonate(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canSwitch());
        self::assertFalse(TeamRoleEnum::Admin->canSwitch());
        self::assertFalse(TeamRoleEnum::Manager->canSwitch());
        self::assertFalse(TeamRoleEnum::Staff->canSwitch());
    }

    public function testManagerAndAboveMayAdministerTheTeam(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canManageTeam());
        self::assertTrue(TeamRoleEnum::Admin->canManageTeam());
        self::assertTrue(TeamRoleEnum::Manager->canManageTeam());
        self::assertFalse(TeamRoleEnum::Staff->canManageTeam());
    }

    public function testOnlyAdminAndAboveHoldEveryPermissionByTier(): void
    {
        self::assertTrue(TeamRoleEnum::SuperAdmin->canManageContent());
        self::assertTrue(TeamRoleEnum::Admin->canManageContent());
        self::assertFalse(TeamRoleEnum::Manager->canManageContent());
        self::assertFalse(TeamRoleEnum::Staff->canManageContent());
    }

    public function testEveryTierHasALabel(): void
    {
        self::assertSame('Super Admin', TeamRoleEnum::SuperAdmin->label());
        self::assertSame('Admin', TeamRoleEnum::Admin->label());
        self::assertSame('Manager', TeamRoleEnum::Manager->label());
        self::assertSame('Staff', TeamRoleEnum::Staff->label());
    }
}
