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

namespace Uhifadhi\Team\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\RosterStateEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Model\RosterQuery;
use Uhifadhi\Team\Repository\DepartmentRepository;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Service\TeamOverview;
use Uhifadhi\Team\Widget\TeamWidgets;
use Uhifadhi\Widget\Service\WidgetService;

/**
 * THE TEAM PAGE — everybody who can sign in to this installation.
 *
 * GATED ON `team.manage`, not on a tier. That is the whole of what retiring the
 * Manager tier bought: the question "may this person administer the team" is
 * asked of the permission catalogue, so a Staff member whose position carries
 * the row gets in and a tier nobody granted does not decide it. Super Admin and
 * Admin pass the same check, because the voter grants them everything by tier.
 *
 * IT IS A WIDGET SURFACE. The body is the person's own resolved layout, so this
 * controller's job is to gather every fact ANY of the nine widgets might want
 * and hand them over — the widget framework decides which are drawn. That is
 * why the roster is fetched twice in different shapes: the table direction is
 * PAGED and the five banded ones are not, because a band cut in half by a pager
 * is a band that lies about its own count.
 *
 * A PLAIN CLASS, extending nothing, with its collaborators handed to it — the
 * reusable-bundle rule (see config/services.php).
 */
final readonly class TeamController
{
    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private PositionRepository $positions,
        private DepartmentRepository $departments,
        private TeamOverview $overview,
        private WidgetService $widgets,
        private TokenStorageInterface $tokens,
    ) {
    }

    #[Route('/team', name: 'team_index', methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function index(Request $request): Response
    {
        $catalog = new TeamWidgets()->catalog();

        return new Response($this->twig->render('@UhifadhiTeam/team/index.html.twig', [
            'widgets' => $this->widgets->resolve($catalog, $this->signedIn()),
            ...$this->widgetContext($request),
        ]));
    }

    /**
     * EVERY FACT ANY OF THE NINE WIDGETS MIGHT WANT, gathered once.
     *
     * PUBLIC, because the widget library renders the same nine partials on the
     * same real data — the picture of a widget IS the widget, so what somebody
     * arranges there is exactly what they get here. A second, thinner context
     * for the preview would be the one place the two screens could disagree.
     *
     * A widget cannot know which of its siblings are on, so it cannot fetch for
     * itself without the page issuing the same query five times — and five
     * independently-fetched copies of one roster can disagree, which on a page
     * about who may do what is the one failure worth spending a join to avoid.
     *
     * @return array<string, mixed>
     */
    public function widgetContext(Request $request): array
    {
        $query = RosterQuery::fromRequest($request);

        return [
            'query' => $query,
            // The paged shape, for the table direction: its whole state is the URL.
            'page' => $this->users->findRoster($query),
            // The unpaged shape, for the five banded directions. A band cut in
            // half by a pager is a band that lies about its own count.
            'everybody' => $this->users->findAllByName(),
            'overview' => $this->overview->build(),
            'tierCounts' => $this->users->countByTier(),
            'tiers' => TeamRoleEnum::cases(),
            'states' => RosterStateEnum::cases(),
            'departments' => $this->departments->findAllOrdered(),
            'groupedPositions' => $this->positions->findAllGroupedByDepartment(),
            'teamManage' => PermissionEnum::TeamManage,
        ];
    }

    /**
     * The signed-in person as the CONTRACT sees them, which is what the widget
     * framework stores a layout against — it never type-hints this bundle's
     * User, and this call site is not where that would start.
     *
     * Null is a real answer rather than a guard: the framework hands an
     * anonymous request the catalogue's defaults, which is exactly right for a
     * page nobody is signed in to. That this route is gated makes it unreachable
     * in practice, and relying on a gate to make a null impossible is how a
     * later change to the gate becomes a 500 here.
     */
    private function signedIn(): ?ModuleUserInterface
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof ModuleUserInterface ? $user : null;
    }
}
