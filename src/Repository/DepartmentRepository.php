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

    /**
     * The active departments only, by name — what a PICKER offers. A deactivated
     * department is winding down, so nothing new is filed into it: the create
     * and confine area pickers and the position move control read this list, not
     * {@see findAllOrdered()} (which the register uses to draw the inactive rows
     * greyed, so they can be reactivated).
     *
     * @return list<Department>
     */
    public function findAllActiveOrdered(): array
    {
        /** @var list<Department> $departments */
        $departments = $this->createQueryBuilder('d')
            ->andWhere('d.active = true')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $departments;
    }

    /**
     * The area-level departments — those confined to one area — by name. The
     * register draws these first, grouped by their area; this is the model half
     * of that grouping. Area-level is `area IS NOT NULL`, because the scope is
     * derived from the area and never a column of its own.
     *
     * @return list<Department>
     */
    public function findAreaLevelOrdered(): array
    {
        /** @var list<Department> $departments */
        $departments = $this->createQueryBuilder('d')
            ->andWhere('d.area IS NOT NULL')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $departments;
    }

    /**
     * The org-level departments — those with no area, spanning every one — by
     * name. The register draws these after the area-level ones.
     *
     * @return list<Department>
     */
    public function findOrgLevelOrdered(): array
    {
        /** @var list<Department> $departments */
        $departments = $this->createQueryBuilder('d')
            ->andWhere('d.area IS NULL')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $departments;
    }
}
