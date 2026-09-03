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
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\RosterStateEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Model\Page;
use Uhifadhi\Team\Model\RosterQuery;

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
     * THE /team LIST, whole: one page of people plus enough about the rest to
     * draw the footer.
     *
     * Every criterion comes off the {@see RosterQuery} — the search box, the
     * tier chips, the two selects, the page — so the list's entire state is the
     * URL that asked for it.
     *
     * THE SEARCH IS ILIKE ACROSS FOUR COLUMNS: first name, last name, email and
     * ranger code, which is exactly what the box's placeholder promises. A
     * search that quietly meant less than its placeholder would be worse than
     * one that promised less. LOWER() over both sides rather than a
     * database-specific ILIKE keyword: the bundle does not get to assume which
     * engine an installation runs.
     *
     * THE JOIN TO POSITION IS A LEFT JOIN, and it has to be. A Staff member with
     * no position is the model's zero and belongs in the list; an inner join
     * would silently drop exactly the people the roster most needs to show.
     *
     * PAGING IS DOCTRINE'S OWN PAGINATOR behind {@see Page}. The page number
     * arrived in a URL somebody can type, so it is clamped to at least 1 — past
     * the end is an empty page rather than an exception, because a person
     * guessing at ?page=900 has not caused an error.
     *
     * @return Page<User>
     */
    public function findRoster(RosterQuery $query): Page
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.position', 'p')
            ->addSelect('p')
            ->leftJoin('p.department', 'd')
            ->addSelect('d')
            ->orderBy('u.firstName', 'ASC')
            ->addOrderBy('u.lastName', 'ASC')
            // The tie-break nobody sees and every pager needs: two people with
            // the same name must not swap places between page 1 and page 2.
            ->addOrderBy('u.id', 'ASC');

        if (null !== $query->q) {
            $qb->andWhere(
                'LOWER(u.firstName) LIKE :q OR LOWER(u.lastName) LIKE :q OR LOWER(u.email) LIKE :q OR LOWER(u.rangerCode) LIKE :q',
            )->setParameter('q', '%'.strtolower($query->q).'%');
        }

        if (null !== $query->tier) {
            $qb->andWhere('u.teamRole = :tier')->setParameter('tier', $query->tier->value);
        }

        if (RosterQuery::NO_POSITION === $query->position) {
            $qb->andWhere('u.position IS NULL');
        } elseif (null !== $query->position) {
            // An unparseable uuid in the URL matches nobody rather than throwing:
            // a stale bookmark is not a server error.
            $uuid = Uuid::isValid($query->position) ? Uuid::fromString($query->position) : null;
            null !== $uuid
                ? $qb->andWhere('p.uuid = :position')->setParameter('position', $uuid, UuidType::NAME)
                : $qb->andWhere('1 = 0');
        }

        if (null !== $query->department) {
            $uuid = Uuid::isValid($query->department) ? Uuid::fromString($query->department) : null;
            null !== $uuid
                ? $qb->andWhere('d.uuid = :department')->setParameter('department', $uuid, UuidType::NAME)
                : $qb->andWhere('1 = 0');
        }

        match ($query->state) {
            RosterStateEnum::Active => $qb->andWhere('u.isActive = true'),
            RosterStateEnum::Deactivated => $qb->andWhere('u.isActive = false'),
            RosterStateEnum::NeverSignedIn => $qb->andWhere('u.isVerified = false'),
            null => null,
        };

        $page = max(1, $query->page);
        $qb->setFirstResult(($page - 1) * RosterQuery::PER_PAGE)
            ->setMaxResults(RosterQuery::PER_PAGE);

        // fetchJoinCollection: false — every join above is to-one, so the
        // paginator does not need its extra distinct-identifier pass.
        $paginator = new Paginator($qb->getQuery(), false);

        /** @var list<User> $items */
        $items = array_values(iterator_to_array($paginator));

        return new Page($items, \count($paginator), $page, RosterQuery::PER_PAGE);
    }

    /**
     * The tier chips' counts, keyed by tier value.
     *
     * OVER THE WHOLE ROSTER, never the filtered one. A chip reading "Admin 0"
     * because somebody is currently searching for "grace" is a chip lying about
     * the installation — the counts are what the chips would show you, not what
     * you are looking at.
     *
     * @return array<string, int> every tier present, in enum order; a tier
     *                            nobody holds reads 0 rather than being absent
     */
    public function countByTier(): array
    {
        // The column is enum-typed, so Doctrine hydrates the CASE and not the
        // string — hence ->value on the way into the array.
        /** @var list<array{tier: TeamRoleEnum, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('u.teamRole AS tier', 'COUNT(u.id) AS n')
            ->groupBy('u.teamRole')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach (TeamRoleEnum::cases() as $tier) {
            $counts[$tier->value] = 0;
        }
        foreach ($rows as $row) {
            $counts[$row['tier']->value] = (int) $row['n'];
        }

        return $counts;
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
     * is {@see findPage()} over a {@see RosterQuery}.
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
