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

use Uhifadhi\ModuleContracts\ModulePermission;
use Uhifadhi\ModuleContracts\ModuleProviderInterface;
use Uhifadhi\ModuleContracts\ModuleProviderTrait;

/**
 * Any installed module bundle, played by a fixture: it DECLARES one permission,
 * with the sentence every declaration now has to carry. The catalogue must hold
 * it beside the core seven, the matrix must print its sentence like any other,
 * and the voter must decide it — that is the whole of what declaring buys a
 * module.
 */
final class DeclaringModuleProvider implements ModuleProviderInterface
{
    use ModuleProviderTrait;

    public function slug(): string
    {
        return 'surveys';
    }

    public function name(): string
    {
        return 'Surveys';
    }

    public function category(): string
    {
        return 'operations';
    }

    public function permissions(): array
    {
        return [new ModulePermission(
            'surveys.record',
            'Surveys',
            'Record',
            'Enter a survey from the field and attach its counts to an area.',
        )];
    }
}
