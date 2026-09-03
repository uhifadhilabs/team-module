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

use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Exception\UnknownPermissionException;
use Uhifadhi\Team\Service\PermissionCatalogue;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * THERE IS ONE WRITE PATH ONTO A POSITION, AND IT VALIDATES.
 *
 * There used to be two. `setPermissions(PermissionEnum[])` took the core enum
 * and was the obvious one to reach for — and it SILENTLY DISCARDED every
 * module-declared permission, because a module's value is not a case of an enum
 * this module owns. An administrator ticking a patrol module's row and saving
 * would watch it come back unticked, with nothing anywhere saying why. The
 * setter is removed rather than deprecated: a call site left compiling is the
 * bug still shipping.
 *
 * What replaces it is the value-string surface, and it takes the live catalogue
 * as a second required argument, so it cannot be called without one. The
 * accepted set is:
 *
 *     the live catalogue  ∪  the strings this position already holds
 *
 * The union is the whole design. The left half is what makes an unknown NEW
 * string fail loudly instead of being quietly dropped. The right half is the
 * prune-not-purge ruling in code: a module uninstalled last week left grants
 * behind in positions' JSON, those values are in nobody's catalogue any more,
 * and saving an unrelated change to the position must not silently strip them.
 * Editing a position is not a migration.
 */
final class PositionGrantTest extends IntegrationTestCase
{
    private function catalogue(): PermissionCatalogue
    {
        return $this->service(PermissionCatalogue::class);
    }

    /**
     * The enum-typed setter is gone. Asserted by name, because "we removed it on
     * purpose" is the fact worth keeping — and because a reintroduced one would
     * pass every other test in this suite.
     */
    public function testTheEnumTypedSetterIsGone(): void
    {
        // Through reflection rather than method_exists(): static analysis knows
        // the literal answer to the latter and narrows the assertion away.
        self::assertFalse(
            new \ReflectionClass(Position::class)->hasMethod('setPermissions'),
            'setPermissions() silently discarded every module-declared permission. It does not come back.',
        );
    }

    public function testACorePermissionRoundTrips(): void
    {
        $position = (new Position())->setName('Analyst');
        $position->setPermissionValues(
            [PermissionEnum::AreaView->value, PermissionEnum::TeamManage->value],
            $this->catalogue()->values(),
        );

        $this->em->persist($position);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Analyst']);
        self::assertInstanceOf(Position::class, $stored);

        self::assertSame(['area.view', 'team.manage'], $stored->getPermissionValues());
        self::assertTrue($stored->hasPermissionValue('team.manage'));
    }

    /**
     * THE ONE THAT THE OLD SETTER GOT WRONG. `surveys.record` belongs to a module
     * bundle, not to this module's enum, and it has to survive a save unchanged.
     */
    public function testAModuleDeclaredPermissionRoundTrips(): void
    {
        $position = (new Position())->setName('Surveyor');
        $position->setPermissionValues(['surveys.record'], $this->catalogue()->values());

        $this->em->persist($position);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Surveyor']);
        self::assertInstanceOf(Position::class, $stored);

        self::assertSame(['surveys.record'], $stored->getPermissionValues());
        self::assertTrue($stored->hasPermissionValue('surveys.record'));
    }

    /** An invented string is refused, loudly, and it names itself in the message. */
    public function testAnUnknownNewValueIsRefused(): void
    {
        $position = (new Position())->setName('Analyst');

        $this->expectException(UnknownPermissionException::class);
        $this->expectExceptionMessageMatches('/invented\.power/');

        $position->setPermissionValues(['area.view', 'invented.power'], $this->catalogue()->values());
    }

    /**
     * PRUNE, DO NOT PURGE. `vegetation.survey` was granted by a module that has
     * since been uninstalled: it is in this position's JSON and in nobody's
     * catalogue. Saving an unrelated change keeps it.
     */
    public function testAnOrphanedGrantSurvivesASaveThatDoesNotTouchIt(): void
    {
        $position = (new Position())->setName('Botanist');
        // How the grant got there: the module was installed at the time.
        $position->setPermissionValues(
            ['area.view', 'vegetation.survey'],
            [...$this->catalogue()->values(), 'vegetation.survey'],
        );

        $this->em->persist($position);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Botanist']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertContains('vegetation.survey', $stored->getPermissionValues());

        // The module is gone now — the catalogue no longer offers the value. An
        // administrator adds a core permission and saves.
        $stored->setPermissionValues(
            ['area.view', 'vegetation.survey', 'module.view'],
            $this->catalogue()->values(),
        );

        self::assertSame(
            ['area.view', 'vegetation.survey', 'module.view'],
            $stored->getPermissionValues(),
            'An orphan already on the position is accepted; only an unknown NEW string is refused.',
        );
    }

    /** An orphan can still be taken away — it is a grant, not a fixture. */
    public function testAnOrphanedGrantCanBeRevoked(): void
    {
        $position = (new Position())->setName('Botanist');
        $position->setPermissionValues(
            ['area.view', 'vegetation.survey'],
            [...$this->catalogue()->values(), 'vegetation.survey'],
        );

        $position->setPermissionValues(['area.view'], $this->catalogue()->values());

        self::assertSame(['area.view'], $position->getPermissionValues());
    }

    /** Ticking the same box twice is one grant. */
    public function testDuplicatesCollapse(): void
    {
        $position = (new Position())->setName('Analyst');
        $position->setPermissionValues(['area.view', 'area.view'], $this->catalogue()->values());

        self::assertSame(['area.view'], $position->getPermissionValues());
    }
}
