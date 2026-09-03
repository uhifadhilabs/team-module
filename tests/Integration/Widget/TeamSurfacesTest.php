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

namespace Uhifadhi\Team\Tests\Integration\Widget;

use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Team\Widget\PositionWidgets;
use Uhifadhi\Team\Widget\TeamWidgets;
use Uhifadhi\Widget\Model\WidgetGroup;
use Uhifadhi\Widget\Model\WidgetPreset;
use Uhifadhi\Widget\Registry\WidgetSurfaceRegistry;

/**
 * BOTH TEAM SCREENS ARE WIDGET SURFACES, and this module hard-requires
 * uhifadhi/widget-module to make that true.
 *
 * The standing workspace rule is that a drawn direction ships as a built-in
 * preset carrying its trade-off line verbatim — adopted, copied and mixed, never
 * picked and never thrown away. No option set is a ballot. So all six roster
 * directions and all six matrix directions are here, each under the words the
 * design used for it, and the shipped composition is the seventh card the strip
 * leads with.
 *
 * A CATALOGUE IS CODE, NOT INPUT: a preset naming a widget the surface does not
 * ship, or a width it does not offer, refuses to boot. That is the framework
 * agreeing, and it is why constructing both catalogues is itself worth a test.
 */
final class TeamSurfacesTest extends IntegrationTestCase
{
    public function testBothSurfacesAreInTheRegistry(): void
    {
        $registry = static::getContainer()->get('test_public.'.WidgetSurfaceRegistry::class);
        self::assertInstanceOf(WidgetSurfaceRegistry::class, $registry);

        self::assertTrue($registry->has(TeamWidgets::SURFACE));
        self::assertTrue($registry->has(PositionWidgets::SURFACE));
    }

    /** The surface string is what every stored row is keyed by, so it is pinned. */
    public function testTheSurfaceKeysAreStable(): void
    {
        self::assertSame('team', TeamWidgets::SURFACE);
        self::assertSame('team_positions', PositionWidgets::SURFACE);
    }

    public function testTheRosterShipsNineWidgetsInTwoGroups(): void
    {
        $catalog = new TeamWidgets()->catalog();

        self::assertSame([
            'kpis', 'attention',
            'roster_a', 'roster_b', 'roster_f', 'roster_c', 'roster_d', 'roster_e',
            'tiers',
        ], $catalog->ids());

        self::assertSame(
            ['roster', 'context'],
            array_map(static fn (WidgetGroup $g): string => $g->id, $catalog->groups()),
        );
    }

    /**
     * ALL SIX DIRECTIONS SHIP AS PRESETS — including f, "The org chart". The
     * standing rule is that a drawn direction is adoptable rather than
     * discarded, and f is the one a first draft would have dropped as a
     * near-duplicate of b. It is not: b bands by tier and f bands by department,
     * and shipping both is how the "tiers or departments?" question was answered
     * — it was refused.
     */
    public function testAllSixRosterDirectionsAreAdoptable(): void
    {
        $catalog = new TeamWidgets()->catalog();

        self::assertSame(
            ['roster_a', 'roster_b', 'roster_f', 'roster_c', 'roster_d', 'roster_e'],
            array_map(static fn (WidgetPreset $p): string => $p->id, $catalog->presets()),
        );
    }

    /**
     * THE SHIPPED COMPOSITION IS NOT ONE OF THE SIX. It is the roster table with
     * the attention pane above it and the tier explainer below — the arrangement
     * a fresh installation's first visit wants, because that visit is the one
     * where something needs doing.
     */
    public function testTheShippedCompositionLeadsTheStripUnderItsOwnName(): void
    {
        $catalog = new TeamWidgets()->catalog();
        $builtins = $catalog->builtins();

        self::assertNotSame([], $builtins);
        $first = $builtins[0];

        self::assertSame('The team roster', $first->label);
        self::assertSame(['kpis', 'attention', 'roster_a', 'tiers'], $first->ids());
    }

    /**
     * KPI CARDS ALWAYS SIT AT THE TOP — the standing workspace rule, with no
     * exceptions. A layout's key order IS its render order, so this is a
     * property of every preset on the surface and not of one template.
     */
    public function testEveryRosterPresetLeadsWithTheKpis(): void
    {
        $catalog = new TeamWidgets()->catalog();

        foreach ($catalog->builtins() as $preset) {
            self::assertSame('kpis', $preset->ids()[0], $preset->id.' does not lead with the counts.');
        }
    }

    public function testTheMatrixSurfaceShipsThirteenWidgetsInThreeGroups(): void
    {
        $catalog = new PositionWidgets()->catalog();

        self::assertSame([
            'kpis', 'catalogue', 'risks',
            'matrix_a', 'matrix_b', 'matrix_c', 'matrix_d', 'matrix_e',
            'dept_a', 'dept_b', 'dept_c', 'dept_d', 'dept_e',
        ], $catalog->ids());

        self::assertSame(
            ['context', 'matrix', 'deptfirst'],
            array_map(static fn (WidgetGroup $g): string => $g->id, $catalog->groups()),
        );
    }

    /**
     * THE FIVE MATRIX DIRECTIONS ARE FIVE RENDERINGS OF ONE ARRAY —
     * PermissionCatalogue::groupedByUmbrella() — and they are ALTERNATIVES:
     * every preset that shows the matrix at all shows exactly one of them.
     * Five renderings of one thing must not be able to disagree about it, and
     * stacking two would be the page disagreeing with itself.
     *
     * Direction F is the exception that proves it: it is not a sixth rendering
     * of the matrix but a different family — five department-first layouts —
     * and it shows none of the five.
     */
    public function testEveryMatrixPresetTurnsOnAtMostOneRendering(): void
    {
        $catalog = new PositionWidgets()->catalog();
        $renderings = ['matrix_a', 'matrix_b', 'matrix_c', 'matrix_d', 'matrix_e'];

        foreach ($catalog->builtins() as $preset) {
            $on = array_values(array_intersect($renderings, $preset->ids()));
            self::assertLessThanOrEqual(1, \count($on), $preset->id.' stacks '.\count($on).' renderings of one array.');
        }

        self::assertSame(['dept_a', 'dept_b', 'dept_c', 'dept_d', 'dept_e'], $catalog->preset('f')?->ids());
    }

    /**
     * B IS THE ONE SELECTED — RULED — and the argument is the fresh-installation
     * state rather than the populated one: on day one there are no positions,
     * and B is the only direction whose empty state is a single control that
     * makes the first one. The grid opens on nothing at all.
     */
    public function testTheMatrixShipsOnDirectionB(): void
    {
        $catalog = new PositionWidgets()->catalog();

        self::assertSame('b', $catalog->defaultPresetId());
        self::assertSame(
            ['a', 'b', 'c', 'd', 'e', 'f'],
            array_map(static fn (WidgetPreset $p): string => $p->id, $catalog->presets()),
        );
    }

    /**
     * KPI CARDS AT THE TOP holds here too, wherever they appear at all. Direction
     * F carries none — it is five stacked department layouts and has no counts
     * of its own — and a rule about where the counts go has nothing to say about
     * a layout that has none.
     */
    public function testWhereTheMatrixSurfaceShowsCountsTheyLead(): void
    {
        foreach (new PositionWidgets()->catalog()->builtins() as $preset) {
            if ($preset->shows('kpis')) {
                self::assertSame('kpis', $preset->ids()[0], $preset->id.' shows the counts but not first.');
            }
        }
    }

    /**
     * A DIRECTION'S TRADE-OFF LINE IS ITS DESCRIPTION, and it says what the
     * direction buys and what it costs, in that order. What the design said
     * about a direction has to be what the product says about it.
     */
    public function testEveryPresetCarriesItsTradeOffLine(): void
    {
        foreach ([new TeamWidgets()->catalog(), new PositionWidgets()->catalog()] as $catalog) {
            foreach ($catalog->presets() as $preset) {
                self::assertNotSame('', trim($preset->description), $preset->id.' has no trade-off line.');
                self::assertGreaterThan(60, mb_strlen($preset->description), $preset->id.'\'s line is too short to name a cost.');
            }
        }
    }

    /** Every widget the catalogues declare has the partial that draws it. */
    public function testEveryWidgetHasATemplate(): void
    {
        foreach ([
            [new TeamWidgets()->catalog(), __DIR__.'/../../../templates/team/_w_%s.html.twig'],
            [new PositionWidgets()->catalog(), __DIR__.'/../../../templates/positions/_w_%s.html.twig'],
        ] as [$catalog, $pattern]) {
            foreach ($catalog->ids() as $id) {
                self::assertFileExists(\sprintf($pattern, $id));
            }
        }
    }
}
