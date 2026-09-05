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

namespace Uhifadhi\Team\Tests\Unit\Shell;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Shell\Model\NavSection;
use Uhifadhi\Team\Shell\TeamNavigation;

/**
 * THE TWO ANSWERS THAT ARE HARD TO STAGE IN A BROWSER — an installation that
 * unmounted this module's routes, and a render with no security context at all.
 *
 * Both are the same kind of failure and it is the worst kind a nav source has:
 * an exception thrown while building the sidebar takes down EVERY page in the
 * installation, including the ones that had nothing to do with this module. So
 * they are asserted here rather than reasoned about.
 *
 * What a viewer actually sees is asserted where it belongs, against real markup
 * a browser received — see tests/Functional/SidebarRowTest.
 */
#[CoversClass(TeamNavigation::class)]
final class TeamNavigationTest extends TestCase
{
    /**
     * THE ADDRESSES BELONG TO THE APPLICATION. The recipe's
     * config/routes/team.yaml is the installation's file and it may edit or
     * delete it; when it does, this module loses its screens — and losing your
     * screens must not mean losing everybody's.
     */
    public function testAnInstallationThatUnmountedTheRoutesGetsNoRowRatherThanAnError(): void
    {
        $navigation = new TeamNavigation(
            $this->urlsThatCannotGenerate(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            new RequestStack(),
        );

        self::assertSame([], iterator_to_array($navigation->sections()));
    }

    /**
     * AND UNMOUNTING IS PER-ADDRESS, which is why the rows are generated one at
     * a time. An installation that kept the roster and dropped the departments
     * screen loses ONE row; the section it belongs to survives with what is
     * still reachable. The whole-section answer above is what happens when
     * nothing is left, not what happens when something is missing.
     */
    public function testUnmountingOneScreenTakesOneRowAndLeavesTheOther(): void
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route): string => TeamNavigation::ROUTE === $route
                ? '/team'
                : throw new RouteNotFoundException(),
        );

        $navigation = new TeamNavigation(
            $urls,
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            new RequestStack(),
        );

        $sections = iterator_to_array($navigation->sections());
        self::assertCount(1, $sections);

        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);
        self::assertSame(['Team'], array_map(static fn ($item): string => $item->label, $section->items));
    }

    /**
     * NO TOKEN, NO QUESTION. Asking the authorization checker with no token
     * throws rather than answering false, and a shell renders in places a
     * firewall does not reach.
     */
    public function testAViewerNobodyCanIdentifyGetsNoRowRatherThanAnError(): void
    {
        $navigation = new TeamNavigation(
            $this->urlsAnsweringTeam(),
            new TokenStorage(),
            $this->checkerThatRefusesToBeAsked(),
            new RequestStack(),
        );

        self::assertSame([], iterator_to_array($navigation->sections()));
    }

    /**
     * AND THE ROWS THEMSELVES ARE VALUES, not renderings — the section's
     * heading, its declared position and the ORDER of what is in it are part of
     * what this module promises the shell, so they are stated somewhere that
     * fails when they change.
     *
     * DEPARTMENTS COMES FIRST, and that is the drawing rather than a
     * preference: the old application's sidebar puts Departments above Team
     * under Organization, because the org chart is the thing the roster is read
     * against.
     */
    public function testTheTwoRowsAreOneSectionAtTheDeclaredPosition(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/somewhere-else'));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
        );

        $sections = iterator_to_array($navigation->sections());
        self::assertCount(1, $sections);

        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);
        self::assertSame('Organization', $section->label);
        self::assertSame(20, $section->position);
        self::assertCount(2, $section->items);

        self::assertSame('Departments', $section->items[0]->label);
        self::assertSame('/departments', $section->items[0]->url);
        self::assertSame('lucide:building-2', $section->items[0]->icon);
        self::assertFalse($section->items[0]->current);

        self::assertSame('Team', $section->items[1]->label);
        self::assertSame('/team', $section->items[1]->url);
        self::assertSame('lucide:users', $section->items[1]->icon);
        self::assertFalse($section->items[1]->current);
    }

    /**
     * THE TWO ROWS CANNOT LIGHT EACH OTHER. This is the reason departments is
     * addressed at `/departments` rather than under the roster: "am I here" is
     * decided by path prefix, so a `/team/departments` would have lit the Team
     * row on the departments screen and the sidebar would have answered "where
     * am I" with two places at once.
     */
    public function testStandingOnDepartmentsLightsDepartmentsAndNotTheRoster(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/departments'));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringByRoute(),
            $this->tokenStorageWithAToken(),
            $this->checkerAnswering(true),
            $requests,
        );

        $sections = iterator_to_array($navigation->sections());
        $section = $sections[0];
        self::assertInstanceOf(NavSection::class, $section);

        self::assertTrue($section->items[0]->current, 'Departments is not lit on the departments screen.');
        self::assertFalse($section->items[1]->current, 'The roster row is lit on a screen it does not lead to.');
    }

    private function urlsAnsweringByRoute(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route): string => TeamNavigation::ROUTE === $route ? '/team' : '/departments',
        );

        return $urls;
    }

    private function urlsAnsweringTeam(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/team');

        return $urls;
    }

    private function urlsThatCannotGenerate(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willThrowException(new RouteNotFoundException());

        return $urls;
    }

    private function tokenStorageWithAToken(): TokenStorageInterface
    {
        $storage = new TokenStorage();
        $storage->setToken($this->createStub(TokenInterface::class));

        return $storage;
    }

    private function checkerAnswering(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn($granted);

        return $checker;
    }

    private function checkerThatRefusesToBeAsked(): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::never())->method('isGranted');

        return $checker;
    }
}
