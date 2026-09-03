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
use Uhifadhi\Team\Entity\Department;

/**
 * @extends ServiceEntityRepository<Department>
 */
final class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    public function findOneByUuid(Uuid $uuid): ?Department
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findOneByName(string $name): ?Department
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Every department, by name — the order a picker and a banded roster both
     * read in.
     *
     * @return list<Department>
     */
    public function findAllOrdered(): array
    {
        /** @var list<Department> $departments */
        $departments = $this->createQueryBuilder('d')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $departments;
    }
}
