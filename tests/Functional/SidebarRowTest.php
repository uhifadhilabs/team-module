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

use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;

/**
 * THE ROW IN THE SIDEBAR — asserted as markup a browser received, because that
 * is where the defect was.
 *
 * A module with screens and no row is a module reachable only by somebody who
 * already knows the URL, which is the same as not shipping it. The platform's
 * one sentence is "a module registers with the seam and renders in the shell",
 * and the second half of it is this file.
 *
 * THE INTERESTING QUESTIONS ARE ASKED FROM OFF THE PAGE. Whether the row is
 * there at all is only half of it; the other half is who sees it, and a suite
 * that only ever asked from /team would have to sign in as somebody who can
 * reach /team before it could ask. So most of these requests go to
 * `/_elsewhere` — a page in the shell's frame that is nobody's module (see
 * ShellPageController) — which is the position a real viewer is in.
 *
 * GATING IS THE SOURCE'S JOB, and the shell says so: a row a viewer may not
 * have is ABSENT, never hidden, because a hidden row leaks its existence to
 * whoever reads the HTML. So the assertions below are about the string not
 * being in the response at all.
 */
final class SidebarRowTest extends WebTestCaseWithSchema
{
    public function testTheTeamRowIsInTheSidebarForSomebodyWhoMayAdministerTheTeam(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Organization'],
            $crawler->filter('nav.nav .nav-hd')->each(static fn ($node): string => $node->text()),
        );

        $row = $crawler->filter('nav.nav a.nav-item');
        self::assertCount(1, $row);
        self::assertSame('/team', $row->attr('href'));
        self::assertSame('Team', $row->filter('span')->text());
    }

    /**
     * NOT LIT FROM SOMEWHERE ELSE. "Where am I" is the sidebar's whole job, and
     * a row lit on a page it does not lead to answers it wrongly.
     */
    public function testTheRowIsUnlitOnAPageThatIsNotThisModules(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertStringNotContainsString('on', (string) $crawler->filter('nav.nav a.nav-item')->attr('class'));
    }

    public function testTheRowIsLitOnTheRoster(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame('nav-item on', $crawler->filter('nav.nav a.nav-item')->attr('class'));
    }

    /**
     * AND LIT ON THE MATRIX TOO, which is one row for two screens on purpose:
     * the design draws Team as a single flat row, and /team/positions is a
     * screen INSIDE it rather than a second place in the product.
     */
    public function testTheRowIsLitOnThePermissionMatrix(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/team/positions');

        self::assertResponseIsSuccessful();
        self::assertSame('nav-item on', $crawler->filter('nav.nav a.nav-item')->attr('class'));
    }

    /**
     * A COLLEAGUE WITHOUT team.manage GETS NO ROW — and gets no mention of one.
     * The gate on the row is the same permission as the gate on the screen, so
     * the sidebar cannot offer a door that closes in somebody's face.
     */
    public function testAColleagueWithoutTeamManageSeesNoRow(): void
    {
        $ranger = $this->person('Juma', 'Mwakalinga', TeamRoleEnum::Staff);
        $ranger->setPosition($this->position('Ranger', $this->department('Protection'), [PermissionEnum::AreaView->value]));
        $this->em->flush();
        $this->client->loginUser($ranger);

        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.nav a.nav-item'));
        self::assertStringNotContainsString('Organization', (string) $this->client->getResponse()->getContent());
    }

    /**
     * AND A STRANGER SEES NOTHING AT ALL. The nav is read live per render and
     * there is no viewer to ask, so there is nothing to draw — which is also
     * why the sign-in card, which renders on the document rung with no sidebar
     * at all, was never the place this leaked.
     */
    public function testAnAnonymousVisitorSeesNoRow(): void
    {
        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.nav a.nav-item'));
        self::assertStringNotContainsString('Organization', (string) $this->client->getResponse()->getContent());
    }

    /** The sign-in screen still shows no navigation, deliberately. */
    public function testTheSignInScreenHasNoSidebar(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.nav'));
    }
}
