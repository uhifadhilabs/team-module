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

namespace Uhifadhi\Team\ArgumentResolver;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\ModuleContracts\Entity\AreaInterface;
use Uhifadhi\Team\Entity\Department;

/**
 * THE CONVENIENCE HALF OF THE AREA-AWARE VOTER'S CONTRACT — it turns a `{uuid}`
 * route parameter into the platform's area, so a controller can write
 * `isGranted('patrols.record', $area)` with `$area` resolved for it.
 *
 * The voter's subject is PASSED EXPLICITLY (DECISIONS §5.1, docs/area-scoped-authority.md §7.1):
 * the area is an argument, not something the voter reaches into the request stack
 * for. That is what makes the voter unit-testable and usable off-route (a
 * console command, an API call naming an area). This resolver is the sugar that
 * keeps the on-route path terse: type an action argument as {@see AreaInterface}
 * and the area addressed by the route's uuid is loaded and handed in.
 *
 * IT REACHES THE AREA THROUGH THE CONTRACT, NEVER AN AREA PACKAGE — the same
 * arrangement {@see \Uhifadhi\Team\Controller\DepartmentController} uses: the
 * concrete class is whatever the installation resolved {@see AreaInterface} to
 * (uhifadhi/area-module, or the host's own), read off the association this module
 * already declares on {@see Department::$area}. So the resolver knows how to load
 * an area without ever naming the class that holds one.
 *
 * WHICH ROUTE PARAM. The argument's own name first (an action taking
 * `AreaInterface $area` reads an `{area}` param), then `{uuid}` by convention —
 * the address every area detail route carries. A param that is present but not a
 * valid, stored area is a 404: a named-but-unknown area is a wrong address, not a
 * silent null. Where NO such param is on the route, the resolver abstains
 * (yielding null only for a nullable argument), so it never fights the argument a
 * controller meant to fill some other way.
 */
final readonly class AreaValueResolver implements ValueResolverInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return iterable<AreaInterface|null>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();
        if (null === $type || AreaInterface::class !== $type && !is_subclass_of($type, AreaInterface::class)) {
            return [];
        }

        $raw = $this->routeValue($request, $argument->getName());
        if (null === $raw) {
            // No area param on this route. Yield null for a nullable argument so
            // an off-area action still resolves; otherwise leave it for another
            // resolver rather than inventing an area.
            return $argument->isNullable() ? [null] : [];
        }

        if (!Uuid::isValid($raw)) {
            throw new NotFoundHttpException('That area address is not a valid one.');
        }

        $area = $this->entityManager->getRepository($this->areaClass())
            ->findOneBy(['uuid' => Uuid::fromString($raw)]);

        if (!$area instanceof AreaInterface) {
            throw new NotFoundHttpException('No such area on this installation.');
        }

        return [$area];
    }

    /**
     * The area uuid on the route — the argument's own name first, then the `uuid`
     * convention. Read from the route attributes, never the query string: an
     * area addressed for an authorization check is part of the path, not a
     * suggestion.
     */
    private function routeValue(Request $request, string $argumentName): ?string
    {
        foreach ([$argumentName, 'uuid'] as $key) {
            $value = $request->attributes->get($key);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The concrete area class the installation resolved {@see AreaInterface} to,
     * read off {@see Department}'s association so no area package is named.
     *
     * @return class-string
     */
    private function areaClass(): string
    {
        return $this->entityManager->getClassMetadata(Department::class)->getAssociationTargetClass('area');
    }
}
