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
use Uhifadhi\Team\Enum\TeamRoleEnum;

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
     * How many people could still sign in and administer this installation at
     * the top tier. The number the sole-Super-Admin invariant turns on
     * ({@see \Uhifadhi\Team\Service\SuperAdminInvariant}).
     *
     * ACTIVE ONLY, deliberately: a Super Admin who left in March cannot fix
     * anything, so counting them would let the last usable account be
     * deactivated on the strength of one that is not.
     */
    public function countActiveSuperAdmins(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.teamRole = :tier')
            ->andWhere('u.isActive = true')
            ->setParameter('tier', TeamRoleEnum::SuperAdmin->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether this installation has anybody at all — the question the bootstrap
     * command asks to decide whether the account it is about to make is the
     * first, and therefore a Super Admin by default.
     */
    public function isEmpty(): bool
    {
        return 0 === (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Everyone who can be named as a patrol team member — the roster the field
     * app caches at sign-in. Ordered by name so the phone's picker is stable.
     *
     * NOT the /team page's list: that one searches, filters and pages, and it
     * is {@see findPage()} over a {@see \Uhifadhi\Team\Model\RosterQuery}.
     *
     * @return list<User>
     */
    public function findAllByName(): array
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
