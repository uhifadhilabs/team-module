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
 * Each belongs to an umbrella (Areas, Modules) with a specific action (View, Create, …) and
 * implies a coarse umbrella *capability role* an installation's access_control can name — so a
 * position holding any permission in an umbrella opens that whole region, while the granular
 * permission itself is checked by {@see \Uhifadhi\Team\Security\PermissionVoter}.
 *
 * SIX, AND THERE IS NO INGESTION. A seventh case, `ingestion.run` under a `ROLE_INGESTION`
 * umbrella, existed here and is gone: this platform has no ingestion capability, and a
 * permission that guards nothing is a power an admin can assign over code that does not
 * exist. Nothing else moved with it — the two remaining umbrellas kept their values, their
 * roles and their order, so a position holding `area.edit` holds exactly what it held before.
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

    public function label(): string
    {
        return $this->umbrella().' · '.$this->action();
    }

    /**
     * The whole catalogue in declaration order, for the /team permission matrix.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return array{0: string, 1: string, 2: string} [umbrella, action, capabilityRole]
     */
    private function meta(): array
    {
        return match ($this) {
            self::AreaView => ['Areas', 'View', 'ROLE_AREAS'],
            self::AreaCreate => ['Areas', 'Create', 'ROLE_AREAS'],
            self::AreaEdit => ['Areas', 'Edit', 'ROLE_AREAS'],
            self::AreaDelete => ['Areas', 'Delete', 'ROLE_AREAS'],
            self::ModuleView => ['Modules', 'View', 'ROLE_MODULES'],
            self::ModuleCreate => ['Modules', 'Add', 'ROLE_MODULES'],
        };
    }
}
