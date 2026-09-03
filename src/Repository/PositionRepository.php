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

namespace Uhifadhi\Team\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Entity\User;

/**
 * @extends ServiceEntityRepository<Position>
 */
final class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    public function findOneByUuid(Uuid $uuid): ?Position
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * How many positions have at least one holder.
     *
     * The complement is the interesting number and it is a real state, not a
     * gap: a position created before its first person exists on purpose, and
     * "8 of 9 held" is the strip saying so.
     */
    public function countHeld(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->innerJoin(User::class, 'u', Join::WITH, 'u.position = p')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every position grouped under its department's name, for the pickers.
     *
     * THE PICKER IS GROUPED BECAUSE IT HAS TO BE: two of an installation's
     * positions may be called "Analyst", and a flat list would offer the same
     * word twice with no way to tell which is which. The optgroup is not
     * decoration here, it is the disambiguation.
     *
     * Positions no department owns are collected under a null key rather than
     * dropped — the Unassigned state is real, and a picker that hid those
     * positions would be a picker that cannot assign them.
     *
     * @return array<string, list<Position>> department name => positions, the
     *                                       unassigned ones under ''
     */
    public function findAllGroupedByDepartment(): array
    {
        /** @var list<Position> $positions */
        $positions = $this->createQueryBuilder('p')
            ->leftJoin('p.department', 'd')
            ->addSelect('d')
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($positions as $position) {
            $grouped[$position->getDepartment()?->getName() ?? ''][] = $position;
        }

        return $grouped;
    }

    /**
     * Every position, department-first — the order a matrix reads in, and the
     * order a position is NAMED in: "Protection Service / Analyst", never a
     * bare "Analyst", because two departments may own the word.
     *
     * @return list<Position>
     */
    public function findAllOrdered(): array
    {
        /** @var list<Position> $positions */
        $positions = $this->createQueryBuilder('p')
            ->leftJoin('p.department', 'd')
            ->addSelect('d')
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $positions;
    }
}
