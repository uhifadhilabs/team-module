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

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Team\DependencyInjection\TeamConfiguration;
use Uhifadhi\Team\Entity\User;

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
 * ZERO-CONFIG, WITH ONE HAND-STEP, AND THE FIREWALL IS IT. Registering the
 * bundle maps its own entities (no doctrine block for team_* tables in the
 * installing project), ANSWERS THE USER CONTRACT (no resolve_target_entities
 * either — see prependExtension), wires its own services and registers its
 * voter. The recipe adds its options and its routes.
 *
 * The firewall is the one thing left, and it is the one thing a module may not
 * do for you: only the installation knows which of its paths are public. The
 * test for whether something belongs here is whether the installation has a
 * decision to make — the security file is such a decision, and what
 * `UserInterface` means, while this module is installed, is not.
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

    /**
     * The AssetMapper namespace this bundle's assets/ directory is mapped to.
     *
     * It is the npm-style form of the composer package name, and it has to be:
     * Flex keys assets/controllers.json by '@'.<composer package name>, and
     * StimulusBundle resolves that key back to this directory. A different name
     * here is a controller the host cannot find.
     */
    public const string ASSET_NAMESPACE = '@uhifadhi/team-module';

    /**
     * The prefix every one of this bundle's Stimulus controllers is addressed
     * by in a template — StimulusBundle's own normalisation of the namespace
     * above ('@' dropped, '/' and '_' to '-'), so `permission-group` is reached
     * as `uhifadhi--team-module--permission-group`.
     */
    public const string CONTROLLER_PREFIX = 'uhifadhi--team-module--';

    /** Config lives under "team:", not the class-derived "uhifadhi_team:". */
    protected string $extensionAlias = 'team';

    public function configure(DefinitionConfigurator $definition): void
    {
        TeamConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /*
         * PREPENDED, AND THE FLAG IS LOAD-BEARING. `extension()` APPENDS by
         * default even when called from prependExtension() — which puts this
         * config LAST, where it would overrule the installation instead of
         * deferring to it. With `prepend: true` it goes first and the
         * application's own doctrine.yaml wins, which is the entire reason
         * shipping a resolution here is safe rather than presumptuous. It
         * changes nothing for the mappings below, whose key is this bundle's
         * alone and which nothing else writes.
         */

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

                    /*
                     * THE PACKAGE THAT PROVIDES THE ANSWER IS THE PACKAGE THAT
                     * STATES IT.
                     *
                     * Nearly every module keeps records with a name on them and
                     * points at the CONTRACT rather than at this bundle's User,
                     * because a module that type-hinted the latter would be a
                     * module no installation could run without this one. So
                     * something has to say what the interface means, and for as
                     * long as this module is installed the answer is not in
                     * doubt: it is this module's User.
                     *
                     * IT USED TO BE A DOCUMENTED HAND-STEP, and that was the
                     * wrong shape. A hand-step is for a decision only the
                     * installation can make; this was not one — the line said
                     * exactly one thing and only ever had one right value. Its
                     * cost was real, because forgetting it fails a long way
                     * from its cause: the container compiles, the kernel boots,
                     * and `doctrine:migrations:diff` stops on "Class
                     * 'Uhifadhi\ModuleContracts\Entity\UserInterface' does not
                     * exist" with nothing pointing back at the paragraph that
                     * was missed.
                     *
                     * THE ESCAPE HATCH IS SYMFONY'S OWN RULE AND NOT A SWITCH
                     * INVENTED HERE: prepended configuration LOSES to the
                     * application's. An installation whose people are its own
                     * entity names that entity under `doctrine.orm.
                     * resolve_target_entities` in its own config and its answer
                     * wins, with nothing to disable first. That property is
                     * precisely what makes shipping a default safe, so it is
                     * tested rather than assumed — see
                     * tests/Integration/Identity/ResolveTargetEntitiesTest.
                     */
                    'resolve_target_entities' => [
                        ModuleUserInterface::class => User::class,
                    ],
                ],
            ], prepend: true);
        }

        // The bundle's public/ dir is auto-registered by AssetMapper under the
        // namespace `bundles/uhifadhiteam` and content-versioned — no config
        // here, no assets:install.

        // THE MATRIX'S ONE ENHANCEMENT, SHIPPED WITH THE MATRIX. A bundle
        // contributes no importmap entry, but it does contribute an AssetMapper
        // path and a `symfony.controllers` block in assets/package.json, which
        // is how every symfony/ux package ships a Stimulus controller. Flex
        // writes the host's assets/controllers.json on install; nothing is
        // built. The composer keyword `symfony-ux` is what makes Flex look in
        // here at all — without it everything installs and nothing binds.
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            $container->extension('framework', [
                'asset_mapper' => [
                    'paths' => [
                        \dirname(__DIR__).'/assets' => self::ASSET_NAMESPACE,
                    ],
                ],
            ]);
        }
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

        // WHAT A LETTER NEEDS THAT A TRANSPORT HAS NO OPINION ABOUT. An empty
        // from-address is the honest default: it is what makes Mail report that
        // this installation cannot send, so the invite path refuses itself in
        // writing instead of dropping a colleague's invitation on the floor.
        $builder->setParameter(
            'team.mail_from',
            \is_string($config['mail_from'] ?? null) ? $config['mail_from'] : '',
        );

        $builder->setParameter(
            'team.installation_name',
            \is_string($config['installation_name'] ?? null)
                ? $config['installation_name']
                : TeamConfiguration::DEFAULT_INSTALLATION_NAME,
        );
    }
}
