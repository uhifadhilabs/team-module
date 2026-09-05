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
    public function testBothRowsAreInTheSidebarForSomebodyWhoMayAdministerTheTeam(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Organization'],
            $crawler->filter('nav.nav .nav-hd')->each(static fn ($node): string => $node->text()),
        );

        $rows = $crawler->filter('nav.nav a.nav-item');
        self::assertCount(2, $rows);
        self::assertSame(
            ['/departments', '/team'],
            $rows->each(static fn ($node): string => (string) $node->attr('href')),
        );
        self::assertSame(
            ['Departments', 'Team'],
            $rows->each(static fn ($node): string => $node->filter('span')->text()),
        );
    }

    /**
     * NOT LIT FROM SOMEWHERE ELSE. "Where am I" is the sidebar's whole job, and
     * a row lit on a page it does not lead to answers it wrongly.
     */
    public function testNeitherRowIsLitOnAPageThatIsNotThisModules(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/_elsewhere');

        self::assertSame(
            ['nav-item', 'nav-item'],
            $crawler->filter('nav.nav a.nav-item')->each(static fn ($node): string => (string) $node->attr('class')),
        );
    }

    public function testTheRowIsLitOnTheRoster(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/team');

        self::assertResponseIsSuccessful();
        self::assertSame(['nav-item', 'nav-item on'], $this->rowClasses($crawler));
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
        self::assertSame(['nav-item', 'nav-item on'], $this->rowClasses($crawler));
    }

    /**
     * AND DEPARTMENTS IS A SECOND PLACE, not a screen inside the roster — which
     * is the whole reason it has a top-level address. Standing on it lights its
     * own row and leaves Team alone; a `/team/departments` would have lit both,
     * because "am I here" is decided by path prefix.
     */
    public function testTheDepartmentsRowIsLitOnItsOwnScreenAndTheRosterRowIsNot(): void
    {
        $this->administrator();

        $crawler = $this->client->request('GET', '/departments');

        self::assertResponseIsSuccessful();
        self::assertSame(['nav-item on', 'nav-item'], $this->rowClasses($crawler));
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
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Organization', $body);
        self::assertStringNotContainsString('Departments', $body);
    }

    /**
     * AND A STRANGER NEVER GETS AS FAR AS A SIDEBAR. Under the documented
     * posture the front door is closed: everything that is not the sign-in
     * screen or one of the recovery paths sends an anonymous visitor to
     * `/login`, so the question "what does a stranger see in the nav" has one
     * answer and it is "the sign-in card". The row cannot leak from a page a
     * stranger cannot reach.
     */
    public function testAnAnonymousVisitorNeverReachesAPageWithASidebar(): void
    {
        $this->client->request('GET', '/_elsewhere');

        self::assertResponseRedirects('http://localhost/login');
    }

    /** @return list<string> */
    private function rowClasses(\Symfony\Component\DomCrawler\Crawler $crawler): array
    {
        return $crawler->filter('nav.nav a.nav-item')->each(static fn ($node): string => (string) $node->attr('class'));
    }

    /** The sign-in screen still shows no navigation, deliberately. */
    public function testTheSignInScreenHasNoSidebar(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.nav'));
    }
}
