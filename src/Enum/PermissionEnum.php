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

namespace Uhifadhi\Team\Enum;

/**
 * The fixed catalogue of granular permissions a {@see \Uhifadhi\Team\Entity\Position} can grant.
 * Each belongs to an umbrella (Areas, Modules, Team) with a specific action (View, Create, …) and
 * implies a coarse umbrella *capability role* an installation's access_control can name — so a
 * position holding any permission in an umbrella opens that whole region, while the granular
 * permission itself is checked by {@see \Uhifadhi\Team\Security\PermissionVoter}.
 *
 * SEVEN, AND THE SEVENTH IS `team.manage`. It is what the retired Manager tier became: the tier
 * answered "may this person administer the team" invisibly, beside the matrix rather than in it,
 * and a second authority system running alongside the first is one nobody can audit. As a
 * permission the same authority is a row an administrator can see, count across the roster, and
 * take away — and it is granted the one way every other capability is, through a position.
 *
 * The Team umbrella carries exactly one row, and that is not an oversight. An umbrella is the
 * coarse region an installation's `access_control` names — `ROLE_TEAM` keeps `/team` shut — and
 * the granular row is what the voter then decides. One row today; the region is what the umbrella
 * is for.
 *
 * THERE IS NO INGESTION. An eighth case, `ingestion.run` under a `ROLE_INGESTION` umbrella,
 * existed here and is gone: this platform has no ingestion capability, and a permission that
 * guards nothing is a power an admin can assign over code that does not exist. Nothing else moved
 * with it — the two original umbrellas kept their values, their roles and their order, so a
 * position holding `area.edit` holds exactly what it held before.
 *
 * EVERY PERMISSION CARRIES A SENTENCE ({@see description()}), the core seven exactly as a
 * module-declared one does ({@see \Uhifadhi\ModuleContracts\ModulePermission}). The matrix prints
 * it under the name, because "Areas · Delete" says which words were chosen and not what ticking
 * the box hands over. A core row without one would be the single row on that page an
 * administrator cannot read, and the rule is that there are no such rows.
 *
 * Single-org: there is no party axis. An installation is one authority.
 */
enum PermissionEnum: string
{
    // Areas → ROLE_AREAS
    case AreaView = 'area.view';
    case AreaCreate = 'area.create';
    case AreaEdit = 'area.edit';
    case AreaDelete = 'area.delete';
    // Modules → ROLE_MODULES
    case ModuleView = 'module.view';
    case ModuleCreate = 'module.create';   // configure a module: settings + visualizations (composition is Admin-tier)
    // Team → ROLE_TEAM
    case TeamManage = 'team.manage';

    public function umbrella(): string
    {
        return $this->meta()[0];
    }

    public function action(): string
    {
        return $this->meta()[1];
    }

    /** The umbrella capability role this permission implies (for area-level access_control). */
    public function capabilityRole(): string
    {
        return $this->meta()[2];
    }

    /** One sentence saying what holding this lets a person do — printed under the name in the matrix. */
    public function description(): string
    {
        return $this->meta()[3];
    }

    public function label(): string
    {
        return $this->umbrella().' · '.$this->action();
    }

    /**
     * The whole catalogue in declaration order, for the /team/positions permission matrix.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [umbrella, action, capabilityRole, description]
     */
    private function meta(): array
    {
        return match ($this) {
            self::AreaView => ['Areas', 'View', 'ROLE_AREAS', 'See the areas this installation manages and everything recorded inside them.'],
            self::AreaCreate => ['Areas', 'Create', 'ROLE_AREAS', 'Draw a new area and add it to the installation.'],
            self::AreaEdit => ['Areas', 'Edit', 'ROLE_AREAS', 'Change an area’s name, its boundary and its settings.'],
            self::AreaDelete => ['Areas', 'Delete', 'ROLE_AREAS', 'Remove an area, and with it everything filed under that area.'],
            self::ModuleView => ['Modules', 'View', 'ROLE_MODULES', 'Open the modules switched on for an area and read what they show.'],
            self::ModuleCreate => ['Modules', 'Add', 'ROLE_MODULES', 'Switch a module on for an area and configure its settings and visualizations.'],
            self::TeamManage => ['Team', 'Manage', 'ROLE_TEAM', 'Administer this team: add people, deactivate them, change tiers, and compose the positions everybody else’s permissions come from.'],
        };
    }
}
