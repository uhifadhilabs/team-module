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
use Uhifadhi\Team\Repository\DepartmentScopeChangeRepository;

/**
 * THE AREA-AWARE DEPARTMENT MANAGER — the register, and the lens every row opens.
 *
 * A department carries a scope now: area-level (confined to one area) or
 * org-level (spanning every area). This screen draws the two apart — area-level
 * first, grouped by their area's name, then org-level — states each row's scope
 * in an explicit column, creates in either scope (area-first, the picker
 * enumerating the installation's areas through the contract), opens every
 * department to its own lens, and changes a scope with a reason recorded to the
 * audit trail.
 *
 * THIS SUPERSEDES the org-only card screen the module shipped through v0.7: the
 * `.dcard`/`.pitem`/Unassigned-card vocabulary is gone, replaced by the
 * canonical register. Where a rule is unchanged (a department grants nothing;
 * DELETE/DEACTIVATE are not drawn, so not here) it is re-asserted below.
 */
final class DepartmentScreenTest extends WebTestCaseWithSchema
{
    // ---- the register lists both scope groups, area-first -----------------

    /**
     * THE TWO SCOPES ARE DRAWN APART. Area-level departments come first, under a
     * heading that names their AREA (read off the contract); org-level after,
     * under its own heading.
     */
    public function testTheRegisterGroupsAreaLevelUnderTheirAreaThenOrgLevel(): void
    {
        $crawler = $this->screen();

        $headings = $crawler->filter('[data-dp] .deptgroup .gh')->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Area-level · Ngorongoro', $headings);
        self::assertContains('Org-level', $headings);

        // Area-level precedes org-level.
        self::assertLessThan(
            array_search('Org-level', $headings, true),
            array_search('Area-level · Ngorongoro', $headings, true),
        );
    }

    /**
     * EVERY ROW STATES ITS SCOPE in an explicit aligned column: the area's name
     * for an area-level department, "Org-level" for an org-wide one.
     */
    public function testEachRowCarriesItsScopeColumn(): void
    {
        $crawler = $this->screen();

        self::assertSame('Ngorongoro', $this->row($crawler, 'Crater Management')->filter('.dr-scope .sc-v')->text());
        self::assertSame('Org-level', $this->row($crawler, 'Ecology')->filter('.dr-scope .sc-v')->text());
    }

    /** The area-level scope column wears the area accent; the org one does not. */
    public function testTheAreaScopeColumnIsMarkedAsArea(): void
    {
        $crawler = $this->screen();

        self::assertStringContainsString('area', (string) $this->row($crawler, 'Crater Management')->filter('.dr-scope')->attr('class'));
    }

    // ---- every department opens to its lens -------------------------------

    /**
     * EVERY DEPARTMENT IS OPENABLE — the name and the Lens action both point at
     * the department's own page, area-level or org-level alike.
     */
    public function testEveryRowOpensItsDepartmentThroughNameAndLens(): void
    {
        $crawler = $this->screen();

        foreach (['Crater Management', 'Ecology'] as $name) {
            $row = $this->row($crawler, $name);
            $show = '/departments/'.$this->uuidOf($name);

            self::assertSame($show, $row->filter('.dr-nm')->attr('href'), $name.' name links to its lens');
            self::assertSame($show, $row->filter('.dr-act a.acc')->attr('href'), $name.' Lens action links to its lens');
        }
    }

    /** And the lens opens, carrying the department's name and its scope. */
    public function testTheAreaLevelLensOpensAndReadsAsAreaScoped(): void
    {
        $this->screen();

        $crawler = $this->client->request('GET', '/departments/'.$this->uuidOf('Crater Management'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Crater Management', $crawler->filter('.dpthead h1')->text());
        self::assertStringContainsString('Ngorongoro', $crawler->filter('.dpthead .scope.area')->text());
        self::assertStringContainsString('confined to Ngorongoro', $crawler->filter('.scoperule')->first()->text());
    }

    public function testTheOrgLevelLensReadsAcrossEveryArea(): void
    {
        $this->screen();

        $crawler = $this->client->request('GET', '/departments/'.$this->uuidOf('Ecology'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Org-level', $crawler->filter('.dpthead .scope.org')->text());
        self::assertStringContainsString('reads across every area', $crawler->filter('.scoperule')->first()->text());
    }

    /**
     * THE IDENTITY CARD IS ON THE OVERVIEW TAB ONLY — the page-chrome ruling. It
     * lives inside the Overview panel, so it is present once and hidden with that
     * panel; the header/breadcrumb and the scope strip carry name+scope on every
     * tab instead.
     */
    public function testTheIdentityCardLivesOnTheOverviewPanelAlone(): void
    {
        $this->screen();

        $crawler = $this->client->request('GET', '/departments/'.$this->uuidOf('Ecology'));

        $factbands = $crawler->filter('.factband');
        self::assertCount(1, $factbands, 'exactly one identity card');
        self::assertSame(
            'overview',
            $factbands->closest('[data-tab-panel]')?->attr('data-tab-panel'),
            'the identity card is inside the Overview panel',
        );
        // The scope strip is NOT inside any tab panel — it sits above the tabs,
        // so scope reads on every tab.
        self::assertNull($crawler->filter('.scoperule')->first()->closest('[data-tab-panel]'));
    }

    /** KPIs, when the follow-up wires them, are laid on a single row (kstrip). */
    public function testThePerformanceKpisSitOnASingleRow(): void
    {
        $this->screen();

        $crawler = $this->client->request('GET', '/departments/'.$this->uuidOf('Ecology'));

        self::assertGreaterThan(0, $crawler->filter('[data-tab-panel="performance"] .grid.kstrip')->count());
    }

    // ---- create, per-area and per-org -------------------------------------

    /**
     * THE CREATE PICKER ENUMERATES THE INSTALLATION'S AREAS through the contract —
     * every area is an option, by name.
     */
    public function testTheCreatePickerListsEveryAreaByName(): void
    {
        $this->administrator();
        $this->area('Ngorongoro');
        $this->area('Pololeti Game Reserve');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');

        $options = $crawler->filter('form[data-create-department] select[name="area"] option')
            ->each(static fn (Crawler $c): string => $c->text());

        self::assertContains('Ngorongoro', $options);
        self::assertContains('Pololeti Game Reserve', $options);
    }

    /** CREATE PER-AREA — the picked area becomes the department's scope. */
    public function testCreatingADepartmentPerAreaConfinesItToThePickedArea(): void
    {
        $this->administrator();
        $ngorongoro = $this->area('Ngorongoro');
        $this->area('Pololeti Game Reserve');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $ngorongoro->getUuidString();
        $form['name'] = 'Crater Management';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $created = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Crater Management']);
        self::assertInstanceOf(Department::class, $created);
        self::assertTrue($created->isAreaLevel());
        self::assertSame('Ngorongoro', $created->getArea()?->getName());
    }

    /** CREATE PER-ORG — no area, spans every one. */
    public function testCreatingADepartmentPerOrgLeavesItOrgWide(): void
    {
        $this->administrator();
        $this->area('Ngorongoro');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'org';
        $form['name'] = 'Administration';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $created = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Administration']);
        self::assertInstanceOf(Department::class, $created);
        self::assertTrue($created->isOrgLevel());
        self::assertNull($created->getArea());
    }

    /** A name may repeat FROM ONE AREA TO ANOTHER — two areas may each run one. */
    public function testTheSameNameMayExistInTwoDifferentAreas(): void
    {
        $this->administrator();
        $ngorongoro = $this->area('Ngorongoro');
        $pololeti = $this->area('Pololeti Game Reserve');
        $this->areaDepartment('Anti-Poaching', $ngorongoro);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $pololeti->getUuidString();
        $form['name'] = 'Anti-Poaching';
        $this->client->submit($form);

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Department::class)->findBy(['name' => 'Anti-Poaching']));
    }

    /** But two ORG-WIDE departments of one name are the same one entered twice. */
    public function testASecondOrgWideDepartmentWithTheSameNameIsRefused(): void
    {
        $crawler = $this->screen();

        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'org';
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
        $this->area('Ngorongoro');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $this->client->submit($crawler->selectButton('Add department')->form());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('needs a name', $crawler->filter('[data-shell-flash]')->text());
        self::assertCount(0, $this->em->getRepository(Department::class)->findAll());
    }

    // ---- the audited scope change -----------------------------------------

    /**
     * CONFINE (org → area) — the department narrows to a picked area, and the
     * reason is recorded to the audit trail on the transition.
     */
    public function testConfiningAnOrgDepartmentToAnAreaRecordsAnAuditedReason(): void
    {
        $crawler = $this->screen();

        $form = $this->row($crawler, 'Ecology')->filter('form[action$="/scope"]')->selectButton('Confine to area')->form();
        $form['area'] = (string) $this->uuidOf('__area:Ngorongoro');
        $form['reason'] = 'Ecology now works only in the crater.';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']);
        self::assertInstanceOf(Department::class, $ecology);
        self::assertTrue($ecology->isAreaLevel());
        self::assertSame('Ngorongoro', $ecology->getArea()?->getName());

        $trail = $this->scopeChanges()->findForDepartment($ecology);
        self::assertCount(1, $trail);
        self::assertSame('Ecology now works only in the crater.', $trail[0]->getReason());
        self::assertSame('Naomi', $trail[0]->getChangedBy()?->getFirstName(), 'the audit line records who');
    }

    /** PROMOTE (area → org) — the department widens, still audited with a reason. */
    public function testPromotingAnAreaDepartmentToOrgWideRecordsAnAuditedReason(): void
    {
        $crawler = $this->screen();

        $form = $this->row($crawler, 'Crater Management')->filter('form[action$="/scope"]')->selectButton('Promote to org-wide')->form();
        $form['reason'] = 'Its remit is now the whole park.';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $crater = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Crater Management']);
        self::assertInstanceOf(Department::class, $crater);
        self::assertTrue($crater->isOrgLevel());

        $trail = $this->scopeChanges()->findForDepartment($crater);
        self::assertCount(1, $trail);
        self::assertSame('Its remit is now the whole park.', $trail[0]->getReason());
    }

    /** A BLANK REASON IS REFUSED, and the scope does not move. */
    public function testAScopeChangeWithNoReasonIsRefusedAndNothingMoves(): void
    {
        $crawler = $this->screen();

        $form = $this->row($crawler, 'Crater Management')->filter('form[action$="/scope"]')->selectButton('Promote to org-wide')->form();
        $form['reason'] = '   ';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('needs a reason', $crawler->filter('[data-shell-flash]')->text());

        $this->em->clear();
        $crater = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Crater Management']);
        self::assertInstanceOf(Department::class, $crater);
        self::assertTrue($crater->isAreaLevel(), 'the refusal happens before the scope moves');
        self::assertCount(0, $this->scopeChanges()->findForDepartment($crater));
    }

    // ---- rename and filing still work -------------------------------------

    public function testARowRenamesItsDepartmentFromThePanel(): void
    {
        $crawler = $this->screen();

        $form = $this->row($crawler, 'Ecology')->filter('form[action$="/rename"]')->selectButton('Rename')->form();
        $form['name'] = 'Ecology & Research';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']));
        self::assertInstanceOf(Department::class, $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology & Research']));
    }

    public function testAPositionIsFiledIntoADepartmentFromItsMoveControl(): void
    {
        $crawler = $this->screen();

        // The loose position sits in the "No department yet" group; move it into Ecology.
        $form = $crawler->filter('[data-unfiled] .posline')->selectButton('Move')->form();
        $form['department'] = $this->uuidOf('Ecology');
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $volunteer = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Volunteer']);
        self::assertSame('Ecology', $volunteer?->getDepartment()?->getName());
    }

    // ---- deactivate, never delete -----------------------------------------

    /** DELETE is never drawn; DEACTIVATE is — the standing fleet rule. */
    public function testTheScreenDeactivatesButNeverDeletesADepartment(): void
    {
        $crawler = $this->screen();

        $page = $crawler->filter('[data-dp]')->text();
        self::assertStringNotContainsString('Delete', $page);
        self::assertStringContainsString('Deactivate', $page);
    }

    /**
     * DEACTIVATING flips the flag without deleting: the row stays (greyed), its
     * positions keep their filing, and the footprint informs in the flash.
     */
    public function testDeactivatingADepartmentGreysItAndKeepsEverythingFiled(): void
    {
        $crawler = $this->screen();

        $form = $this->row($crawler, 'Ecology')->filter('form[action$="/deactivate"]')->selectButton('Deactivate anyway')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('deactivated', $crawler->filter('[data-shell-flash]')->text());

        $this->em->clear();
        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']);
        self::assertInstanceOf(Department::class, $ecology);
        self::assertFalse($ecology->isActive());
        self::assertNotNull($ecology->getDeactivatedAt());

        // The position filed under it keeps its filing — deactivate, never delete.
        $analyst = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Analyst', 'department' => $ecology->getId()]);
        self::assertInstanceOf(Position::class, $analyst);

        // Greyed in the register.
        self::assertStringContainsString('inactive', (string) $this->row($crawler, 'Ecology')->attr('class'));
    }

    /** A DEACTIVATED department is dropped from the position move control. */
    public function testADeactivatedDepartmentIsHiddenFromTheMoveControl(): void
    {
        $crawler = $this->screen();
        $form = $this->row($crawler, 'Ecology')->filter('form[action$="/deactivate"]')->selectButton('Deactivate anyway')->form();
        $this->client->submit($form);

        $crawler = $this->client->request('GET', '/departments');
        $options = $crawler->filter('[data-unfiled] .moveform select[name="department"] option')
            ->each(static fn (Crawler $c): string => $c->text());

        self::assertNotContains('Ecology', $options, 'a wound-down department takes no new filings');
    }

    /** REACTIVATE brings it back into the register and the pickers. */
    public function testReactivatingADepartmentBringsItBack(): void
    {
        $crawler = $this->screen();
        $this->client->submit($this->row($crawler, 'Ecology')->filter('form[action$="/deactivate"]')->selectButton('Deactivate anyway')->form());

        $crawler = $this->client->request('GET', '/departments');
        $this->client->submit($this->row($crawler, 'Ecology')->filter('form[action$="/reactivate"]')->selectButton('Reactivate')->form());

        self::assertResponseRedirects('/departments');

        $this->em->clear();
        $ecology = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Ecology']);
        self::assertInstanceOf(Department::class, $ecology);
        self::assertTrue($ecology->isActive());
        self::assertNull($ecology->getDeactivatedAt());
    }

    /** The deactivate write is gated like every other. */
    public function testTheDeactivateWriteIsGated(): void
    {
        $department = $this->department('Ecology');
        $this->em->flush();

        $this->client->request('POST', '/departments/'.$department->getUuidString().'/deactivate');
        self::assertResponseRedirects('http://localhost/login');
    }

    // ---- §5.7: a scope change is a privilege change -----------------------

    /** PROMOTION informs that everyone filed under it gains authority everywhere. */
    public function testPromotingNoticesThatAuthorityWidensToEveryArea(): void
    {
        $this->administrator();
        $ng = $this->area('Ngorongoro');
        $crater = $this->areaDepartment('Crater Management', $ng);
        $position = $this->position('Crater Ecologist', $crater, [PermissionEnum::AreaView->value]);
        $this->person('Zawadi', 'Kimaro', TeamRoleEnum::Staff)->setPosition($position);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $this->row($crawler, 'Crater Management')->filter('form[action$="/scope"]')->selectButton('Promote to org-wide')->form();
        $form['reason'] = 'Its remit is now the whole park.';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsStringIgnoringCase('every area', $crawler->filter('[data-shell-flash]')->text());
    }

    /** DEMOTION (confine) informs that authority elsewhere is lost. */
    public function testConfiningNoticesThatAuthorityElsewhereIsLost(): void
    {
        $this->administrator();
        $ng = $this->area('Ngorongoro');
        $ecology = $this->department('Ecology');
        $position = $this->position('Analyst', $ecology, [PermissionEnum::AreaView->value]);
        $this->person('Zawadi', 'Kimaro', TeamRoleEnum::Staff)->setPosition($position);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/departments');
        $form = $this->row($crawler, 'Ecology')->filter('form[action$="/scope"]')->selectButton('Confine to area')->form();
        $form['area'] = (string) $ng->getUuidString();
        $form['reason'] = 'Ecology now works only in the crater.';
        $this->client->submit($form);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsStringIgnoringCase('lost', $crawler->filter('[data-shell-flash]')->text());
    }

    /** The promote form carries the §5.7 privilege-gain notice (informs, never guards). */
    public function testThePromoteFormStatesThePrivilegeGain(): void
    {
        $crawler = $this->screen();

        $form = $this->row($crawler, 'Crater Management')->filter('form[action$="/scope"]');
        self::assertStringContainsStringIgnoringCase('every area', $form->text());
    }

    // ---- §5.6: what an area administrator may touch -----------------------

    /** An area-X admin CREATES an area-level department in their own area. */
    public function testAnAreaAdminCreatesAnAreaDepartmentInTheirOwnArea(): void
    {
        $ngorongoro = $this->area('Ngorongoro');
        $this->areaAdminIn($ngorongoro);

        $crawler = $this->client->request('GET', '/departments');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $ngorongoro->getUuidString();
        $form['name'] = 'Crater Ecology';
        $this->client->submit($form);

        self::assertResponseRedirects('/departments');
        $this->em->clear();
        $created = $this->em->getRepository(Department::class)->findOneBy(['name' => 'Crater Ecology']);
        self::assertInstanceOf(Department::class, $created);
        self::assertTrue($created->isAreaLevel());
    }

    /** But NOT an org-level one — minting an org department is escalation. */
    public function testAnAreaAdminCannotCreateAnOrgDepartment(): void
    {
        $ngorongoro = $this->area('Ngorongoro');
        $this->areaAdminIn($ngorongoro);

        $crawler = $this->client->request('GET', '/departments');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'org';
        $form['name'] = 'Administration';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Department::class)->findOneBy(['name' => 'Administration']));
    }

    /** And NOT a department in another area. */
    public function testAnAreaAdminCannotCreateADepartmentInAnotherArea(): void
    {
        $ngorongoro = $this->area('Ngorongoro');
        $pololeti = $this->area('Pololeti Game Reserve');
        $this->areaAdminIn($ngorongoro);

        $crawler = $this->client->request('GET', '/departments');
        $form = $crawler->selectButton('Add department')->form();
        $form['scope'] = 'area';
        $form['area'] = (string) $pololeti->getUuidString();
        $form['name'] = 'Anti-Poaching';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
    }

    /** An area admin may NOT change any department's scope. */
    public function testAnAreaAdminCannotChangeScope(): void
    {
        $ngorongoro = $this->area('Ngorongoro');
        $this->areaAdminIn($ngorongoro);

        // Their own area-level department — even so, scope change is unbounded.
        $crawler = $this->client->request('GET', '/departments');
        $form = $this->row($crawler, 'Warden Office')->filter('form[action$="/scope"]')->selectButton('Promote to org-wide')->form();
        $form['reason'] = 'trying to widen';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(403);
    }

    /** An area admin may deactivate their OWN area department, not an org one. */
    public function testAnAreaAdminDeactivatesTheirOwnAreaDepartmentButNotAnOrgOne(): void
    {
        $ngorongoro = $this->area('Ngorongoro');
        $this->areaAdminIn($ngorongoro);
        $ecology = $this->department('Ecology'); // org-level
        $this->em->flush();

        // Own area department: allowed.
        $crawler = $this->client->request('GET', '/departments');
        $this->client->submit($this->row($crawler, 'Warden Office')->filter('form[action$="/deactivate"]')->selectButton('Deactivate anyway')->form());
        self::assertResponseRedirects('/departments');

        // Org department: refused.
        $this->client->request('POST', '/departments/'.$ecology->getUuidString().'/deactivate', [
            '_token' => $this->tokenFrom('/departments'),
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    // ---- who may reach it -------------------------------------------------

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

    public function testTheScopeChangeWriteIsGated(): void
    {
        // No login: the client from setUp is anonymous until loginUser() is
        // called, and the audited scope-change route must refuse it at the door.
        $department = $this->department('Ecology');
        $this->em->flush();

        $this->client->request('POST', '/departments/'.$department->getUuidString().'/scope');
        self::assertResponseRedirects('http://localhost/login');
    }

    // ---- the cast ---------------------------------------------------------

    /**
     * The register's cast: one area (Ngorongoro), an area-level department in it
     * (Crater Management), two org-level (Ecology, Protection Service — the twin
     * Analysts the per-scope-uniqueness ruling exists for), and one loose
     * position nobody has filed.
     */
    private function screen(): Crawler
    {
        $this->administrator();

        $this->ngorongoro = $this->area('Ngorongoro');

        $crater = $this->areaDepartment('Crater Management', $this->ngorongoro);
        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');

        $this->position('Crater Ecologist', $crater);
        $this->position('Analyst', $ecology);
        $this->position('Analyst', $protection);
        $this->position('Volunteer', null);

        $this->em->flush();

        return $this->client->request('GET', '/departments');
    }

    /**
     * Sign in as an AREA-X administrator — a Staff member whose team.manage
     * comes through a position in an AREA-LEVEL department confined to $area, so
     * their authority-area is $area. They own a "Warden Office" department there
     * to act on.
     */
    private function areaAdminIn(\Uhifadhi\Team\Tests\Integration\Fixtures\Area\HostArea $area): \Uhifadhi\Team\Entity\User
    {
        $office = $this->areaDepartment('Warden Office', $area);
        $admin = $this->person('Amina', 'Salehe', TeamRoleEnum::Staff);
        $admin->setPosition($this->position('Warden', $office, [PermissionEnum::TeamManage->value]));
        $this->em->flush();
        $this->client->loginUser($admin);

        return $admin;
    }

    private ?\Uhifadhi\Team\Tests\Integration\Fixtures\Area\HostArea $ngorongoro = null;

    private function row(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('[data-dp] .deptwrap')
            ->reduce(static fn (Crawler $c): bool => $name === $c->filter('.dr-nm')->text())
            ->first();
    }

    /** The uuid of a department by name — or, for "__area:Name", of an area. */
    private function uuidOf(string $name): string
    {
        if (str_starts_with($name, '__area:')) {
            $area = $this->em->getRepository(\Uhifadhi\Team\Tests\Integration\Fixtures\Area\HostArea::class)
                ->findOneBy(['name' => substr($name, 7)]);
            self::assertNotNull($area);

            return (string) $area->getUuidString();
        }

        $department = $this->em->getRepository(Department::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Department::class, $department);

        return (string) $department->getUuidString();
    }

    private function scopeChanges(): DepartmentScopeChangeRepository
    {
        /** @var DepartmentScopeChangeRepository $repo */
        $repo = static::getContainer()->get('test_public.'.DepartmentScopeChangeRepository::class);

        return $repo;
    }
}
