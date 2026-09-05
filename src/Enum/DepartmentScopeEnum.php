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
 * WHETHER A DEPARTMENT BELONGS TO THE WHOLE ORGANISATION OR TO ONE AREA.
 *
 * IT IS DERIVED, NOT STORED. A department carries a nullable area and nothing
 * else: `area = null` is {@see self::Org}, `area = X` is {@see self::Area}. There
 * is no scope column to disagree with the area, because a stored scope and a
 * nullable area are two facts that can drift apart, and the moment they do one of
 * them is lying. {@see \Uhifadhi\Team\Entity\Department::getScope()} reads it off
 * the area every time.
 *
 * This enum exists so the derived fact has a name the audit trail can record and
 * a screen can group by — an org→area confinement and an area→org promotion are
 * the two transitions {@see \Uhifadhi\Team\Entity\DepartmentScopeChange} logs,
 * and a `from`/`to` pair of these cases is how it says which way the change went.
 *
 * IT GRANTS NOTHING BY ITSELF. An area-level department is the unit that WILL
 * confine a Staff member's authority once the area-aware voter is wired (see
 * docs/area-scoped-authority.md), but that enforcement is not in this module yet.
 * Today the scope shapes emphasis and reach on a screen; it never gates data.
 */
enum DepartmentScopeEnum: string
{
    /** No area — the department belongs to the organisation and spans every area. */
    case Org = 'org';

    /** One area — the department belongs to that single area. */
    case Area = 'area';

    /** The label a screen prints for the scope. */
    public function label(): string
    {
        return match ($this) {
            self::Org => 'Org-level',
            self::Area => 'Area-level',
        };
    }
}
