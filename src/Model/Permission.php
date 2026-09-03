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
 *
 * THE SENTENCE IS NOT OPTIONAL HERE EITHER. Both sides are required to supply
 * one — the core enum through {@see \Uhifadhi\Team\Enum\PermissionEnum::description()},
 * a module through {@see \Uhifadhi\ModuleContracts\ModulePermission} — so the
 * matrix never has to ask where a row came from before it knows whether it can
 * explain it. A nullable description would have made the answer "sometimes",
 * and a matrix that explains some of its rows is one an administrator stops
 * reading.
 */
final readonly class Permission
{
    /**
     * @param string      $description    one sentence saying what holding this lets a person
     *                                    do, printed under the name in the matrix
     * @param string|null $capabilityRole the coarse umbrella role, core permissions only
     * @param string|null $source         the slug of the module that declared it, or null for
     *                                    one of the host's own — see {@see isCore()}
     */
    public function __construct(
        public string $value,
        public string $umbrella,
        public string $action,
        public string $description,
        public ?string $capabilityRole = null,
        public ?string $source = null,
    ) {
    }

    /**
     * WHETHER THIS ROW IS THE HOST'S OWN, and the matrix wears the answer.
     *
     * It is the thing that makes this matrix different from every other
     * permission matrix: the list is NOT FIXED. The seven core rows will always
     * be there; every other one arrived with a bundle and leaves with it. A row
     * that could not say which it was would leave an administrator unable to
     * tell a power the product has from a power something installed brought.
     */
    public function isCore(): bool
    {
        return null === $this->source;
    }

    public function label(): string
    {
        return $this->umbrella.' · '.$this->action;
    }
}
