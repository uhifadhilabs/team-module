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

use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Enum\PermissionEnum;

/**
 * THE PERMISSION MATRIX, RENDERED AND SAVED.
 *
 * What is asserted here is what makes this matrix different from every other
 * permission matrix, and what a template can get wrong about it:
 *
 *   · the per-module groups are CLEARLY BOUNDED and each names its contributor;
 *   · every row carries the sentence that says what holding it does;
 *   · an installed module that declares nothing is DRAWN, not skipped;
 *   · an orphaned grant is drawn muted and SURVIVES a save that does not touch
 *     it, because editing a position is not a migration;
 *   · a module-declared permission round-trips through the save.
 */
final class PositionMatrixTest extends WebTestCaseWithSchema
{
    public function testTheMatrixPageRenders(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/positions');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Positions &amp; permissions', $crawler->filter('h1.pg')->html());
    }

    /** Gated on the permission it grants — a Staff member without it is refused. */
    public function testItIsGatedOnTeamManage(): void
    {
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();
        $this->client->loginUser($frank);

        $this->client->request('GET', '/team/positions');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * B SHIPS SELECTED, and its empty state is the argument: on day one there
     * are no positions, and B is the only direction whose empty state is a
     * control that makes the first one.
     */
    public function testWithNoPositionsTheShippedDirectionSaysSoRatherThanDrawingNothing(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/positions');

        self::assertCount(1, $crawler->filter('[data-pm="b"]'));
        self::assertStringContainsString('There are no positions yet', $crawler->html());
    }

    /**
     * EVERY UMBRELLA IS ONE BOUNDED GROUP WEARING ITS CONTRIBUTOR — the PM·C
     * finding, applied to the direction that ships. A reader has to be able to
     * see where one module's permissions end and the next module's begin.
     */
    public function testEachUmbrellaIsABoundedGroupNamingWhoBroughtIt(): void
    {
        $this->administrator();
        $this->position('Ranger', $this->department('Protection Service'), ['area.view']);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        // Three core umbrellas plus the one a module declared.
        $groups = $crawler->filter('[data-pm="b"] .pm-group');
        self::assertGreaterThanOrEqual(4, $groups->count());

        self::assertStringContainsString('the host', $crawler->filter('[data-pm="b"] .pm-by.core')->first()->text());
        // The module's own name, from its own provider — never a word this page invented.
        self::assertStringContainsString('Surveys', $crawler->filter('[data-pm="b"] .pm-by.surveys')->first()->text());
    }

    /** THE DESCRIPTION IS PRINTED UNDER THE NAME. That is the whole of the ruling. */
    public function testEveryRowCarriesItsSentence(): void
    {
        $this->administrator();
        $this->position('Ranger', $this->department('Protection Service'), []);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertStringContainsString(
            PermissionEnum::TeamManage->description(),
            $crawler->filter('[data-pm="b"]')->html(),
        );
        // And the module's own sentence reaches the page unchanged.
        self::assertStringContainsString(
            'Enter a survey from the field and attach its counts to an area.',
            $crawler->filter('[data-pm="b"]')->html(),
        );
    }

    /**
     * AN INSTALLED MODULE THAT DECLARES NOTHING IS DRAWN. Hiding it would read
     * as "that module is not installed", which is a different and wrong fact.
     */
    public function testAModuleThatDeclaresNothingIsStillOnThePage(): void
    {
        $this->administrator();
        $this->position('Ranger', $this->department('Protection Service'), []);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertStringContainsString('Installed, and it declares no permissions', $crawler->html());
        self::assertStringContainsString('Roster', $crawler->filter('.pm-mod-roster')->first()->text());
    }

    public function testTickingABoxGrantsItAndTheFlashSaysWhoItReaches(): void
    {
        $naomi = $this->administrator();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), []);
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($ranger);
        $this->em->flush();

        $token = $this->tokenFrom('/team/positions');
        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/permissions', [
            '_token' => $token,
            'permissions' => ['area.view', 'team.manage'],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame(['area.view', 'team.manage'], $stored->getPermissionValues());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('reaches 1 person', $crawler->html());
        unset($naomi);
    }

    /** A MODULE-DECLARED PERMISSION ROUND-TRIPS — the bug the old setter had. */
    public function testAModuleDeclaredPermissionRoundTripsThroughTheSave(): void
    {
        $this->administrator();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), []);
        $this->em->flush();

        $token = $this->tokenFrom('/team/positions');
        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/permissions', [
            '_token' => $token,
            'permissions' => ['surveys.record'],
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame(['surveys.record'], $stored->getPermissionValues(), 'A module\'s permission is not silently dropped.');
    }

    /**
     * PRUNE, NOT PURGE, THROUGH THE WHOLE ROUND TRIP: an orphan is drawn muted,
     * posted back by the form, and survives a save that adds something else.
     */
    public function testAnOrphanedGrantIsDrawnAndSurvivesASave(): void
    {
        $this->administrator();
        $botanist = (new Position())->setName('Botanist')->setDepartment($this->department('Ecology'));
        // How it got there: the module was installed at the time.
        $botanist->setPermissionValues(['vegetation.survey'], ['vegetation.survey']);
        $this->em->persist($botanist);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions?position='.$botanist->getUuidString());

        self::assertStringContainsString('no longer described', $crawler->html());
        self::assertStringContainsString('vegetation.survey', $crawler->html());
        self::assertStringContainsString('no installed module provides it', $crawler->html());

        // The form posts it back, so an unrelated save keeps it.
        $token = $this->tokenFrom('/team/positions?position='.$botanist->getUuidString());
        $this->client->request('POST', '/team/positions/'.$botanist->getUuidString().'/permissions', [
            '_token' => $token,
            'permissions' => ['vegetation.survey', 'area.view'],
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Botanist']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertContains('vegetation.survey', $stored->getPermissionValues());
        self::assertContains('area.view', $stored->getPermissionValues());
    }

    /** And it can still be taken away: it is a grant, not a fixture. */
    public function testAnOrphanedGrantCanBeRevoked(): void
    {
        $this->administrator();
        $botanist = (new Position())->setName('Botanist')->setDepartment($this->department('Ecology'));
        $botanist->setPermissionValues(['vegetation.survey'], ['vegetation.survey']);
        $this->em->persist($botanist);
        $this->em->flush();

        $token = $this->tokenFrom('/team/positions?position='.$botanist->getUuidString());
        $this->client->request('POST', '/team/positions/'.$botanist->getUuidString().'/permissions', [
            '_token' => $token,
            'permissions' => [],
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Botanist']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame([], $stored->getPermissionValues());
    }

    /**
     * TWO DEPARTMENTS MAY EACH OWN AN "ANALYST", and creating the second is not
     * an error. The message when a name really IS taken names the department,
     * because the same word elsewhere is fine.
     */
    public function testTheSameNameInTwoDepartmentsIsTwoPositions(): void
    {
        $this->administrator();
        $ecology = $this->department('Ecology');
        $protection = $this->department('Protection Service');
        $this->em->flush();

        $token = $this->tokenFrom('/team/positions');
        foreach ([$ecology, $protection] as $department) {
            $this->client->request('POST', '/team/positions', [
                '_token' => $token,
                'department' => $department->getUuidString(),
                'name' => 'Analyst',
            ]);
        }

        $this->em->clear();
        self::assertCount(2, $this->em->getRepository(Position::class)->findBy(['name' => 'Analyst']));
    }

    public function testTheSameNameTwiceInOneDepartmentIsRefusedWithTheDepartmentNamed(): void
    {
        $this->administrator();
        $ecology = $this->department('Ecology');
        $this->em->flush();

        $token = $this->tokenFrom('/team/positions');
        $this->client->request('POST', '/team/positions', [
            '_token' => $token, 'department' => $ecology->getUuidString(), 'name' => 'Analyst',
        ]);
        $this->client->request('POST', '/team/positions', [
            '_token' => $token, 'department' => $ecology->getUuidString(), 'name' => 'Analyst',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Ecology already has a position called', $crawler->html());
    }

    /**
     * BOTH WIDGET LIBRARIES RENDER. Asserted because they were the one pair of
     * routes no other test opened, and a real install found them broken: the
     * library's action URLs carry a placeholder uuid, and Requirement::UUID
     * refuses a nil one, so the router threw at RENDER time and took the whole
     * page down. A route that is never requested is a route that is untested.
     */
    public function testBothWidgetLibrariesRender(): void
    {
        $this->administrator();

        foreach (['/team/widgets', '/team/positions/widgets'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url.' does not render.');
        }
    }

    /** A write with no token is refused rather than performed. */
    public function testASaveWithoutACsrfTokenIsRefused(): void
    {
        $this->administrator();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), []);
        $this->em->flush();

        $this->client->request('POST', '/team/positions/'.$ranger->getUuidString().'/permissions', [
            'permissions' => ['area.view'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
