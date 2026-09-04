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

namespace Uhifadhi\Team\Tests\Integration\Fixtures\Resolution;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Uhifadhi\Team\UhifadhiTeamBundle;
use Uhifadhi\Widget\UhifadhiWidgetBundle;

/**
 * THE SMALLEST HOST THE QUESTION CAN HONESTLY BE ASKED IN: framework, twig,
 * doctrine, security and this bundle, plus one module's entity pointing at the
 * contract.
 *
 * SMALLEST does not mean fewest bundles — it means nothing present that could
 * ANSWER the question on team's behalf. Every bundle here is one team requires
 * outright: it draws screens (twig), a team module in an installation with no
 * firewall would be a user table nobody can ever become (security), and both
 * its surfaces are widget surfaces (widget). A kernel that dropped any of them
 * would compile nothing and prove nothing.
 *
 * WIDGET ASKS THE QUESTION, IT DOES NOT ANSWER IT — its `WidgetPreference`
 * points at the same contract and its bundle prepends no resolution of its own,
 * which is checked rather than assumed. So the only package here that could
 * have supplied the answer is team, and the assertions are about team.
 *
 * The whole point is what is ABSENT from `configureContainer()`: there is no
 * `resolve_target_entities` here, because an installation should not have to
 * write one.
 */
final class ResolutionKernel extends Kernel
{
    /**
     * @param array<class-string, class-string> $override what the APPLICATION says, if anything —
     *                                                    the escape hatch under test
     */
    public function __construct(
        private readonly array $override = [],
        private readonly string $variant = 'plain',
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new UhifadhiWidgetBundle();
        yield new UhifadhiTeamBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            // The widget framework's write endpoint is CSRF-checked, so the
            // token manager has to exist for the container to compile.
            'csrf_protection' => ['enabled' => true],
            // Named explicitly rather than left to the micro-kernel's own
            // default: the widget library's controller takes the router to
            // build its action URLs, so the service has to exist for the
            // container to compile even though this kernel routes nothing.
            'router' => ['resource' => 'kernel::loadRoutes', 'type' => 'service', 'utf8' => true],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);

        // The smallest firewall that compiles. Nothing here is under test; it
        // exists because team requires security-bundle and its services take
        // the password hasher.
        $container->extension('security', [
            'providers' => ['in_memory' => ['memory' => null]],
            'firewalls' => ['main' => ['security' => false]],
        ]);

        $orm = [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
            // The one module standing in for every module that keeps a record
            // with a name on it.
            'mappings' => [
                'ResolutionFixtures' => [
                    'type' => 'attribute',
                    'dir' => __DIR__,
                    'prefix' => __NAMESPACE__,
                    'is_bundle' => false,
                ],
            ],
        ];

        // Written ONLY by the variant that is testing the escape hatch. The
        // plain kernel says nothing, which is the case an installation is in.
        if ([] !== $this->override) {
            $orm['resolve_target_entities'] = $this->override;
        }

        $container->extension('doctrine', [
            'dbal' => ['url' => '%env(TEAM_TEST_DATABASE_URL)%'],
            'orm' => $orm,
        ]);
    }

    /**
     * NO ROUTES. Team's controllers are services here and nothing is requested;
     * the router exists only because the widget library's controller takes it
     * to build its action URLs, and a router with no routes is the honest
     * shape for a kernel that renders nothing.
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    /** Per-variant, so one kernel's compiled container can never answer for the other. */
    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/team-module-tests/resolution/'.$this->variant;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/team-module-tests/resolution/log';
    }
}
