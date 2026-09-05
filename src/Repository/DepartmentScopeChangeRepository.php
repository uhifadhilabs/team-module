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
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\DepartmentScopeChange;

/**
 * @extends ServiceEntityRepository<DepartmentScopeChange>
 */
final class DepartmentScopeChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepartmentScopeChange::class);
    }

    /**
     * One department's scope history, oldest first — the order a trail reads in.
     *
     * @return list<DepartmentScopeChange>
     */
    public function findForDepartment(Department $department): array
    {
        /** @var list<DepartmentScopeChange> $changes */
        $changes = $this->createQueryBuilder('c')
            ->andWhere('c.department = :department')
            ->setParameter('department', $department)
            ->orderBy('c.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $changes;
    }
}
