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
 *
 * THE SEVENTH IS `team.manage`, and it is what the retired Manager tier became.
 * Administering the team used to be answered by the tier column; it is now an
 * ordinary row in the matrix under its own umbrella, which is what makes "who
 * administers this installation" a question with a countable answer.
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
            'module.view',
            'module.create',
            'team.manage',
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

    /**
     * The Team umbrella is the new one, and it carries exactly one row. An
     * umbrella with one permission is not a mistake: the umbrella is the coarse
     * region an installation's access_control can name (`ROLE_TEAM` keeps
     * `/team` shut), and the granular row is what the voter decides.
     */
    public function testTeamManageIsTheSeventhUnderItsOwnUmbrella(): void
    {
        self::assertSame('Team', PermissionEnum::TeamManage->umbrella());
        self::assertSame('Manage', PermissionEnum::TeamManage->action());
        self::assertSame('ROLE_TEAM', PermissionEnum::TeamManage->capabilityRole());
        self::assertSame('Team · Manage', PermissionEnum::TeamManage->label());
    }

    public function testTheThreeUmbrellasAreTheOnlyOnes(): void
    {
        $umbrellas = array_values(array_unique(
            array_map(static fn (PermissionEnum $p): string => $p->umbrella(), PermissionEnum::all()),
        ));

        self::assertSame(['Areas', 'Modules', 'Team'], $umbrellas);
    }

    /**
     * EVERY PERMISSION CARRIES ITS SENTENCE, the core seven exactly as the
     * declared ones do. The matrix prints it under the name, so a core row that
     * had none would be the one row on the page an administrator cannot read —
     * and the rule this release states is that there are no such rows.
     */
    public function testEveryCorePermissionExplainsItself(): void
    {
        foreach (PermissionEnum::all() as $permission) {
            self::assertNotSame(
                '',
                trim($permission->description()),
                $permission->value.' is a power somebody can be granted with no sentence saying what it does.',
            );
        }
    }

    /**
     * The sentence is about the holder and not about the mechanism. Spot-checked
     * on the one permission whose meaning is easiest to state wrongly.
     */
    public function testTheSentenceSaysWhatHoldingItLetsSomebodyDo(): void
    {
        self::assertStringContainsString('team', strtolower(PermissionEnum::TeamManage->description()));
    }
}
