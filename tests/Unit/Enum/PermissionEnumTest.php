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
use Uhifadhi\Team\Enum\PermissionEnum;

/**
 * THE CATALOGUE IS SIX, AND THE LIST IS WRITTEN OUT HERE.
 *
 * Not counted — spelled. A permission is a power somebody holds, so adding one
 * is a decision, and a test that only counted would let the seventh arrive by
 * accident and the wrong sixth be swapped in silently.
 */
#[CoversClass(PermissionEnum::class)]
final class PermissionEnumTest extends TestCase
{
    public function testTheCatalogueIsExactlyTheSixNamedPermissions(): void
    {
        self::assertSame([
            'area.view',
            'area.create',
            'area.edit',
            'area.delete',
            'module.view',
            'module.create',
        ], array_map(static fn (PermissionEnum $p): string => $p->value, PermissionEnum::all()));
    }

    /**
     * THERE IS NO INGESTION, and this test is the guard on that. The catalogue
     * used to carry `ingestion.run` under a `ROLE_INGESTION` umbrella, ported
     * from an application that had an ingestion capability; this platform does
     * not, and a permission guarding nothing is a power an admin can assign
     * over code that does not exist. Named here rather than merely absent above,
     * because "we deleted it on purpose" is the fact worth keeping.
     */
    public function testIngestionIsNotInTheCatalogue(): void
    {
        // Asserted over the VALUES rather than with tryFrom(): a literal that
        // is not a case is a fact static analysis already knows, so that
        // assertion would be a tautology rather than a guard.
        self::assertNotContains(
            'ingestion.run',
            array_map(static fn (PermissionEnum $p): string => $p->value, PermissionEnum::all()),
        );

        foreach (PermissionEnum::all() as $permission) {
            self::assertNotSame('Ingestion', $permission->umbrella());
            self::assertNotSame('ROLE_INGESTION', $permission->capabilityRole());
        }
    }

    public function testEachPermissionCarriesItsUmbrellaActionAndCapabilityRole(): void
    {
        self::assertSame('Areas', PermissionEnum::AreaView->umbrella());
        self::assertSame('View', PermissionEnum::AreaView->action());
        self::assertSame('ROLE_AREAS', PermissionEnum::AreaView->capabilityRole());

        self::assertSame('ROLE_MODULES', PermissionEnum::ModuleCreate->capabilityRole());

        // The label is the two words the matrix prints, joined the one way.
        self::assertSame('Modules · Add', PermissionEnum::ModuleCreate->label());
    }

    public function testTheTwoUmbrellasAreTheOnlyOnes(): void
    {
        $umbrellas = array_values(array_unique(
            array_map(static fn (PermissionEnum $p): string => $p->umbrella(), PermissionEnum::all()),
        ));

        self::assertSame(['Areas', 'Modules'], $umbrellas);
    }
}
