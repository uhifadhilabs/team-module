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

namespace Uhifadhi\Team\Tests\Integration\Fixtures;

use Uhifadhi\ModuleContracts\ModuleProviderInterface;
use Uhifadhi\ModuleContracts\ModuleProviderTrait;

/**
 * AN INSTALLED MODULE THAT DECLARES NOTHING — the common case, and a state the
 * matrix has to draw rather than skip.
 *
 * Most modules gate nothing beyond what the host already does, so their
 * permissions() returns the trait's empty list. Leaving them off the matrix
 * would read as "this module is not installed", which is a different and wrong
 * fact; the matrix says instead that it is here and has nothing to grant.
 */
final class SilentModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    public function slug(): string
    {
        return 'roster';
    }

    public function name(): string
    {
        return 'Roster';
    }

    public function category(): string
    {
        return 'operations';
    }
}
