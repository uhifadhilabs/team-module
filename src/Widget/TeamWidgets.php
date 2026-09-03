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
 * THE /team SURFACE — a transcription of the design's own surface declaration
 * (team/team.widgets.js), which is the specification.
 *
 * Nine widgets in two groups. The six `roster_*` entries are ALTERNATIVES rather
 * than additions: exactly one is on at a time, and every preset turns on exactly
 * one, because they are six ways to lay out the same people and stacking two
 * would be the page saying the same thing twice. The three in `context` are
 * additions — any direction can carry any of them.
 *
 * THE SHIPPED COMPOSITION IS NOT ONE OF THE SIX. It is the roster table with the
 * attention pane above it and the tier explainer below, and it leads the preset
 * strip under its own name, because a fresh installation's first visit to this
 * page is the one where something needs doing.
 *
 * KPI CARDS ALWAYS SIT AT THE TOP. Standing workspace rule, no exceptions — and
 * since a layout's key order is its render order, it is a property of every
 * layout below rather than of a template.
 *
 * EVERY DESCRIPTION IS THE DIRECTION'S TRADE-OFF LINE, verbatim from the design:
 * one sentence naming what the direction buys and what it costs, in that order.
 * What the design said about a direction has to be what the product says about
 * it, or the compare gallery and the product are two opinions.
 *
 * A CATALOGUE IS A STATEMENT OF WHAT A SURFACE SHIPS, so this class has no
 * dependencies and nothing may vary it at runtime.
 */
final class TeamWidgets implements WidgetSurfaceInterface
{
    /** What a stored preference row is keyed by — stable across releases. */
    public const string SURFACE = 'team';

    /** What the composition this module ships with is CALLED when it leads the strip. */
    public const string DEFAULT_LABEL = 'The team roster';

    public const string DEFAULT_DESCRIPTION = 'The counts, what needs a decision, the dense roster table and the note that tier and permission are two different things — the composition a fresh installation opens on.';

    public function catalog(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [
                new WidgetGroup(
                    'roster',
                    'The roster',
                    'Six ways to lay the same people out. They are alternatives, not additions — keep one on. Tier, department and position are the grouping axes the model has, and two of the six are built on them.',
                ),
                new WidgetGroup(
                    'context',
                    'Around the roster',
                    'The counts, the decisions waiting on a person, and the one explanation without which every list above is read wrongly. These are additions: any direction can carry any of them.',
                ),
            ],
            [
                new Widget('kpis', 'Team at a glance', 'context', 12, [12], true,
                    'Five counts, every one of them a query over the roster rather than a stored number.'),
                new Widget('attention', 'Needs a decision', 'context', 12, [12], true,
                    'Accounts that have never signed in, people who hold nothing, and the sole-Super-Admin risk.'),
                new Widget('roster_a', 'Roster — the table', 'roster', 12, [12], true,
                    'One dense table with search and filters. The only direction that still reads at three hundred people.'),
                new Widget('roster_b', 'Roster — grouped by tier', 'roster', 12, [12], false,
                    'Three bands — two escape hatches and everyone else — with every holder of team.manage marked.'),
                new Widget('roster_f', 'Roster — the org chart', 'roster', 12, [12], false,
                    'Bands by department, tier moved into a column, and an Unassigned band for whoever holds no position.'),
                new Widget('roster_c', 'Roster — person cards', 'roster', 12, [12], false,
                    'A card each: face, tier, position and what that position actually grants.'),
                new Widget('roster_d', 'Roster — grouped by position', 'roster', 12, [12], false,
                    'Bands by position, so four people holding one identical permission set are visibly one thing.'),
                new Widget('roster_e', 'Roster — attention first', 'roster', 12, [12], false,
                    'The decisions on top, the settled roster quietly below. For an installation somebody visits weekly.'),
                new Widget('tiers', 'How authority works here', 'context', 12, [12], true,
                    'Three tiers, two of which are escape hatches, and the one permission that makes somebody an administrator.'),
            ],
            [
                new WidgetPreset(
                    'roster_a',
                    'The table',
                    'One dense sortable table with search, tier chips and a position filter — the only direction that still works at three hundred people, and the only one in which nothing about the shape of the organisation is visible.',
                    ['kpis' => 12, 'roster_a' => 12, 'tiers' => 12],
                ),
                new WidgetPreset(
                    'roster_b',
                    'Grouped by tier',
                    'Three bands separate the two escape-hatch tiers from everybody else at a glance, which is the shape of the authority model now; it can no longer answer "who administers this" from the bands alone, so it marks every holder of team.manage instead.',
                    ['kpis' => 12, 'roster_b' => 12, 'tiers' => 12],
                ),
                new WidgetPreset(
                    'roster_f',
                    'The org chart',
                    'Bands by the axis an organisation of this shape actually has, so the roster sorts evenly where tier now sorts 1 + 1 + 10, and tier survives as a column rather than being lost; a department is reached through a position, so anybody holding none falls into an Unassigned band that has to be explained every time somebody new reads the page.',
                    ['kpis' => 12, 'roster_f' => 12, 'tiers' => 12],
                ),
                new WidgetPreset(
                    'roster_c',
                    'Person cards',
                    'A card each carries the face, the tier, the position and what that position actually grants all at once, which is exactly what you want while an organisation is still being assembled; past about forty people it becomes a very long scroll.',
                    ['kpis' => 12, 'roster_c' => 12, 'tiers' => 12],
                ),
                new WidgetPreset(
                    'roster_d',
                    'Grouped by position',
                    'The only direction in which four Rangers holding one identical permission set read as one thing rather than four rows, and the only one where the two Analysts are visibly two jobs; it bands twice, so it is the longest of the six and it hides tier almost completely.',
                    ['kpis' => 12, 'roster_d' => 12, 'tiers' => 12],
                ),
                new WidgetPreset(
                    'roster_e',
                    'Attention first',
                    'Everything needing a human decision is above everything that does not, which is the right shape for an installation somebody opens once a week; in a settled organisation it spends the top of the page saying that nothing is wrong.',
                    ['kpis' => 12, 'roster_e' => 12, 'tiers' => 12],
                ),
            ],
            WidgetCatalog::DEFAULT_PRESET_ID,
            self::DEFAULT_LABEL,
            self::DEFAULT_DESCRIPTION,
        );
    }
}
