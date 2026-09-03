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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Team\Command\CreateUserCommand;
use Uhifadhi\Team\Controller\SecurityController;
use Uhifadhi\Team\Repository\DepartmentRepository;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Security\ActiveUserChecker;
use Uhifadhi\Team\Security\PermissionVoter;
use Uhifadhi\Team\Service\PermissionCatalogue;
use Uhifadhi\Team\Service\SuperAdminInvariant;
use Uhifadhi\Team\Service\TeamOverview;
use Uhifadhi\Team\Widget\PositionWidgets;
use Uhifadhi\Team\Widget\TeamWidgets;
use Uhifadhi\Widget\Registry\WidgetSurfaceInterface;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml onto
 * an installation, and FQCN references stay refactor-safe and phpstan-checked. Imported by
 * UhifadhiTeamBundle::loadExtension(), which keeps only the config-DRIVEN bits.
 *
 * Everything below is defined EXPLICITLY — no autowire(), no autoconfigure(),
 * and ids prefixed with the bundle alias — because this bundle is installed by
 * other projects via Composer, which is what Symfony calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * The ids are the published surface. They are private, as a reusable bundle's
 * should be; anything that wants one aliases it.
 *
 *   team.permissions            the catalogue: this bundle's seven + what modules declared
 *   team.permission_voter       who holds which of them
 *   team.super_admin_invariant  the refusal that keeps one active Super Admin
 *   team.user_checker           the sign-in refusal for a deactivated account
 *   team.overview               the roster's counts and its attention rows
 *   team.widget_surface.*       the roster and the matrix, as dashboard surfaces
 *   team.command.create_user    the bootstrap console command
 *   team.controller.security    the sign-in screen
 *
 * Controllers extend nothing and take their collaborators explicitly, patterned
 * on FrameworkBundle's own TemplateController (see
 * vendor/symfony/framework-bundle/Controller/TemplateController.php).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    /*
     * Repositories keep FQCN ids — the one place the bundle-alias prefix cannot
     * be used: ServiceRepositoryCompilerPass keys its locator by SERVICE ID over
     * findTaggedServiceIds(), while ContainerRepositoryFactory looks a repository
     * up by CLASS NAME; tagged-id lookup never sees aliases.
     *
     * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/Compiler/ServiceRepositoryCompilerPass.php
     */
    $services->set(UserRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(PositionRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    $services->set(DepartmentRepository::class)
        ->args([service('doctrine')])
        ->tag('doctrine.repository_service');

    /*
     * THE CATALOGUE, reading the module providers LIVE from the container in
     * registration order — which is what makes uninstalling a bundle take its
     * declared permissions with it on the next request rather than the next
     * deploy. The tag string is the seam's, written out here rather than
     * imported: this bundle must work in an installation that has no seam (a
     * deployment with no modules still has people), and a class constant would
     * have made uhifadhi/seam-module a hard dependency of signing in.
     */
    $services->set('team.permissions', PermissionCatalogue::class)
        ->args([tagged_iterator('uhifadhi.module')]);

    /*
     * The voter, tagged by hand. A reusable bundle is not autoconfigured, and a
     * voter that missed this tag would deny nothing and grant nothing — it would
     * simply never be asked, which looks exactly like a permission model that
     * does not work.
     */
    $services->set('team.permission_voter', PermissionVoter::class)
        ->args([service('team.permissions')])
        ->tag('security.voter');

    /*
     * THE SOLE-ACTIVE-SUPER-ADMIN INVARIANT. Every write path that lowers a
     * tier or deactivates an account asks this first, so the refusal happens
     * before anything is stored rather than after.
     */
    $services->set('team.super_admin_invariant', SuperAdminInvariant::class)
        ->args([service(UserRepository::class)]);

    /*
     * The sign-in refusal for a deactivated account. NOT tagged: a user checker
     * is named by the FIREWALL (`user_checker:`), which is the installation's
     * file — so the service is registered and public here, and the README's
     * security block names its id. A tag would have been this bundle deciding
     * a firewall's shape for every installation that has one.
     */
    $services->set('team.user_checker', ActiveUserChecker::class)->public();

    /*
     * WHAT THE TEAM PAGE KNOWS BEFORE IT DRAWS A ROW — the counts and the
     * decisions waiting on a person, asked once for the whole surface rather
     * than once per widget.
     */
    $services->set('team.overview', TeamOverview::class)
        ->args([
            service(UserRepository::class),
            service(PositionRepository::class),
            service(DepartmentRepository::class),
        ]);

    /*
     * THE TWO DASHBOARD SURFACES. Both team screens ride the widget framework
     * rather than a copy of it, which is why uhifadhi/widget-module is a hard
     * requirement of this module and not a suggestion: the roster and the matrix
     * are widget surfaces, and a surface with no framework under it is a page
     * whose six drawn directions can never be adopted.
     *
     * The tag goes on BY HAND. A reusable bundle is not autoconfigured, and a
     * surface that missed the tag has a working dashboard and an unreachable
     * registry entry — nothing renders differently until the day somebody runs
     * `widget:prune` and it reads their stored layouts as orphans.
     */
    $services->set('team.widget_surface.roster', TeamWidgets::class)
        ->tag(WidgetSurfaceInterface::TAG);

    $services->set('team.widget_surface.positions', PositionWidgets::class)
        ->tag(WidgetSurfaceInterface::TAG);

    /*
     * THE BOOTSTRAP COMMAND — how the first administrator of a fresh
     * installation exists, before there is anybody who could add them through
     * the product. Tagged by hand like everything else here; a reusable bundle
     * is not autoconfigured, and an untagged command is one `bin/console` never
     * lists.
     */
    $services->set('team.command.create_user', CreateUserCommand::class)
        ->args([
            service(UserRepository::class),
            service('security.user_password_hasher'),
            service('doctrine.orm.entity_manager'),
        ])
        ->tag('console.command');

    /*
     * The sign-in screen. Registered unconditionally: this bundle requires
     * symfony/security-bundle outright, unlike a module that merely benefits
     * from one — a team module in an installation with no firewall would be a
     * user table nobody can ever become.
     *
     * The alias is what makes `SecurityController::login` resolvable from the
     * attribute route: Symfony's controller resolver looks the class name up in
     * the container, and a bundle's own services are private by default.
     */
    $services->set('team.controller.security', SecurityController::class)
        ->args([
            service('twig'),
            service('security.authentication_utils'),
            service('security.token_storage'),
            param('team.after_sign_in_path'),
            param('team.sign_in_lede'),
        ])
        ->tag('controller.service_arguments');
    $services->alias(SecurityController::class, 'team.controller.security')->public();
};
