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

namespace Uhifadhi\Team\Tests\Integration\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Uhifadhi\ModuleContracts\Entity\AreaInterface;
use Uhifadhi\Team\ArgumentResolver\AreaValueResolver;
use Uhifadhi\Team\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * THE {uuid} → AREA CONVENIENCE (DECISIONS §5.1). The voter's subject is passed
 * explicitly; this resolver is the sugar that fills an AreaInterface-typed
 * controller argument from the route's uuid, reaching the area through the
 * contract (never an area package).
 */
final class AreaValueResolverTest extends IntegrationTestCase
{
    private function resolver(): AreaValueResolver
    {
        return $this->service(AreaValueResolver::class);
    }

    private function argument(string $name = 'area', ?string $type = AreaInterface::class, bool $nullable = false): ArgumentMetadata
    {
        return new ArgumentMetadata($name, $type, false, false, null, $nullable);
    }

    private function requestWith(string $key, string $value): Request
    {
        $request = new Request();
        $request->attributes->set($key, $value);

        return $request;
    }

    private function storedArea(string $name = 'Serengeti'): HostArea
    {
        $area = (new HostArea())->setName($name);
        $this->em->persist($area);
        $this->em->flush();

        return $area;
    }

    public function testItResolvesAStoredAreaByTheUuidRouteParam(): void
    {
        $area = $this->storedArea();

        $resolved = iterator_to_array($this->resolver()->resolve(
            $this->requestWith('uuid', (string) $area->getUuidString()),
            $this->argument(),
        ));

        self::assertCount(1, $resolved);
        self::assertInstanceOf(AreaInterface::class, $resolved[0]);
        self::assertSame($area->getUuidString(), $resolved[0]->getUuidString());
    }

    public function testItReadsTheArgumentsOwnNameBeforeTheUuidConvention(): void
    {
        $area = $this->storedArea();

        $resolved = iterator_to_array($this->resolver()->resolve(
            $this->requestWith('area', (string) $area->getUuidString()),
            $this->argument('area'),
        ));

        self::assertCount(1, $resolved);
    }

    public function testAnUnknownAreaIsANotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        iterator_to_array($this->resolver()->resolve(
            $this->requestWith('uuid', \Symfony\Component\Uid\Uuid::v7()->toRfc4122()),
            $this->argument(),
        ));
    }

    public function testAnInvalidUuidIsANotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        iterator_to_array($this->resolver()->resolve(
            $this->requestWith('uuid', 'not-a-uuid'),
            $this->argument(),
        ));
    }

    public function testItAbstainsOnANonAreaArgument(): void
    {
        $resolved = iterator_to_array($this->resolver()->resolve(
            $this->requestWith('uuid', 'anything'),
            $this->argument('name', 'string'),
        ));

        self::assertSame([], $resolved);
    }

    public function testWithNoAreaParamItAbstainsButYieldsNullForANullableArgument(): void
    {
        // Non-nullable, no param → abstain (leave it for another resolver).
        self::assertSame([], iterator_to_array($this->resolver()->resolve(new Request(), $this->argument())));

        // Nullable, no param → null, so an off-area action still resolves.
        self::assertSame([null], iterator_to_array($this->resolver()->resolve(new Request(), $this->argument('area', AreaInterface::class, true))));
    }
}
