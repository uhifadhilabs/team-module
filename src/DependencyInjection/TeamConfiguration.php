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

namespace Uhifadhi\Team\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;

/**
 * The bundle's semantic configuration — how a host configures identity in
 * config/packages/team.yaml:
 *
 *   team:
 *     after_sign_in_path: /
 *     sign_in_lede: 'Analytical observatory for protected areas.'
 *
 * TWO KEYS, AND NEITHER OF THEM IS SECURITY. What a deployment usually wants to
 * change about signing in — who may reach what, how long a session lives,
 * whether there is a remember-me cookie — is not here and cannot be: it is
 * `security.yaml`, which belongs to the installing project (see the bundle
 * class). What IS here is the two things the SCREEN needs and a firewall has no
 * opinion about: where a signed-in visitor is bounced to, and the sentence the
 * card says under the mark.
 *
 * The tree is closed, so an invented key fails loudly.
 *
 * Static so the tree is testable with a plain Processor and shared verbatim by
 * the bundle's configure().
 */
final class TeamConfiguration
{
    /**
     * What the card says when a deployment has not said anything else. It
     * describes the PRODUCT, never an organisation: a default naming somebody's
     * authority would be a lie on every other installation.
     */
    public const string DEFAULT_SIGN_IN_LEDE = 'Analytical observatory for protected areas. Sign in to reach this installation and its modules.';

    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The team root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('after_sign_in_path')
                    ->info('Where a visitor who asks for /login while already signed in is sent. A path, not a route name — this bundle cannot know what your home screen is called.')
                    ->defaultValue('/')->cannotBeEmpty()
                ->end()
                ->scalarNode('sign_in_lede')
                    ->info('The sentence under the mark on the sign-in card.')
                    ->defaultValue(self::DEFAULT_SIGN_IN_LEDE)->cannotBeEmpty()
                ->end()
            ->end()
        ;
    }
}
