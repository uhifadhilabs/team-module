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

use Symfony\Component\HttpFoundation\Response;

/**
 * A page an installation would guard. It exists so "an anonymous visitor is
 * sent to the sign-in screen, and a signed-in one is not" is something the
 * suite can observe rather than reason about.
 */
final class GuardedController
{
    public function __invoke(): Response
    {
        return new Response('guarded');
    }
}
