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

namespace Uhifadhi\Team\Tests\Integration\Permission;

use Uhifadhi\Team\Model\Permission;
use Uhifadhi\Team\Service\PermissionCatalogue;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * ONE CATALOGUE, TWO SOURCES. The seven permissions this module owns, then
 * whatever the installed modules declared through the seam's tag — in that
 * order, because core always precedes and a module may never shadow it.
 */
final class PermissionCatalogueTest extends IntegrationTestCase
{
    private function catalogue(): PermissionCatalogue
    {
        return $this->service(PermissionCatalogue::class);
    }

    public function testTheCoreSevenComeFirstInEnumOrder(): void
    {
        $values = array_map(
            static fn (Permission $p): string => $p->value,
            $this->catalogue()->all(),
        );

        self::assertSame([
            'area.view', 'area.create', 'area.edit', 'area.delete',
            'ingestion.run', 'module.view', 'module.create',
        ], \array_slice($values, 0, 7));
    }

    public function testAModulesDeclarationJoinsTheCatalogue(): void
    {
        // The kernel registers one module bundle declaring "surveys.record".
        self::assertTrue($this->catalogue()->has('surveys.record'));
        self::assertCount(8, $this->catalogue()->all());
    }

    public function testAModuleDeclarationNeverCarriesACapabilityRole(): void
    {
        foreach ($this->catalogue()->all() as $permission) {
            if ('surveys.record' === $permission->value) {
                // Declaring is not granting: a module cannot mint an umbrella.
                self::assertNull($permission->capabilityRole);

                return;
            }
        }

        self::fail('The declared permission is not in the catalogue.');
    }

    public function testTheMatrixIsGroupedByUmbrella(): void
    {
        $grouped = $this->catalogue()->groupedByUmbrella();

        self::assertSame(['Areas', 'Ingestion', 'Modules', 'Surveys'], array_keys($grouped));
        self::assertCount(4, $grouped['Areas']);
    }

    public function testAnUnknownValueIsFilteredOutOnWrite(): void
    {
        // The write-side filter: only catalogue values survive, in catalogue order.
        self::assertSame(
            ['area.view', 'surveys.record'],
            $this->catalogue()->knownValues(['surveys.record', 'area.view', 'invented.power']),
        );
    }
}
