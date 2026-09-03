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

namespace Uhifadhi\Team\Widget;

use Uhifadhi\Widget\Model\Widget;
use Uhifadhi\Widget\Model\WidgetCatalog;
use Uhifadhi\Widget\Model\WidgetGroup;
use Uhifadhi\Widget\Model\WidgetPreset;
use Uhifadhi\Widget\Registry\WidgetSurfaceInterface;

/**
 * THE /team/positions SURFACE — the permission matrix, and the heart of this
 * module. A transcription of the design's own surface declaration
 * (team/positions/positions.widgets.js), which is the specification.
 *
 * THE FIVE MATRIX WIDGETS ARE FIVE RENDERINGS OF ONE ARRAY, and that array is
 * {@see \Uhifadhi\Team\Service\PermissionCatalogue::groupedByUmbrella()}. It is
 * the only input any of them has. They are ALTERNATIVES rather than additions —
 * every preset turns on exactly one — because five renderings of one thing must
 * not be able to disagree about it, and stacking two would be the page
 * disagreeing with itself.
 *
 * WHAT EVERY DIRECTION HAS TO MAKE VISIBLE, because it is the thing that makes
 * this matrix different from every other permission matrix: THE LIST OF
 * PERMISSIONS IS NOT FIXED. Seven are this module's and will always be there;
 * the rest arrive when a bundle is installed and leave when it is removed. So
 * every row wears its contributor, and three honest states are drawn rather
 * than described — an installed module that declares nothing, a permission
 * whose module is not installed here, and an orphaned grant still held by
 * positions and provided by nothing.
 *
 * B SHIPS SELECTED, and the argument is the fresh-installation state rather
 * than the populated one: on day one there are no positions, and B is the only
 * direction whose empty state is a single control that makes the first one. The
 * grid opens on nothing at all.
 *
 * F IS NOT A SIXTH RENDERING. It is a different family — five department-first
 * layouts, drawn before the department was ruled into the model and turned out
 * to be right. Where A–E carry the department as context on every row, these
 * carry it as the shape of the page. It is the one preset that shows no matrix
 * rendering and no counts, which is why the KPI-at-the-top rule has nothing to
 * say about it.
 */
final class PositionWidgets implements WidgetSurfaceInterface
{
    /**
     * What a stored preference row is keyed by.
     *
     * PREFIXED WITH THE MODULE, unlike the design's bare "positions". Two
     * surfaces may both be called "positions" in an installation that installs
     * a module nobody here has heard of, and a stored row keyed by a word that
     * generic is a row two dashboards would fight over.
     */
    public const string SURFACE = 'team_positions';

    public function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup(
                    'context',
                    'Context',
                    'What the installation’s authority actually consists of, before anybody edits it. These are additions, so every preset can carry them.',
                ),
                new WidgetGroup(
                    'matrix',
                    'The matrix',
                    'Five renderings of PermissionCatalogue::groupedByUmbrella(). They are ALTERNATIVES — keep exactly one on; each preset turns on exactly one.',
                ),
                new WidgetGroup(
                    'deptfirst',
                    'Department-first — the department as the organising axis',
                    'Five directions that make DEPARTMENT the structure rather than a column: banded tables, department chips, a card per department, fully qualified names, and a department rail. A position belongs to a department and its name is unique only inside it, so “Ecology / Analyst” is how a position is named.',
                ),
            ],
            [
                new Widget('kpis', 'The catalogue, counted', 'context', 12, [12], true,
                    'Four numbers about the installation’s own authority — positions, placement, grantable permissions, and the Super Admin there is only one of.'),
                new Widget('catalogue', 'Where every permission comes from', 'context', 12, [12], true,
                    'The seam drawn as a table: which umbrella is the host’s, which arrived with a bundle, and what removing that bundle would do to the grants already written.'),
                new Widget('risks', 'Two standing risks', 'context', 12, [12], false,
                    'The sole Super Admin with no recovery path, and the orphaned grants no installed module provides. Both are properties of shipped code.'),
                new Widget('matrix_a', 'Matrix — the grid', 'matrix', 12, [12], false,
                    'Positions across, permissions down, a box at every intersection. One row answers “who may delete an area”; every new position is a new column.'),
                new Widget('matrix_b', 'Matrix — one position at a time', 'matrix', 12, [12], true,
                    'A rail of positions beside one position’s checklist. The safest editor: always exactly one position, and the header says how many people it reaches.'),
                new Widget('matrix_c', 'Matrix — permission first', 'matrix', 12, [12], false,
                    'The permission is what you open and you tick who holds it — the shape of the question an audit asks, and the worst shape for building a new position.'),
                new Widget('matrix_d', 'Matrix — umbrella packs', 'matrix', 12, [12], false,
                    'One collapsible pack per umbrella, each naming its contributor and what uninstalling it would cost. Makes the catalogue’s growth the visible thing.'),
                new Widget('matrix_e', 'Matrix — the effective ledger', 'matrix', 12, [12], false,
                    'One person, every permission, and the reason on every row: by tier, by position, or not held. The only direction that shows the tier bypass and the zero.'),
                new Widget('dept_a', 'Department-first A', 'deptfirst', 12, [12], false,
                    'One table banded by department, with the create row inside its own band.'),
                new Widget('dept_b', 'Department-first B', 'deptfirst', 12, [12], false,
                    'One department at a time, chosen by a chip row that always states the scope.'),
                new Widget('dept_c', 'Department-first C', 'deptfirst', 12, [12], false,
                    'A card per department, so two same-named positions can never be read as one.'),
                new Widget('dept_d', 'Department-first D', 'deptfirst', 12, [12], false,
                    'One flat sortable list where every position carries its department in its name.'),
                new Widget('dept_e', 'Department-first E', 'deptfirst', 12, [12], false,
                    'A department rail beside a single-department pane — scales furthest.'),
            ],
            [
                new WidgetPreset(
                    'a',
                    'The grid',
                    'Positions across and permissions down, so “who may delete an area” is one row read left to right; every position added is another column, and past a dozen the table is a horizontal scroll nobody reads accurately.',
                    ['kpis' => 12, 'matrix_a' => 12, 'catalogue' => 12],
                ),
                new WidgetPreset(
                    'b',
                    'One position at a time',
                    'The safest editor there is — you are always demonstrably changing exactly one position and the header says how many people that reaches; comparing two positions costs two clicks and there is no view of the whole.',
                    ['kpis' => 12, 'matrix_b' => 12, 'catalogue' => 12],
                ),
                new WidgetPreset(
                    'c',
                    'Permission first',
                    'Shaped like the question an audit actually asks, so “who can delete an area” is one card rather than one row of eight; building a brand-new position means visiting nine cards to assemble it.',
                    ['kpis' => 12, 'matrix_c' => 12, 'catalogue' => 12],
                ),
                new WidgetPreset(
                    'd',
                    'Umbrella packs',
                    'Makes the matrix’s real behaviour legible — that it grows and shrinks with the installed bundles, and what uninstalling one costs the grants already written; no single screen ever shows one position whole.',
                    ['kpis' => 12, 'matrix_d' => 12, 'catalogue' => 12],
                ),
                new WidgetPreset(
                    'e',
                    'The effective ledger',
                    'The only direction that answers “why can this person not do that”, because every row carries its reason and the tier bypass stops being folklore; it edits nothing, so it is a companion to an editor rather than one.',
                    ['kpis' => 12, 'matrix_e' => 12, 'catalogue' => 12],
                ),
                new WidgetPreset(
                    'f',
                    'Department-first',
                    'Makes the department the structure of the page rather than a column on it, which is the honest shape now that a position belongs to one and its name is unique only inside it; five layouts of one idea, and the page gets longer the more departments an organisation has.',
                    ['dept_a' => 12, 'dept_b' => 12, 'dept_c' => 12, 'dept_d' => 12, 'dept_e' => 12],
                ),
            ],
            // B, not the catalogue's own composition — which IS B's layout, so
            // the strip carries six cards rather than a seventh saying the same.
            'b',
        );
    }
}
