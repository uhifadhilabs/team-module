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
 * THE CATALOGUE IS SEVEN, AND THE LIST IS WRITTEN OUT HERE.
 *
 * Not counted — spelled. A permission is a power somebody holds, so adding one
 * is a decision, and a test that only counted would let the eighth arrive by
 * accident and the wrong seventh be swapped in silently.
 */
#[CoversClass(PermissionEnum::class)]
final class PermissionEnumTest extends TestCase
{
    public function testTheCatalogueIsExactlyTheSevenNamedPermissions(): void
    {
        self::assertSame([
            'area.view',
            'area.create',
            'area.edit',
            'area.delete',
            'ingestion.run',
            'module.view',
            'module.create',
        ], array_map(static fn (PermissionEnum $p): string => $p->value, PermissionEnum::all()));
    }

    public function testEachPermissionCarriesItsUmbrellaActionAndCapabilityRole(): void
    {
        self::assertSame('Areas', PermissionEnum::AreaView->umbrella());
        self::assertSame('View', PermissionEnum::AreaView->action());
        self::assertSame('ROLE_AREAS', PermissionEnum::AreaView->capabilityRole());

        self::assertSame('ROLE_INGESTION', PermissionEnum::IngestionRun->capabilityRole());
        self::assertSame('ROLE_MODULES', PermissionEnum::ModuleCreate->capabilityRole());

        // The label is the two words the matrix prints, joined the one way.
        self::assertSame('Modules · Add', PermissionEnum::ModuleCreate->label());
    }

    public function testTheThreeUmbrellasAreTheOnlyOnes(): void
    {
        $umbrellas = array_values(array_unique(
            array_map(static fn (PermissionEnum $p): string => $p->umbrella(), PermissionEnum::all()),
        ));

        self::assertSame(['Areas', 'Ingestion', 'Modules'], $umbrellas);
    }
}
