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
 *
 * EVERY ENTRY CARRIES ITS SENTENCE, whichever side declared it. The matrix
 * prints it under the name, and it does not ask where the row came from.
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
            'module.view', 'module.create', 'team.manage',
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

        self::assertSame(['Areas', 'Modules', 'Team', 'Surveys'], array_keys($grouped));
        self::assertCount(4, $grouped['Areas']);
    }

    /**
     * The sentence survives the fold, from both sides. A catalogue that carried
     * descriptions for its own seven and dropped the modules' would produce a
     * matrix where the rows an administrator understands least are the ones
     * with nothing written under them.
     */
    public function testEveryEntryCarriesItsSentenceWhicheverSideDeclaredIt(): void
    {
        foreach ($this->catalogue()->all() as $permission) {
            self::assertNotSame('', trim($permission->description), $permission->value.' has no sentence.');
        }

        foreach ($this->catalogue()->all() as $permission) {
            if ('surveys.record' === $permission->value) {
                self::assertSame(
                    'Enter a survey from the field and attach its counts to an area.',
                    $permission->description,
                    'A module\'s own words reach the matrix unchanged.',
                );

                return;
            }
        }

        self::fail('The declared permission is not in the catalogue.');
    }

    /**
     * EVERY ROW WEARS ITS CONTRIBUTOR, and that is the thing that makes this
     * matrix different from every other permission matrix: the list of
     * permissions is NOT FIXED. Seven are this module's and will always be
     * there; the rest arrive when a bundle is installed and leave when it is
     * removed. A row that could not say where it came from would leave an
     * administrator unable to tell a power the product has from a power a
     * bundle brought.
     */
    public function testACoreRowSaysItIsTheHostsAndADeclaredOneNamesItsModule(): void
    {
        foreach ($this->catalogue()->all() as $permission) {
            if ('area.view' === $permission->value) {
                self::assertNull($permission->source, 'A core permission has no module: it is the host\'s.');
                self::assertTrue($permission->isCore());
            }
            if ('surveys.record' === $permission->value) {
                self::assertSame('surveys', $permission->source);
                self::assertFalse($permission->isCore());
            }
        }
    }

    /**
     * AN INSTALLED MODULE THAT DECLARES NOTHING IS STILL DRAWN. Hiding it would
     * read as "that module is not installed", which is a different and wrong
     * fact — and the matrix's whole job is to be honest about what the
     * installation contains.
     */
    public function testAnInstalledModuleDeclaringNothingIsNameable(): void
    {
        // The test kernel registers one module that declares a permission and
        // one that declares none.
        self::assertContains('roster', $this->catalogue()->silentModules());
        self::assertNotContains('surveys', $this->catalogue()->silentModules());
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
