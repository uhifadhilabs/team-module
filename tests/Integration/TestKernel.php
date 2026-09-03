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

namespace Uhifadhi\Team\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Shell\UhifadhiShellBundle;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Tests\Integration\Fixtures\DeclaringModuleProvider;
use Uhifadhi\Team\Tests\Integration\Fixtures\GuardedController;
use Uhifadhi\Team\Tests\Integration\Fixtures\SilentModuleProvider;
use Uhifadhi\Team\UhifadhiTeamBundle;
use Uhifadhi\Widget\UhifadhiWidgetBundle;

/**
 * The smallest host this bundle can live in: framework + twig + doctrine +
 * security + the shell the sign-in screen renders through, talking to a REAL
 * database (TEAM_TEST_DATABASE_URL, see phpunit.dist.xml).
 *
 * THE SECURITY CONFIG HERE IS THE README'S. What this kernel writes under
 * `security:` is what the README's "Wire the security" tells an installation to
 * put in its own config/packages/security.yaml — provider, form_login pointing
 * at this bundle's routes, the access_control ladder. The two are kept in step
 * deliberately: a test kernel that invented its own firewall would prove the
 * module works in a shape no installation has, and documentation nothing
 * exercises is documentation that rots.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new UXIconsBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new UhifadhiShellBundle();
        // Hard-required: both team screens are widget surfaces.
        yield new UhifadhiWidgetBundle();
        yield new UhifadhiTeamBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // A form login needs a session and a CSRF token manager, exactly as
            // a real host has them.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => ['enabled' => true],
            // asset() has to exist, because both the shell's document and this
            // module's own page link a stylesheet with it. AssetMapper is a dev
            // dependency of this bundle and takes over path resolution here, so
            // the hrefs come out content-digested exactly as they do in a real
            // installation — which is why the assertions match a stem and not a
            // literal filename.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => [
                    'algorithm' => 'auto',
                    // Test-only cost floor, the documented Symfony practice.
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => [
                    'entity' => ['class' => User::class, 'property' => 'email'],
                ],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'team_user_provider',
                    // Exactly as the README tells an installation to write it: a
                    // deactivated account is refused at the door, with a reason.
                    'user_checker' => 'team.user_checker',
                    'form_login' => [
                        'login_path' => 'team_login',
                        'check_path' => 'team_login',
                        'enable_csrf' => true,
                        'default_target_path' => '/',
                    ],
                    'logout' => [
                        'path' => 'team_logout',
                        'target' => 'team_login',
                    ],
                ],
            ],
            'role_hierarchy' => [
                // No ROLE_MANAGER: the tier that emitted it is gone, and what it
                // stood for is the team.manage permission under ROLE_TEAM.
                'ROLE_ADMIN' => ['ROLE_AREAS', 'ROLE_MODULES', 'ROLE_TEAM'],
                'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN', 'ROLE_ALLOWED_TO_SWITCH'],
            ],
            'access_control' => [
                ['path' => '^/login', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/_guarded', 'roles' => 'ROLE_USER'],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(TEAM_TEST_DATABASE_URL)%'],
            'orm' => [
                // The skeleton's own choice, mirrored so the bundle's SQL is
                // exercised against the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // THE README'S OWN HAND-STEP, and it is here for the same reason
                // the firewall above is: what this kernel writes is what an
                // installation is told to write. The widget module keeps a
                // layout per PERSON and points at the contract to do it, so
                // without this line the schema stops before it reaches a single
                // team_ table — which is exactly the failure an installation
                // would hit, and exactly why the README says so first.
                'resolve_target_entities' => [
                    ModuleUserInterface::class => User::class,
                ],
            ],
        ]);

        // No extra twig paths: every template this module renders is its own,
        // reached through the @UhifadhiTeam namespace the bundle registers.

        $container->extension('ux_icons', [
            'icon_dir' => __DIR__.'/Fixtures/icons',
            'ignore_not_found' => true,
        ]);

        // A module standing in for every installed module bundle: it DECLARES a
        // permission, which the catalogue must fold in beside the core seven.
        $container->services()
            ->set(DeclaringModuleProvider::class)
            ->tag('uhifadhi.module');

        // And one that declares NOTHING, which is what most modules do. The
        // matrix has to draw it rather than skip it, so the catalogue has to
        // know it is there.
        $container->services()
            ->set(SilentModuleProvider::class)
            ->tag('uhifadhi.module');

        // The thing behind the firewall (see configureRoutes).
        $container->services()->set(GuardedController::class)->public();

        // The framework's own hasher, made reachable: a suite proving a stored
        // password verifies has to use the same service the firewall does.
        $container->services()->alias('test_public.hasher', 'security.user_password_hasher')->public();

        // Public aliases so a test can hold the bundle's private services.
        foreach ([
            \Uhifadhi\Team\Service\PermissionCatalogue::class => 'team.permissions',
            \Uhifadhi\Team\Security\PermissionVoter::class => 'team.permission_voter',
            \Uhifadhi\Team\Repository\UserRepository::class => \Uhifadhi\Team\Repository\UserRepository::class,
            \Uhifadhi\Team\Repository\PositionRepository::class => \Uhifadhi\Team\Repository\PositionRepository::class,
            \Uhifadhi\Team\Repository\DepartmentRepository::class => \Uhifadhi\Team\Repository\DepartmentRepository::class,
            \Uhifadhi\Team\Service\SuperAdminInvariant::class => 'team.super_admin_invariant',
            \Uhifadhi\Team\Service\TeamOverview::class => 'team.overview',
            \Uhifadhi\Widget\Registry\WidgetSurfaceRegistry::class => 'widget.surfaces',
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Mounted exactly as the recipe's config/routes/team.yaml mounts it.
        $routes->import('@UhifadhiTeamBundle/src/Controller/', 'attribute');

        // Something behind the firewall, so "an anonymous visitor is sent to
        // /login" is a fact this suite can assert rather than assume.
        $routes->add('guarded', '/_guarded')
            ->controller(GuardedController::class);

        // The front door every installation has (the skeleton points `/` at the
        // shell's welcome page). It exists here because the firewall's
        // default_target_path sends a fresh sign-in to it, and a redirect to
        // nowhere would make the suite prove nothing.
        $routes->add('home', '/')->controller(GuardedController::class);
    }

    /**
     * THE STAND-IN HOST'S PROJECT DIRECTORY — a Flex-installed application's
     * asset side and nothing else. The shell's document renders the importmap
     * of whatever application it is installed in, so a suite that renders any
     * page through the page frame needs an application that has one. Pointing
     * the kernel at a fixture is how it gets one without this bundle growing an
     * importmap of its own, which a shipped bundle has no business carrying.
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures/app';
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/team-module-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/team-module-tests/log';
    }
}
