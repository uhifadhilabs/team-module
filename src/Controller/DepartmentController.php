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
use Uhifadhi\ModuleContracts\Entity\AreaInterface;
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Exception\MissingScopeChangeReasonException;
use Uhifadhi\Team\Repository\DepartmentRepository;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;

/**
 * THE ORG CHART'S HOME — the area-aware department manager, the per-department
 * lens each row opens to, and the writes that shape them.
 *
 * A DEPARTMENT CARRIES A SCOPE NOW, and the manager is built around it. Every
 * department is either AREA-LEVEL — confined to one area, named in the scope
 * column and grouped under that area's heading — or ORG-LEVEL, spanning every
 * area. Area-level come first, grouped by their area's name; org-level after.
 * The scope is derived from the nullable area on the entity ({@see Department}),
 * never stored twice, so the manager only ever reads it.
 *
 * THE AREA IS REACHED THROUGH THE CONTRACT, NEVER AN AREA PACKAGE. The pickers
 * (create, and confine-to-area) enumerate the installation's areas by asking the
 * ORM for the entity the platform's {@see AreaInterface} resolves to — the class
 * whichever area package (uhifadhi/area-module) named in
 * `doctrine.orm.resolve_target_entities`. This module points at an area exactly
 * as it points at a person, and requires neither package to do it.
 *
 * A DEPARTMENT GRANTS NOTHING, scope or no scope. Filing a position into a
 * department changes where it is READ; confining a department to an area changes
 * where it is read; neither changes what anybody may do. Capability still
 * arrives through a position's permissions, composed one screen over. The scope
 * WILL become authority once the area-aware voter is wired
 * (docs/area-scoped-authority.md), but not in this module and not today, which is
 * why `team.manage` — "administer this installation's people" — is the honest
 * gate on every route here rather than an invented per-area permission.
 *
 * CHANGING A SCOPE IS AUDITED, both directions. Confining an org-wide department
 * to one area, or promoting an area-level one to org-wide, goes through
 * {@see Department::changeScopeTo()} — the one door that refuses a change with no
 * reason and appends a {@see \Uhifadhi\Team\Entity\DepartmentScopeChange}. The
 * controller only supplies who and why; the entity records the transition.
 *
 * A DEPARTMENT DEACTIVATES, IT NEVER DELETES (the standing fleet rule). Winding
 * one down flips its active flag; the register draws it greyed, the pickers drop
 * it, and its scope history and filed positions are untouched. The footprint —
 * how many positions and people it touches — is stated on the act and INFORMS,
 * never guards; {@see reactivate()} brings it back.
 *
 * WHAT IS DELIBERATELY NOT HERE YET. The rich lens surface — a department's
 * widget board, its attached modules and their rolled-up KPIs — is the canonical
 * detail page's follow-up: the Overview and Settings tabs draw the DRAWN empty
 * states for it and nothing is invented behind them.
 */
final readonly class DepartmentController
{
    public const string CSRF_ID = 'team_department';

    public function __construct(
        private Environment $twig,
        private DepartmentRepository $departments,
        private PositionRepository $positions,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
    ) {
    }

    /**
     * THE ADDRESS IS `/departments` AND NOT `/team/departments`, which the old
     * application's URL space and the drawn sidebar agree on: Departments is a
     * sibling of Team under Organization, not a screen inside the roster.
     */
    #[Route('/departments', name: 'team_departments', methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function index(): Response
    {
        $departments = $this->departments->findAllOrdered();

        /*
         * THE POSITIONS COME FROM ONE QUERY, not from walking each department's
         * inverse collection — that is a lazy load per card, and the inverse of a
         * OneToMany is only as true as whoever maintained it. Reading the owning
         * side (Position::getDepartment) is reading the fact.
         */
        $owned = [];
        $loose = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $department = $position->getDepartment();
            if (null === $department) {
                $loose[] = $position;
                continue;
            }
            $owned[$department->getUuidString() ?? ''][] = $position;
        }

        // HEADCOUNT IS REACHED THROUGH THE POSITIONS. A department holds nobody
        // directly, and a count that pretended otherwise would be the first place
        // this page lied about the model.
        $headcount = [];
        foreach ($departments as $department) {
            $key = $department->getUuidString() ?? '';
            $headcount[$key] = $this->users->countActiveHoldingAnyPosition($owned[$key] ?? []);
        }

        return new Response($this->twig->render('@UhifadhiTeam/departments/index.html.twig', [
            'areaGroups' => $this->areaGroups(),
            'orgDepartments' => $this->departments->findOrgLevelOrdered(),
            'departments' => $departments,
            'areas' => $this->areas(),
            // The move control and the confine picker file INTO a department, so
            // they offer only the active ones; the register above draws the
            // inactive rows greyed from the all-inclusive groups.
            'fileTargets' => $this->departments->findAllActiveOrdered(),
            'owned' => $owned,
            'headcount' => $headcount,
            'holders' => $this->holders(),
            'loose' => $loose,
            'looseHeadcount' => $this->users->countActiveHoldingAnyPosition($loose),
            'marks' => $this->marks($departments),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /**
     * THE LENS — a department's own page, area-aware and openable from every row.
     *
     * It carries the department's real facts: its scope, its area when it has
     * one, its code and the positions filed under it. The module-led overview,
     * the performance KPIs and the module attachments are the rich-lens
     * follow-up; the page draws their empty states and invents no data.
     */
    #[Route('/departments/{uuid}', name: 'team_department_show', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function show(string $uuid): Response
    {
        $department = $this->department($uuid);
        $positions = $owned = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            if ($position->getDepartment()?->getId() === $department->getId()) {
                $positions[] = $position;
                $owned[] = $position;
            }
        }

        return new Response($this->twig->render('@UhifadhiTeam/departments/show.html.twig', [
            'department' => $department,
            'mark' => $this->mark((string) $department->getName()),
            'positions' => $positions,
            'headcount' => $this->users->countActiveHoldingAnyPosition($owned),
            'holders' => $this->holders(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /**
     * CREATE — the scope question is asked first, area-first by default because
     * it is the narrow, correctable choice ({@see Department::changeScopeTo()}
     * widens without warning and confines with a ripple). An org-wide department
     * takes only a name; an area-level one takes the area the picker enumerated.
     */
    #[Route('/departments', name: 'team_department_create', methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A department needs a name.', 'error');
        }

        $department = new Department()->setName($name);

        // AREA-LEVEL unless org-wide was chosen. An empty or missing scope is
        // treated as area-first only when an area is actually named; a scope of
        // "area" with no area is a mis-post and falls back to org rather than
        // erroring, because a department with a name is worth keeping.
        if ('org' !== $request->request->get('scope')) {
            $areaUuid = trim((string) $request->request->get('area'));
            if ('' !== $areaUuid) {
                $area = $this->areaByUuid($areaUuid);
                if (null === $area) {
                    return $this->back($request, 'That area is not one this installation has.', 'error');
                }
                $department->setArea($area);
            }
        }

        $this->entityManager->persist($department);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // UNIQUE WITHIN ITS SCOPE. Two org-wide departments of one name, or
            // two in the same area, would be the same department entered twice. A
            // name may still repeat FROM ONE AREA TO ANOTHER — two areas may each
            // run an Anti-Poaching unit — the way a position name repeats across
            // departments.
            return $this->back($request, null === $department->getArea()
                ? \sprintf('There is already an organisation-wide department called “%s”. A name may repeat from one area to another, but the organisation-wide ones each stand alone.', $name)
                : \sprintf('%s already has a department called “%s”. Another area may share the name, but not this one twice.', (string) $department->getArea()->getName(), $name),
                'error');
        }

        return $this->back($request, null === $department->getArea()
            ? \sprintf('“%s” exists, organisation-wide. It owns no positions yet, and it grants nobody anything — a department is where work is filed, never what permits it.', $name)
            : \sprintf('“%s” exists, confined to %s. It owns no positions yet, and it grants nobody anything.', $name, (string) $department->getArea()->getName()));
    }

    #[Route('/departments/{uuid}/rename', name: 'team_department_rename', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function rename(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name'));
        if ('' === $name) {
            return $this->back($request, 'A department needs a name.', 'error');
        }

        $was = (string) $department->getName();
        $department->setName($name);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->back($request, \sprintf('There is already a department called “%s” in that scope.', $name), 'error');
        }

        return $this->back($request, \sprintf('“%s” is now “%s”. Every position it owns is named after it, so they all read differently now.', $was, $name));
    }

    /**
     * CHANGE A DEPARTMENT'S SCOPE — confine to an area, or promote to org-wide,
     * with a reason recorded to the audit trail on the transition.
     *
     * An area is confined-to; its absence is a promotion. Either way the reason
     * is required — {@see Department::changeScopeTo()} refuses a blank one before
     * the area moves — and the transition is appended as a
     * {@see \Uhifadhi\Team\Entity\DepartmentScopeChange}. Confining an org-wide
     * department re-scopes everything under it; promoting only widens. The actor
     * is the signed-in administrator, resolved from the token.
     */
    #[Route('/departments/{uuid}/scope', name: 'team_department_scope', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function changeScope(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);

        $reason = trim((string) $request->request->get('reason'));
        $areaUuid = trim((string) $request->request->get('area'));

        $newArea = null;
        if ('' !== $areaUuid) {
            $newArea = $this->areaByUuid($areaUuid);
            if (null === $newArea) {
                return $this->back($request, 'That area is not one this installation has.', 'error');
            }
        }

        $wasOrg = $department->isOrgLevel();

        try {
            $department->changeScopeTo($newArea, $this->signedIn(), $reason);
        } catch (MissingScopeChangeReasonException) {
            return $this->back($request, 'A scope change needs a reason — it is recorded to the audit trail.', 'error');
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Confining into an area that already runs a department of this name
            // would collapse two into one. Refuse, and name the clash.
            return $this->back($request, \sprintf(
                '%s already has a department called “%s”. Confine it under a different name, or rename one first.',
                $newArea?->getName() ?? 'That area',
                (string) $department->getName(),
            ), 'error');
        }

        if (null === $newArea) {
            return $this->back($request, \sprintf('“%s” is org-wide now — it spans every area. The change is recorded with your reason.', (string) $department->getName()));
        }

        return $this->back($request, \sprintf(
            '“%s” is confined to %s now%s. The change is recorded with your reason.',
            (string) $department->getName(),
            (string) $newArea->getName(),
            $wasOrg ? ', and everything filed under it moves with it' : '',
        ));
    }

    /**
     * DEACTIVATE — wind a department down without deleting it (the standing
     * fleet rule). The footprint ("N positions hold this") is stated in the
     * flash that confirms the act; it INFORMS, never guards, so the write goes
     * through regardless of how much is filed under the department. Its scope
     * history stays on the ledger and its positions keep their filing — a
     * deactivated department is hidden from the pickers, greyed in the register,
     * and one click from coming back.
     */
    #[Route('/departments/{uuid}/deactivate', name: 'team_department_deactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function deactivate(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);

        $footprint = $this->footprint($department);
        $department->deactivate();
        $this->entityManager->flush();

        return $this->back($request, \sprintf(
            '“%s” is deactivated — hidden from the pickers and greyed in the register, %s. Nothing is deleted: its history stays, and it is one click from coming back.',
            (string) $department->getName(),
            0 === $footprint['positions']
                ? 'and nothing was filed under it'
                : \sprintf('%d position%s (%d %s) stay filed under it and keep what they grant',
                    $footprint['positions'], 1 === $footprint['positions'] ? '' : 's',
                    $footprint['people'], 1 === $footprint['people'] ? 'person' : 'people'),
        ));
    }

    /**
     * REACTIVATE — bring a wound-down department back. No footprint: widening
     * availability strands nothing, the same way promoting a scope does not.
     */
    #[Route('/departments/{uuid}/reactivate', name: 'team_department_reactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function reactivate(Request $request, string $uuid): Response
    {
        $department = $this->department($uuid);
        $this->assertCsrf($request);

        $department->reactivate();
        $this->entityManager->flush();

        return $this->back($request, \sprintf('“%s” is active again — back in the pickers and the register.', (string) $department->getName()));
    }

    /**
     * FILING A POSITION — moving one between departments (or out to none). The
     * positions page chooses a department when a position is CREATED; this is the
     * only thing that moves one afterwards, which is why a position seeded before
     * its department was decided is reachable at all.
     */
    #[Route('/departments/positions/{uuid}/file', name: 'team_department_file', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function file(Request $request, string $uuid): Response
    {
        $position = $this->positions->findOneByUuid(Uuid::fromString($uuid))
            ?? throw new NotFoundHttpException('No such position on this installation.');
        $this->assertCsrf($request);

        $target = trim((string) $request->request->get('department'));

        // THE EMPTY OPTION IS A DESTINATION, not a missing value — a position
        // whose department was a mistake has to be able to leave it.
        $department = '' === $target ? null : $this->department($target);
        $position->setDepartment($department);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->back($request, \sprintf(
                '%s already has a position called “%s”. Two departments may own the same word — that is the point — but one department may not own it twice. Rename one of them first.',
                $department?->getName() ?? 'The unassigned group',
                (string) $position->getName(),
            ), 'error');
        }

        return $this->back($request, null === $department
            ? \sprintf('“%s” belongs to no department now, and its holders read as Unassigned on the roster.', (string) $position->getName())
            : \sprintf('“%s” is filed under %s. Nothing about what it grants has changed.', (string) $position->getName(), (string) $department->getName()));
    }

    /**
     * THE AREA-LEVEL DEPARTMENTS, GROUPED BY THEIR AREA and ordered by area name
     * — the shape the register draws first. Each group is its area and the
     * departments confined to it.
     *
     * @return list<array{area: AreaInterface, departments: list<Department>}>
     */
    private function areaGroups(): array
    {
        $groups = [];
        foreach ($this->departments->findAreaLevelOrdered() as $department) {
            $area = $department->getArea();
            if (null === $area) {
                continue; // Defensive: findAreaLevelOrdered() already excludes these.
            }
            $key = $area->getUuidString() ?? (string) $area->getId();
            $groups[$key] ??= ['area' => $area, 'departments' => []];
            $groups[$key]['departments'][] = $department;
        }

        // usort reindexes to a 0-based list, which is the shape the template reads.
        usort($groups, static fn (array $a, array $b): int => strcasecmp((string) $a['area']->getName(), (string) $b['area']->getName()));

        return $groups;
    }

    /**
     * THE INSTALLATION'S AREAS, for the create picker and the confine-to picker —
     * enumerated through the contract, never an area package.
     *
     * The concrete class is whatever the platform's {@see AreaInterface} was
     * resolved to (uhifadhi/area-module, or the host's own), read off the
     * association this module already declares on {@see Department::$area}. So the
     * picker knows the installation's areas without this module ever naming the
     * class that holds them.
     *
     * @return list<AreaInterface>
     */
    private function areas(): array
    {
        $class = $this->entityManager->getClassMetadata(Department::class)->getAssociationTargetClass('area');

        /** @var list<AreaInterface> $areas */
        $areas = $this->entityManager->getRepository($class)->findBy([], ['name' => 'ASC']);

        return $areas;
    }

    private function areaByUuid(string $uuid): ?AreaInterface
    {
        foreach ($this->areas() as $area) {
            if ($area->getUuidString() === $uuid) {
                return $area;
            }
        }

        return null;
    }

    /**
     * HOW MANY ACTIVE PEOPLE EACH POSITION REACHES — the number beside a row.
     *
     * @return array<string, int>
     */
    private function holders(): array
    {
        $holders = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            $holders[$position->getUuidString() ?? ''] = $this->users->countActiveHoldingAnyPosition([$position]);
        }

        return $holders;
    }

    /**
     * A TWO-LETTER MARK for each department, keyed by uuid — the plate the
     * register row and the lens both wear, so one department reads as one thing
     * in both places.
     *
     * @param list<Department> $departments
     *
     * @return array<string, string>
     */
    private function marks(array $departments): array
    {
        $marks = [];
        foreach ($departments as $department) {
            $marks[$department->getUuidString() ?? ''] = $this->mark((string) $department->getName());
        }

        return $marks;
    }

    /**
     * WHAT DEACTIVATING THIS DEPARTMENT TOUCHES — the positions filed under it
     * and the active people who hold them. Informs the confirming flash; it is
     * never a gate.
     *
     * @return array{positions: int, people: int}
     */
    private function footprint(Department $department): array
    {
        $positions = [];
        foreach ($this->positions->findAllOrdered() as $position) {
            if ($position->getDepartment()?->getId() === $department->getId()) {
                $positions[] = $position;
            }
        }

        return [
            'positions' => \count($positions),
            'people' => $this->users->countActiveHoldingAnyPosition($positions),
        ];
    }

    private function mark(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $words) {
            return '—';
        }
        if (\count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 2));
    }

    private function department(string $uuid): Department
    {
        return (Uuid::isValid($uuid) ? $this->departments->findOneByUuid(Uuid::fromString($uuid)) : null)
            ?? throw new NotFoundHttpException('No such department on this installation.');
    }

    private function signedIn(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    private function back(Request $request, string $message, string $kind = 'success'): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }

        return new RedirectResponse($this->router->generate('team_departments'));
    }
}
