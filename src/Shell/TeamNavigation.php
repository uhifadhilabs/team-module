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

namespace Uhifadhi\Team\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Uhifadhi\Shell\Contract\NavigationSourceInterface;
use Uhifadhi\Shell\Model\NavItem;
use Uhifadhi\Shell\Model\NavSection;
use Uhifadhi\Team\Enum\PermissionEnum;

/**
 * THE ONE ROW THIS MODULE PUTS IN THE SIDEBAR.
 *
 * Team is the platform-wide row the shell's nav seam is documented to expect
 * from a module: "the rare platform-wide row that belongs to nobody's area". It
 * is deliberately not the other thing a module can be — a per-area capability
 * registered with the seam through `ModuleProviderInterface` — because that
 * contract is per-area by construction (the seam's ledger is an area-by-module
 * table) and an installation's people are not an area's. A team that had to be
 * switched on per area would be a roster that existed four times.
 *
 * TWO ROWS, AND THE SECOND ONE IS NOT A SCREEN INSIDE THE FIRST. /team and
 * /team/positions are one place in the product, so the matrix lights the Team
 * row rather than adding a third; anything below that is the page's own
 * business, not the sidebar's. Departments is different in kind and the drawing
 * says so — the old application's sidebar puts Departments and Team side by
 * side under Organization, in that order, because a department is an org-wide
 * fact the roster reads rather than a corner of the roster. It has its own
 * top-level address for the same reason.
 *
 * ONE SECTION, THOUGH. Both rows are Organization, and a module contributing
 * two sections for two screens would be a module deciding the shape of somebody
 * else's sidebar.
 *
 * GATING IS THIS CLASS'S JOB, not the shell's — the shell holds no
 * authorization service and asks nothing about the viewer. So the row is
 * ABSENT, never hidden, for anybody without `team.manage`, which is the exact
 * permission the screens behind it are gated on. A row that offered a door
 * closing in somebody's face would be worse than no row.
 *
 * ROUTE-TOLERANT. The addresses are mounted by the APPLICATION (the recipe's
 * config/routes/team.yaml, which an installation may edit or delete), so
 * generating one can fail — and a sidebar that took every page down because
 * somebody unmounted a route would be the worst possible way to learn it. No
 * route, no row.
 *
 * BUILT PER CALL, NEVER CACHED, and nothing is done in the constructor: the
 * shell reads its sources live on every render precisely so a permission
 * revoked this morning is gone from the sidebar this morning.
 */
final readonly class TeamNavigation implements NavigationSourceInterface
{
    /** The heading the row files under, as the design draws it. */
    public const string SECTION = 'Organization';

    /**
     * Between an installation's own Observatory rows and its System ones. A
     * declared position rather than a hope about container compilation order,
     * which is what the field is for.
     */
    public const int POSITION = 20;

    /** The roster: this module's front door, and the row's destination. */
    public const string ROUTE = 'team_index';

    /** The org chart's home — org-wide, and addressed as such. */
    public const string DEPARTMENTS_ROUTE = 'team_departments';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TokenStorageInterface $tokens,
        private AuthorizationCheckerInterface $authorization,
        private RequestStack $requests,
    ) {
    }

    public function sections(): iterable
    {
        /*
         * NO TOKEN, NO QUESTION. A page can render outside any firewall — an
         * error page, a console-rendered template — and asking the authorization
         * checker there throws rather than answering false. A viewer nobody can
         * identify holds nothing, which is the same answer without the 500.
         */
        if (null === $this->tokens->getToken()) {
            return;
        }

        if (!$this->authorization->isGranted(PermissionEnum::TeamManage->value)) {
            return;
        }

        /*
         * ROW BY ROW, because unmounting is per-address. An installation that
         * kept the roster and dropped the departments screen must lose one row
         * rather than both — and a section with nothing left in it is not a
         * section, so an empty list yields nothing at all.
         */
        $items = array_values(array_filter([
            $this->row('Departments', self::DEPARTMENTS_ROUTE, 'lucide:building-2'),
            $this->row('Team', self::ROUTE, 'lucide:users'),
        ]));

        if ([] === $items) {
            return;
        }

        yield new NavSection(self::SECTION, $items, position: self::POSITION);
    }

    /**
     * ONE ROW, OR NOTHING WHERE ITS ADDRESS IS GONE.
     *
     * ROUTE-TOLERANT, as the class comment says: the addresses are mounted by
     * the APPLICATION, so generating one can fail, and a sidebar that took every
     * page down because somebody unmounted a route would be the worst possible
     * way to learn it.
     */
    private function row(string $label, string $route, string $icon): ?NavItem
    {
        try {
            $url = $this->urls->generate($route);
        } catch (RouteNotFoundException) {
            return null;
        }

        return new NavItem(
            label: $label,
            url: $url,
            icon: $icon,
            current: $this->viewerIsHere($url),
        );
    }

    /**
     * WHETHER THE VIEWER IS ON THIS ROW'S SCREEN, or on one underneath it.
     *
     * Compared as PATHS rather than route names, because the addresses belong to
     * the application: it may mount this module under a prefix, and a list of
     * route names typed out here would go stale the first time a screen was
     * added. The generated url carries the base url when the installation lives
     * in a subdirectory, so the request's is put back on before comparing.
     */
    private function viewerIsHere(string $url): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        $here = $request->getBaseUrl().$request->getPathInfo();

        return $here === $url || str_starts_with($here, rtrim($url, '/').'/');
    }
}
