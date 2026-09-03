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

namespace Uhifadhi\Team\Tests\Integration\Roster;

use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\RosterStateEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Model\RosterQuery;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * THE /team LIST, AND THE WHOLE OF ITS STATE IS IN ITS URL.
 *
 * Every control in the roster's tool row is a query parameter — the search box,
 * the tier chips, the position select, the state select, the page. That is what
 * makes a filtered roster bookmarkable, shareable and survivable across the back
 * button, and it is why the tool row is a plain GET form and the tier chips are
 * links: the list works with no JavaScript at all.
 *
 * ONE CRITERIA OBJECT RATHER THAN FIVE ARGUMENTS. A repository method with five
 * optional positional parameters is one nobody can call correctly from memory,
 * and it grows a sixth the first time a design asks for one.
 *
 * SEARCH IS ILIKE ACROSS FOUR COLUMNS — first name, last name, email and ranger
 * code — because that is exactly what the placeholder promises ("Name, email or
 * ranger code"), and a search box that quietly means less than its placeholder
 * is worse than one that promises less.
 *
 * PAGED WITH DOCTRINE'S OWN PAGINATOR, wrapped in a thin Page model, 25 to a
 * page. No API Platform and no new dependency: this is one list.
 */
final class RosterQueryTest extends IntegrationTestCase
{
    private function users(): UserRepository
    {
        return $this->service(UserRepository::class);
    }

    private function seedCast(): void
    {
        $protection = (new Department())->setName('Protection Service');
        $ecology = (new Department())->setName('Ecology');
        $this->em->persist($protection);
        $this->em->persist($ecology);

        $ranger = (new Position())->setName('Ranger')->setDepartment($protection);
        $analyst = (new Position())->setName('Analyst')->setDepartment($ecology);
        $this->em->persist($ranger);
        $this->em->persist($analyst);

        $this->person('Naomi', 'Kileo', 'n.kileo@example.test', TeamRoleEnum::SuperAdmin);
        $this->person('Salum', 'Mwaipopo', 's.mwaipopo@example.test', TeamRoleEnum::Admin);

        $grace = $this->person('Grace', 'Ndosi', 'g.ndosi@example.test');
        $grace->setPosition($ranger)->setRangerCode('R-104')->setVerified(true);

        $elias = $this->person('Elias', 'Mtui', 'e.mtui@example.test');
        $elias->setPosition($analyst)->setVerified(true);

        // Never signed in, and invited by nobody: created with a password.
        $this->person('Joseph', 'Mrema', 'j.mrema@example.test')
            ->setPosition($ranger)->setRangerCode('R-121');

        // Deactivated, and STILL LISTED — the roster is where "left" and "never
        // existed" are told apart.
        $this->person('Hawa', 'Rajabu', 'h.rajabu@example.test')->deactivate();

        // The model's zero: verified, can sign in, holds nothing at all.
        $this->person('Frank', 'Massawe', 'f.massawe@example.test')->setVerified(true);

        $this->em->flush();
        $this->em->clear();
    }

    private function person(string $first, string $last, string $email, TeamRoleEnum $tier = TeamRoleEnum::Staff): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($first)
            ->setLastName($last)
            ->setPassword('x')
            ->setTeamRole($tier);
        $this->em->persist($user);

        return $user;
    }

    /** @return list<string> */
    private function emails(RosterQuery $query): array
    {
        return array_map(
            static fn (User $u): string => (string) $u->getEmail(),
            $this->users()->findRoster($query)->items,
        );
    }

    public function testAnEmptyQueryReturnsEverybodyIncludingTheDeactivated(): void
    {
        $this->seedCast();

        $page = $this->users()->findRoster(new RosterQuery());

        self::assertSame(7, $page->total);
        self::assertCount(7, $page->items);
    }

    public function testTheListIsOrderedByName(): void
    {
        $this->seedCast();

        // By first name, then last — the order a person scans a roster in, and
        // stable, which is what makes paging mean anything.
        self::assertSame([
            'e.mtui@example.test',      // Elias Mtui
            'f.massawe@example.test',   // Frank Massawe
            'g.ndosi@example.test',     // Grace Ndosi
            'h.rajabu@example.test',    // Hawa Rajabu
            'j.mrema@example.test',     // Joseph Mrema
            'n.kileo@example.test',     // Naomi Kileo
            's.mwaipopo@example.test',  // Salum Mwaipopo
        ], $this->emails(new RosterQuery()));
    }

    public function testSearchMatchesAFirstName(): void
    {
        $this->seedCast();

        self::assertSame(['g.ndosi@example.test'], $this->emails(new RosterQuery(q: 'grace')));
    }

    public function testSearchMatchesALastNameCaseInsensitively(): void
    {
        $this->seedCast();

        self::assertSame(['g.ndosi@example.test'], $this->emails(new RosterQuery(q: 'NDOSI')));
    }

    public function testSearchMatchesAnEmailFragment(): void
    {
        $this->seedCast();

        self::assertSame(['s.mwaipopo@example.test'], $this->emails(new RosterQuery(q: 'mwaipopo@')));
    }

    /** The placeholder promises ranger code, so the search has to mean it. */
    public function testSearchMatchesARangerCode(): void
    {
        $this->seedCast();

        self::assertSame(['j.mrema@example.test'], $this->emails(new RosterQuery(q: 'R-121')));
    }

    public function testAnUnmatchedSearchIsAnEmptyPageAndNotAnError(): void
    {
        $this->seedCast();

        $page = $this->users()->findRoster(new RosterQuery(q: 'nobody at all'));

        self::assertSame(0, $page->total);
        self::assertSame([], $page->items);
    }

    public function testTheTierChipsFilter(): void
    {
        $this->seedCast();

        self::assertSame(['n.kileo@example.test'], $this->emails(new RosterQuery(tier: TeamRoleEnum::SuperAdmin)));
        self::assertCount(5, $this->emails(new RosterQuery(tier: TeamRoleEnum::Staff)));
    }

    public function testThePositionFilterNarrowsToOnePosition(): void
    {
        $this->seedCast();

        $ranger = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertInstanceOf(Position::class, $ranger);

        self::assertSame(
            ['g.ndosi@example.test', 'j.mrema@example.test'],
            $this->emails(new RosterQuery(position: $ranger->getUuidString())),
        );
    }

    /**
     * "— no position —" IS A FILTER VALUE. Holding nothing is a state somebody
     * needs to find people in, not the absence of a filterable fact.
     */
    public function testThePositionFilterCanAskForNobodysPosition(): void
    {
        $this->seedCast();

        self::assertSame(
            [
                'f.massawe@example.test',
                'h.rajabu@example.test',
                'n.kileo@example.test',
                's.mwaipopo@example.test',
            ],
            $this->emails(new RosterQuery(position: RosterQuery::NO_POSITION)),
        );
    }

    public function testTheStateFilterFindsTheActiveOnes(): void
    {
        $this->seedCast();

        $emails = $this->emails(new RosterQuery(state: RosterStateEnum::Active));

        self::assertCount(6, $emails);
        self::assertNotContains('h.rajabu@example.test', $emails);
    }

    /**
     * AND, AS IMPORTANTLY, THE SWITCHED-OFF ONES AGAIN — which is the whole
     * reason they were not deleted.
     */
    public function testTheStateFilterFindsTheDeactivatedOnes(): void
    {
        $this->seedCast();

        self::assertSame(['h.rajabu@example.test'], $this->emails(new RosterQuery(state: RosterStateEnum::Deactivated)));
    }

    public function testTheStateFilterFindsWhoHasNeverSignedIn(): void
    {
        $this->seedCast();

        // Four of the cast: the two who were invited or created and have not
        // arrived, and the two seeded without a verification pass. isVerified is
        // the whole of the test — the pill says exactly what the boolean says.
        self::assertSame(
            [
                'h.rajabu@example.test',
                'j.mrema@example.test',
                'n.kileo@example.test',
                's.mwaipopo@example.test',
            ],
            $this->emails(new RosterQuery(state: RosterStateEnum::NeverSignedIn)),
        );
    }

    /** Two axes, not one scale: a person can be any combination of them. */
    public function testStateAndTierCombine(): void
    {
        $this->seedCast();

        self::assertSame(
            ['h.rajabu@example.test'],
            $this->emails(new RosterQuery(tier: TeamRoleEnum::Staff, state: RosterStateEnum::Deactivated)),
        );
    }

    public function testTheDepartmentNarrowsThroughThePosition(): void
    {
        $this->seedCast();

        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']);
        self::assertInstanceOf(Department::class, $ecology);

        self::assertSame(
            ['e.mtui@example.test'],
            $this->emails(new RosterQuery(department: $ecology->getUuidString())),
        );
    }

    public function testAPageIsTwentyFiveAndSaysWhatItIsPartOf(): void
    {
        for ($i = 1; $i <= 30; ++$i) {
            $this->person('Person', \sprintf('%02d', $i), \sprintf('p%02d@example.test', $i));
        }
        $this->em->flush();
        $this->em->clear();

        $first = $this->users()->findRoster(new RosterQuery());

        self::assertSame(25, RosterQuery::PER_PAGE);
        self::assertCount(25, $first->items);
        self::assertSame(30, $first->total);
        self::assertSame(1, $first->page);
        self::assertSame(2, $first->pages());
        self::assertFalse($first->hasPrevious());
        self::assertTrue($first->hasNext());

        $second = $this->users()->findRoster(new RosterQuery(page: 2));

        self::assertCount(5, $second->items);
        self::assertSame(2, $second->page);
        self::assertTrue($second->hasPrevious());
        self::assertFalse($second->hasNext());
    }

    /**
     * A PAGE NUMBER IS UNTRUSTED INPUT. It arrives in a URL somebody can type,
     * so page 0, page -3 and page 900 are all requests a person can make and
     * none of them is an error worth a 500.
     */
    public function testAnOutOfRangePageIsClampedRatherThanFatal(): void
    {
        $this->seedCast();

        self::assertSame(1, $this->users()->findRoster(new RosterQuery(page: 0))->page);
        self::assertSame(1, $this->users()->findRoster(new RosterQuery(page: -3))->page);

        $far = $this->users()->findRoster(new RosterQuery(page: 900));
        self::assertSame([], $far->items, 'Past the end is empty, not an exception.');
        self::assertSame(7, $far->total);
    }

    /** An installation with nobody in it is a page, not a crash. */
    public function testAnEmptyInstallationIsAnEmptyPage(): void
    {
        $page = $this->users()->findRoster(new RosterQuery());

        self::assertSame(0, $page->total);
        self::assertSame(1, $page->pages(), 'One empty page rather than zero pages — the list still exists.');
    }

    /**
     * THE CHIP COUNTS ARE A QUERY, not a stored number. They are also counted
     * over the WHOLE roster rather than the filtered one: a chip that says
     * "Admin 0" because you are currently searching for "grace" is a chip
     * lying about the installation.
     */
    public function testTheTierCountsAreOverTheWholeRoster(): void
    {
        $this->seedCast();

        self::assertSame(
            ['super_admin' => 1, 'admin' => 1, 'staff' => 5],
            $this->users()->countByTier(),
        );
    }
}
