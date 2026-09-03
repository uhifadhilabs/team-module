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
 * Each belongs to an umbrella (Areas, Ingestion, …) with a specific action (View, Create, …)
 * and implies a coarse umbrella *capability role* used by security.yaml's access_control — so a
 * position holding any permission in an umbrella opens that whole area, while the granular
 * permission itself is checked by {@see \Uhifadhi\Team\Security\PermissionVoter}.
 *
 * Single-org: no party axis (uhifadhi is one authority, unlike vivutio's operator/supplier split).
 */
enum PermissionEnum: string
{
    // Areas → ROLE_AREAS
    case AreaView = 'area.view';
    case AreaCreate = 'area.create';
    case AreaEdit = 'area.edit';
    case AreaDelete = 'area.delete';
    // Ingestion → ROLE_INGESTION
    case IngestionRun = 'ingestion.run';
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
            self::IngestionRun => ['Ingestion', 'Run', 'ROLE_INGESTION'],
            self::ModuleView => ['Modules', 'View', 'ROLE_MODULES'],
            self::ModuleCreate => ['Modules', 'Add', 'ROLE_MODULES'],
        };
    }
}
