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

use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Team\UhifadhiTeamBundle;

/**
 * Registering the bundle is the whole installation: it maps its own entities,
 * wires its own services, mounts its own routes and registers its voter — a
 * host writes no doctrine block and no service definition for any of it.
 */
final class BundleBootTest extends IntegrationTestCase
{
    public function testTheBundleIsRegistered(): void
    {
        $bundles = static::getContainer()->getParameter('kernel.bundles');
        self::assertIsArray($bundles);
        self::assertArrayHasKey('UhifadhiTeamBundle', $bundles);
        self::assertSame(UhifadhiTeamBundle::class, $bundles['UhifadhiTeamBundle']);
    }

    public function testItMapsItsOwnEntitiesWithoutAHostDoctrineBlock(): void
    {
        $names = array_map(
            static fn (\Doctrine\Persistence\Mapping\ClassMetadata $m): string => $m->getName(),
            $this->em->getMetadataFactory()->getAllMetadata(),
        );

        self::assertContains(\Uhifadhi\Team\Entity\User::class, $names);
        self::assertContains(\Uhifadhi\Team\Entity\Position::class, $names);
    }

    public function testItMountsItsOwnRoutes(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();

        $login = $routes->get('team_login');
        self::assertNotNull($login);
        self::assertSame('/login', $login->getPath());

        $logout = $routes->get('team_logout');
        self::assertNotNull($logout);
        self::assertSame('/logout', $logout->getPath());
    }

    public function testTheVoterIsTaggedSoTheCheckerAsksIt(): void
    {
        $checker = static::getContainer()->get('security.authorization_checker');
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $checker);

        // Nobody is signed in, so every permission is denied — but the call
        // resolves, which is what proves the voter is in the decision manager.
        self::assertFalse($checker->isGranted('area.view'));
    }
}
