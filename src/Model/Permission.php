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

namespace Uhifadhi\Team\Model;

/**
 * One entry of the deployment's permission catalogue, whichever side declared
 * it: the app's own {@see \Uhifadhi\Team\Enum\PermissionEnum} cases or a
 * permission an installed module declares through its provider. The matrix and
 * the voter treat both identically; only core permissions may imply an umbrella
 * capability role — a module-declared permission never mints one (declared,
 * never granted).
 */
final readonly class Permission
{
    public function __construct(
        public string $value,
        public string $umbrella,
        public string $action,
        public ?string $capabilityRole = null,
    ) {
    }

    public function label(): string
    {
        return $this->umbrella.' · '.$this->action;
    }
}
