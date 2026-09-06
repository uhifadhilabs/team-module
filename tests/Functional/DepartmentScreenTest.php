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

namespace Uhifadhi\Team\Tests\Functional;

use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;

/**
 * THE ORG CHART'S HOME — the screen that was missing, and the closed loop it
 * opens.
 *
 * Until this release a department arrived only by seeding: the model shipped in
 * v0.3.0, the matrix grouped by it, the roster banded by it, and NOTHING in the
 * product could make one. An installation whose seed had never run read
 * "Unassigned" against every position and had no way out of it, which is the
 * same shape of defect as a route nobody links.
 *
 * THE THREE WRITES ARE THE WHOLE SCREEN: make a department, rename one, and
 * file a position into one. Everything else on the page is a reading of those
 * three.
 *
 * DELETE IS NOT HERE, and that is asserted rather than merely true today. The
 * ruled posture for a position is deactivate-with-a-stated-reason, and it
 * applies to a department conceptually — but the wording of a refusal is a
 * DESIGN, and no design has been drawn for a department that still owns
 * positions. Rendering a destructive control ahead of its ruling is how the
 * ruling gets made by accident.
 */
final class DepartmentScreenTest extends WebTestCaseWithSchema
{
    /**
     * THE PAGE OPENS, AND ONE CARD PER DEPARTMENT IS THE READING — the drawn
     * one: two departments owning the same word can never be read as one job
     * when they are in separate cards.
     */
    public function testEveryDepartmentGetsItsOwnCard(): void
    {
        $crawler = $this->screen();

        $cards = $crawler->filter('[data-dp] .dcard:not(.un)');
        self::assertCount(2, $cards);
        self::assertSame(
            ['Ecology', 'Protection Service'],
            $cards->each(static fn (Crawler $c): string => $c->filter('.dh-l b')->text()),
        );
    }

    /**
     * A CARD CARRIES ITS POSITIONS AND WHAT THEY COST IN PEOPLE. Headcount is
     * the one number the org chart is asked for, and it is reached THROUGH the
     * positions — a department holds nobody directly.
     */
    public function testACardCountsItsPositionsAndTheActivePeopleReachedThroughThem(): void
    {
        $crawler = $this->screen();

        $ecology = $this->cardNamed($crawler, 'Ecology');
        self::assertSame(
            ['Analyst', 'Botanist'],
            $ecology->filter('.pitem .pn')->each(static fn (Crawler $c): string => trim($c->text())),
        );

        $meta = $ecology->filter('.dmeta')->text();
        self::assertStringContainsString('2 positions', $meta);
        self::assertStringContainsString('1 person', $meta);
    }

    /**
     * THE UNASSIGNED CARD IS NOT A DEPARTMENT, and the sentence beside it is
     * not decoration — a reader arriving here for the first time will otherwise
     * read it as a department called "No department yet". Same ruling the
     * roster's org chart already ships.
     */
    public function testPositionsOwnedByNobodyGetADashedCardThatSaysItIsNotADepartment(): void
    {
        $crawler = $this->screen();

        $loose = $crawler->filter('[data-dp] .dcard.un');
        self::assertCount(1, $loose);
        self::assertSame(['Volunteer'], $loose->filter('.pitem .pn')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertStringContainsString('not a department', $loose->filter('.dnote')->text());
    }

    /**
     * A FRESH INSTALLATION HAS NONE OF THESE, and the page says so in the one
     * place a person is looking — beside the control that ends it. This is the
     * state that made the screen necessary.
     */
    public function testAnInstallationWithNoDepartmentsIsToldSoBesideTheControlThatEndsIt(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-dp] .dcard:not(.un)'));
        self::assertStringContainsString(
            'no departments yet',
            strtolower($crawler->filter('.pm-first')->text()),
        );
    }

    // ---- the three writes ------------------------------------------------

    public function testTheHeaderFormMakesTheFirstDepartment(): void
    {
        $this->administrator();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $crawler->selectButton('Add')->form();
        $form['name'] = 'Ecology';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        self::assertInstanceOf(Department::class, $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']));
    }

    /**
     * THE NAME IS UNIQUE WITHIN ITS SCOPE. This screen adds org-wide
     * departments, and two of those by one name would be the same department
     * entered twice — the org bucket is unique on the name alone. (A name may
     * still repeat from one area to another; that is a different scope.) The
     * index would have said this in SQL; the person who typed it wants the
     * sentence.
     */
    public function testASecondDepartmentWithTheSameNameIsRefusedWithTheReason(): void
    {
        $crawler = $this->screen();

        $form = $crawler->selectButton('Add')->form();
        $form['name'] = 'Ecology';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already', $crawler->filter('[data-shell-flash]')->text());

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Department::class)->findBy(['name' => 'Ecology']));
    }

    public function testADepartmentWithNoNameIsRefused(): void
    {
        $this->administrator();
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $this->client->submit($crawler->selectButton('Add')->form());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('needs a name', $crawler->filter('[data-shell-flash]')->text());
        self::assertCount(0, $this->em->getRepository(Department::class)->findAll());
    }

    public function testTheCardHeadRenamesItsDepartment(): void
    {
        $crawler = $this->screen();

        $form = $this->cardNamed($crawler, 'Ecology')->selectButton('Rename')->form();
        $form['name'] = 'Ecology & Research';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']));
        self::assertInstanceOf(
            Department::class,
            $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology & Research']),
        );
    }

    public function testRenamingOntoANameThatExistsIsRefusedWithTheReason(): void
    {
        $crawler = $this->screen();

        $form = $this->cardNamed($crawler, 'Ecology')->selectButton('Rename')->form();
        $form['name'] = 'Protection Service';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already', $crawler->filter('[data-shell-flash]')->text());

        $this->em->clear();
        self::assertInstanceOf(Department::class, $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']));
    }

    /**
     * FILING A POSITION IS THE SCREEN'S REASON TO EXIST. The positions page can
     * already pick a department when a position is CREATED; nothing could move
     * one afterwards, which left every position seeded before its department
     * stranded.
     */
    public function testAPositionCanBeFiledIntoADepartmentFromItsRow(): void
    {
        $crawler = $this->screen();

        $form = $crawler->filter('[data-dp] .dcard.un .pitem')->selectButton('Move')->form();
        $form['department'] = $this->uuidOfDepartmentNamed('Ecology');
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $volunteer = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Volunteer']);
        self::assertInstanceOf(Position::class, $volunteer);
        self::assertSame('Ecology', $volunteer->getDepartment()?->getName());
    }

    /** And out again — the empty option is a destination, not a missing value. */
    public function testAPositionCanBeMovedBackOutToNoDepartment(): void
    {
        $crawler = $this->screen();

        $form = $this->cardNamed($crawler, 'Ecology')->filter('.pitem')->first()->selectButton('Move')->form();
        $form['department'] = '';
        $this->client->submit($form);

        $this->em->clear();
        $analyst = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Analyst', 'department' => null]);
        self::assertInstanceOf(Position::class, $analyst);
    }

    /**
     * AND THE MOVE THAT CANNOT HAPPEN SAYS WHY. `unique(department, name)` is
     * the ruling this module exists around: Ecology's Analyst and Protection
     * Service's Analyst are two jobs sharing a word, and filing one into the
     * other's department would collapse them. The refusal names the department.
     */
    public function testFilingAPositionIntoADepartmentThatAlreadyOwnsThatNameIsRefused(): void
    {
        $crawler = $this->screen();

        $form = $this->cardNamed($crawler, 'Protection Service')->filter('.pitem')->first()->selectButton('Move')->form();
        $form['department'] = $this->uuidOfDepartmentNamed('Ecology');
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        $flash = $crawler->filter('[data-shell-flash]')->text();
        self::assertStringContainsString('Ecology', $flash);
        self::assertStringContainsString('Analyst', $flash);

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Position::class)->findBy(['name' => 'Analyst']));
    }

    // ---- what is deliberately absent -------------------------------------

    /** DELETE IS NOT DRAWN, so it is not here — and this fails the day it is. */
    public function testNothingOnTheScreenDeletesOrDeactivatesADepartment(): void
    {
        $crawler = $this->screen();

        $page = $crawler->filter('[data-dp]')->text();
        self::assertStringNotContainsString('Delete', $page);
        self::assertStringNotContainsString('Deactivate', $page);
        self::assertCount(0, $crawler->filter('[data-dp] .softbtn.danger'));
    }

    /** No dashboard was drawn for this screen, so none was invented. */
    public function testTheScreenInventsNoKpiStripAndNoWidgetLibrary(): void
    {
        $crawler = $this->screen();

        self::assertCount(0, $crawler->filter('.w-grid'));
        self::assertCount(0, $crawler->filter('.kpi'));
    }

    // ---- who may reach it ------------------------------------------------

    public function testAColleagueWithoutTeamManageIsRefused(): void
    {
        $ranger = $this->person('Juma', 'Mwakalinga', TeamRoleEnum::Staff);
        $ranger->setPosition($this->position('Ranger', $this->department('Protection'), [PermissionEnum::AreaView->value]));
        $this->em->flush();
        $this->client->loginUser($ranger);

        $this->client->request('GET', '/departments');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAnonymousVisitorIsSentToSignIn(): void
    {
        $this->client->request('GET', '/departments');

        self::assertResponseRedirects('http://localhost/login');
    }

    /** Every write is gated too, not merely the reading of the page. */
    public function testTheWritesAreGatedAndNotOnlyThePage(): void
    {
        $this->client->request('POST', '/departments');
        self::assertResponseRedirects('http://localhost/login');
    }

    // ---- the cast --------------------------------------------------------

    /**
     * THE TWIN ANALYSTS ARE LOAD-BEARING: one in each department, which is the
     * pair the per-department-uniqueness ruling exists for, and the pair that
     * makes the refusal above a real refusal rather than a contrived one.
     */
    private function screen(): Crawler
    {
        $this->administrator();

        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');

        $this->position('Analyst', $ecology);
        $botanist = $this->position('Botanist', $ecology);
        $this->position('Analyst', $protection);
        $this->position('Volunteer', null);

        $this->person('Grace', 'Ndosi')->setPosition($botanist);
        $this->em->flush();

        return $this->client->request('GET', '/departments');
    }

    private function cardNamed(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('[data-dp] .dcard')
            ->reduce(static fn (Crawler $c): bool => $name === $c->filter('.dh-l b')->text())
            ->first();
    }

    private function uuidOfDepartmentNamed(string $name): string
    {
        $department = $this->em->getRepository(Department::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Department::class, $department);
        $uuid = $department->getUuidString();
        self::assertIsString($uuid);

        return $uuid;
    }
}
