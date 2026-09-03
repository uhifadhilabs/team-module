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
use Uhifadhi\Team\Entity\User;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower($email)]);
    }

    public function findOneByRangerCode(string $rangerCode): ?User
    {
        return $this->findOneBy(['rangerCode' => strtolower(trim($rangerCode))]);
    }

    /**
     * The field app's sign-in identifier, resolved the way a ranger might type
     * it: a service number normally, an email address for staff who have no
     * service number. One lookup surface, two honest spellings of "who".
     */
    public function findOneByFieldIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        return str_contains($identifier, '@')
            ? $this->findOneByEmail($identifier)
            : $this->findOneByRangerCode($identifier);
    }

    /**
     * The roster the field app caches at sign-in: everyone who can be named as
     * a patrol team member. Ordered by name so the phone's picker is stable.
     *
     * @return list<User>
     */
    public function findRoster(): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $users;
    }
}
