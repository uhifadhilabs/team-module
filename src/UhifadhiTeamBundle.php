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

namespace Uhifadhi\Team;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Team\DependencyInjection\TeamConfiguration;

/**
 * TEAM — who an installation's people are, and how they sign in.
 *
 * The skeleton is the application, the seam carries the modules, the shell is
 * what you see, and this is who is looking. It owns the user account, the
 * position that bundles permissions, the permission catalogue every module's
 * declarations fold into, the voter that decides them, and the one screen the
 * product shows a stranger.
 *
 * IT DOES NOT OWN ENFORCEMENT, and cannot. Firewalls and access_control are
 * application configuration by Symfony's own design — `security.yaml` lives in
 * the installing project, because only that project knows which of its paths
 * are public. So this bundle does not write it, does not merge into it, and
 * ships no second file that quietly overrides it: the README states the exact
 * contents an installation should end up with, and the installation puts them
 * there. One security file, one owner. There is no separate security module and
 * there is nothing for one to hold.
 *
 * ZERO-CONFIG, WITH ONE HAND-STEP. Registering the bundle maps its own entities
 * (no doctrine block for team_user / team_position in the installing project),
 * wires its own services and registers its voter. The recipe adds its options
 * and its routes. The firewall is the hand-step, and it is the one thing a
 * module may not do for you.
 */
final class UhifadhiTeamBundle extends AbstractBundle
{
    /**
     * WHERE THIS BUNDLE'S OWN VOCABULARY IS SERVED FROM. The sign-in card is
     * the shell's document plus this sheet — the shell draws frames and knows
     * nothing about a login form, so the card's rules ship here. Stated once,
     * as a constant, because templates/login.html.twig links it and anyone
     * theming the screen has to be able to name it.
     */
    public const string STYLESHEET = 'bundles/uhifadhiteam/team.css';

    /** Config lives under "team:", not the class-derived "uhifadhi_team:". */
    protected string $extensionAlias = 'team';

    public function configure(DefinitionConfigurator $definition): void
    {
        TeamConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Zero-config persistence: the bundle maps its own entities, so an
        // installation never writes a doctrine mappings block for team_* tables.
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiTeam' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Team\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/uhifadhiteam` and content-versioned — no config
        // here, no assets:install.
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Where a visitor who asks for /login while already signed in is sent.
        // A PATH: this bundle cannot know what an installation calls its home
        // screen. See SecurityController for why that is not a route name.
        $builder->setParameter(
            'team.after_sign_in_path',
            \is_string($config['after_sign_in_path'] ?? null) ? $config['after_sign_in_path'] : '/',
        );

        // The line under the mark on the sign-in card. A deployment says who it
        // is here rather than by patching a template out of the vendor tree.
        $builder->setParameter(
            'team.sign_in_lede',
            \is_string($config['sign_in_lede'] ?? null)
                ? $config['sign_in_lede']
                : TeamConfiguration::DEFAULT_SIGN_IN_LEDE,
        );
    }
}
