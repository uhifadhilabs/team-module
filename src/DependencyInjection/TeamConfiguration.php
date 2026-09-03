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
 * The module's semantic configuration — how an installation configures identity
 * in config/packages/team.yaml:
 *
 *   team:
 *     after_sign_in_path: /
 *     sign_in_lede: 'Analytical observatory for protected areas.'
 *     mail_from: 'uhifadhi@example.org'
 *     installation_name: 'Ngorongoro Conservation Area'
 *
 * FOUR KEYS, AND NONE OF THEM IS SECURITY. What a deployment usually wants to
 * change about signing in — who may reach what, how long a session lives,
 * whether there is a remember-me cookie — is not here and cannot be: it is
 * the security configuration, which belongs to the installing project (see the
 * bundle class). What IS here is the two things the SCREEN needs and a firewall
 * has no opinion about: where a signed-in visitor is bounced to, and the
 * sentence the card says under the mark.
 *
 * THE TWO MAIL KEYS ARE NOT A MAILER. The transport is `MAILER_DSN` and belongs
 * to the framework; what is here is the two things a LETTER needs that no
 * transport has an opinion about — the address it comes from, and what to call
 * this installation in the subject line. `mail_from` defaults to empty on
 * purpose: an empty from-address is what makes {@see \Uhifadhi\Team\Service\Mail::isConfigured()}
 * answer no, so an installation that configured a transport and forgot the
 * address is told at the moment of ASKING rather than at the moment of sending.
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
    /** What an email calls this installation when a deployment has not said. */
    public const string DEFAULT_INSTALLATION_NAME = 'Uhifadhi';

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
                ->scalarNode('mail_from')
                    ->info('The address invitations and password-reset links come from. Empty means this installation cannot send: the invite path is then offered and refused in writing, and the forgot-password screen says so rather than swallowing the request.')
                    ->defaultValue('')
                ->end()
                ->scalarNode('installation_name')
                    ->info('What to call this installation in an email subject line. Never a default naming somebody\'s authority — the fallback is the product.')
                    ->defaultValue(self::DEFAULT_INSTALLATION_NAME)->cannotBeEmpty()
                ->end()
            ->end()
        ;
    }
}
