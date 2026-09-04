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
     * AND THE ROW ITSELF IS A VALUE, not a rendering — the section's heading and
     * declared position are part of what this module promises the shell, so they
     * are stated somewhere that fails when they change.
     */
    public function testTheRowIsOneSectionAtTheDeclaredPosition(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/somewhere-else'));

        $navigation = new TeamNavigation(
            $this->urlsAnsweringTeam(),
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
        self::assertCount(1, $section->items);
        self::assertSame('Team', $section->items[0]->label);
        self::assertSame('/team', $section->items[0]->url);
        self::assertSame('lucide:users', $section->items[0]->icon);
        self::assertFalse($section->items[0]->current);
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
