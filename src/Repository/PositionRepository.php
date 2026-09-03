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
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Team\Entity\Position;

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

    /**
     * Every position, by name — the order a matrix reads in.
     *
     * @return list<Position>
     */
    public function findAllOrdered(): array
    {
        /** @var list<Position> $positions */
        $positions = $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $positions;
    }
}
