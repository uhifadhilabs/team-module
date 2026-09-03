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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Exception\UnknownPermissionException;
use Uhifadhi\Team\Repository\DepartmentRepository;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Service\PermissionCatalogue;
use Uhifadhi\Team\Widget\PositionWidgets;
use Uhifadhi\Widget\Service\WidgetService;

/**
 * POSITIONS AND PERMISSIONS — the heart of this module.
 *
 * A POSITION IS THE ONLY THING THAT GRANTS A STAFF MEMBER ANY CAPABILITY AT
 * ALL, and this is where one is composed. It belongs to a department and its
 * name is unique only inside it, so nothing on this page ever writes a bare
 * name: *Ecology / Analyst* and *Protection Service / Analyst* are two
 * different jobs that share a word.
 *
 * ADMINISTERING THE TEAM IS ITSELF ONE OF THE ROWS. `team.manage`, the seventh
 * core case — which is why the page that grants it is gated on it.
 *
 * THE LIST OF PERMISSIONS IS NOT FIXED, and every direction has to make that
 * visible. Seven are this module's and will always be there; the rest arrive
 * when a bundle is installed and leave when it is removed. So the page hands
 * every rendering the same three honest states: an installed module that
 * declares nothing, a value whose module is not installed here, and an ORPHANED
 * GRANT — still held by a position, provided by nothing, drawn muted. The
 * difference between "you no longer have this" and "you cannot see that you
 * still have this" is the whole of the prune-not-purge ruling.
 */
final readonly class PositionController
{
    public const string CSRF_ID = 'team_position';

    public function __construct(
        private Environment $twig,
        private PositionRepository $positions,
        private DepartmentRepository $departments,
        private UserRepository $users,
        private PermissionCatalogue $catalogue,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
        private WidgetService $widgets,
    ) {
    }

    #[Route('/team/positions', name: 'team_positions', methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function index(Request $request): Response
    {
        $catalog = new PositionWidgets()->catalog();

        return new Response($this->twig->render('@UhifadhiTeam/positions/index.html.twig', [
            'widgets' => $this->widgets->resolve($catalog, $this->signedIn()),
            ...$this->widgetContext($request),
        ]));
    }

    /**
     * CREATING ONE IS TWO FIELDS, AND THE DEPARTMENT IS THE FIRST OF THEM. The
     * name is unique inside that department and nowhere else.
     */
    #[Route('/team/positions', name: 'team_position_create', methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A position needs a name.', 'error');
        }

        $department = $this->department(trim((string) $request->request->get('department')));

        $position = (new Position())->setName($name)->setDepartment($department);
        $this->entityManager->persist($position);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The index would have said this in SQL. The person who typed the
            // name wants the sentence — and the sentence has to name the
            // DEPARTMENT, because the same word in another one is fine.
            return $this->back($request, \sprintf(
                '%s already has a position called “%s”. A name is unique inside its department and nowhere else, so the same word in another department is fine.',
                $department?->getName() ?? 'The unassigned group',
                $name,
            ), 'error');
        }

        return $this->back($request, \sprintf('“%s” exists. It grants nothing until you tick something.', $position->getQualifiedName()));
    }

    /**
     * THE MATRIX SAVE. Every ticked box, as a value string, through the
     * position's one validated write path.
     *
     * THE FORM POSTS ONLY WHAT IS TICKED, so what is absent is what was
     * revoked — except for the orphans, which are posted back as hidden fields
     * by the template precisely so that a save that does not touch them keeps
     * them. Editing a position is not a migration.
     */
    #[Route('/team/positions/{uuid}/permissions', name: 'team_position_permissions', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function permissions(Request $request, string $uuid): Response
    {
        $position = $this->position($uuid);
        $this->assertCsrf($request);

        /** @var list<string> $granted */
        $granted = array_values(array_filter(
            array_map(
                static fn (mixed $v): string => \is_string($v) ? $v : '',
                (array) $request->request->all('permissions'),
            ),
            static fn (string $v): bool => '' !== $v,
        ));

        try {
            $position->setPermissionValues($granted, $this->catalogue->values());
        } catch (UnknownPermissionException $refusal) {
            return $this->back($request, $refusal->getMessage(), 'error', $position);
        }

        $this->entityManager->flush();

        $reaches = $this->users->countActiveHoldingAnyPosition([$position]);

        return $this->back($request, \sprintf(
            '“%s” now holds %d permission%s, and the change reaches %d %s.',
            $position->getQualifiedName(),
            \count($granted),
            1 === \count($granted) ? '' : 's',
            $reaches,
            1 === $reaches ? 'person' : 'people',
        ), 'success', $position);
    }

    #[Route('/team/positions/{uuid}/rename', name: 'team_position_rename', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function rename(Request $request, string $uuid): Response
    {
        $position = $this->position($uuid);
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A position needs a name.', 'error', $position);
        }

        $position->setName($name);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->back($request, \sprintf('That department already has a position called “%s”.', $name), 'error');
        }

        return $this->back($request, 'Renamed.', 'success', $position);
    }

    /**
     * EVERY FACT ANY OF THE THIRTEEN WIDGETS MIGHT WANT, gathered once — the
     * same context the widget library previews on, so what somebody arranges
     * there is exactly what they get here.
     *
     * @return array<string, mixed>
     */
    public function widgetContext(Request $request): array
    {
        $positions = $this->positions->findAllOrdered();

        // WHICH POSITION THE CHECKLIST IS SHOWING. Direction B edits exactly one
        // at a time and says which; the id is in the URL, so the choice is
        // shareable and survives the save's redirect.
        $selectedUuid = trim((string) $request->query->get('position'));
        $selected = '' !== $selectedUuid && Uuid::isValid($selectedUuid)
            ? $this->positions->findOneByUuid(Uuid::fromString($selectedUuid))
            : null;
        $selected ??= $positions[0] ?? null;

        $holders = [];
        foreach ($positions as $position) {
            $holders[$position->getUuidString() ?? ''] = $this->users->countActiveHoldingAnyPosition([$position]);
        }

        return [
            'catalogue' => $this->catalogue->all(),
            'grouped' => $this->catalogue->groupedByUmbrella(),
            'silentModules' => $this->catalogue->silentModules(),
            'moduleNames' => $this->catalogue->moduleNames(),
            'positions' => $positions,
            'groupedPositions' => $this->positions->findAllGroupedByDepartment(),
            'departments' => $this->departments->findAllOrdered(),
            'selected' => $selected,
            'holders' => $holders,
            'orphans' => $this->orphans($positions),
            'people' => $this->users->findAllByName(),
            'soleSuperAdmin' => 1 === $this->users->countActiveSuperAdmins(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ];
    }

    /**
     * GRANTS NO INSTALLED MODULE PROVIDES ANY MORE, and which positions still
     * hold them.
     *
     * They stay in the JSON and stop resolving — pruned, not purged — because
     * removing them on the module's way out would silently rewrite what an
     * administrator granted. Drawing them muted is how somebody finds out.
     *
     * @param list<Position> $positions
     *
     * @return array<string, list<Position>> value => the positions still holding it
     */
    private function orphans(array $positions): array
    {
        $known = $this->catalogue->values();
        $orphans = [];

        foreach ($positions as $position) {
            foreach ($position->getPermissionValues() as $value) {
                if (!\in_array($value, $known, true)) {
                    $orphans[$value][] = $position;
                }
            }
        }

        return $orphans;
    }

    private function department(string $uuid): ?Department
    {
        if ('' === $uuid || !Uuid::isValid($uuid)) {
            // NULLABLE, AND THE NULL IS A STATE. A position created before
            // anybody decided which department owns it is a position that
            // exists, and its holders show in the roster's Unassigned band.
            return null;
        }

        return $this->departments->findOneByUuid(Uuid::fromString($uuid));
    }

    private function position(string $uuid): Position
    {
        return $this->positions->findOneByUuid(Uuid::fromString($uuid))
            ?? throw new NotFoundHttpException('No such position on this installation.');
    }

    private function signedIn(): ?ModuleUserInterface
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof ModuleUserInterface ? $user : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    private function back(Request $request, string $message, string $kind = 'success', ?Position $position = null): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }

        // Back to the position that was being edited, so the checklist is still
        // showing what the sentence is about.
        return new RedirectResponse($this->router->generate(
            'team_positions',
            null !== $position ? ['position' => $position->getUuidString()] : [],
        ));
    }
}
