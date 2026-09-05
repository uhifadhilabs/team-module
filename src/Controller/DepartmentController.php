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
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Repository\DepartmentRepository;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;

/**
 * THE ORG CHART'S HOME — departments, the positions they own, and the three
 * writes that shape them.
 *
 * THE CLOSED LOOP THIS OPENS. The Department entity shipped in v0.3.0 and
 * nothing in the product could make one: the matrix grouped by departments, the
 * roster banded by them, and they arrived only by whatever seeded the
 * installation. An installation whose seed never ran read "Unassigned" against
 * every position with no way out of it. That is the same shape of defect as a
 * route nobody links, and this is the screen that ends it.
 *
 * A DEPARTMENT GRANTS NOTHING, which is why nothing on this page is an
 * authorization decision. Filing a position into Ecology changes where it is
 * READ; every capability an installation has still arrives through that
 * position's permissions, and those are composed one screen over. The page says
 * so in its own subtitle rather than leaving it to be assumed.
 *
 * WHY `team.manage` GATES IT. It is the honest permission and not merely the
 * available one: the seven core cases carry exactly one that means "administer
 * this installation's people", and reshaping the org chart is that. There is no
 * department-scoped permission to invent here, because a department is an
 * organizational fact rather than an authorization one — inventing
 * `department.manage` would be inventing an eighth core case to guard a screen
 * that grants nothing.
 *
 * NOT A WIDGET SURFACE, unlike the roster and the matrix. Both of those ship
 * because five or six directions over one array were DRAWN and the standing
 * rule is that a drawn direction becomes an adoptable preset. Nothing was drawn
 * for this screen, and manufacturing six renderings to satisfy the shape of the
 * other two would be inventing a design rather than shipping one.
 *
 * DELETE IS ABSENT. The ruled posture for a person is deactivation, and it
 * applies here conceptually — but what a refusal SAYS to somebody deleting a
 * department that still owns four positions is a design, and no design has been
 * drawn. See the README's "Not here yet".
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
    ) {
    }

    /**
     * THE ADDRESS IS `/departments` AND NOT `/team/departments`, which the old
     * application's URL space and the drawn sidebar agree on: Departments is a
     * sibling of Team under Organization, not a screen inside the roster.
     * Departments are org-wide the way Team is — and the routes are mounted as a
     * directory, so this address arrives in an installation with no edit to its
     * config/routes/team.yaml.
     */
    #[Route('/departments', name: 'team_departments', methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function index(): Response
    {
        $departments = $this->departments->findAllOrdered();

        /*
         * THE POSITIONS COME FROM ONE QUERY, not from walking each
         * department's inverse collection. Two reasons and both matter: a walk
         * is a lazy load per card, and — the one that actually bit — the
         * inverse side of a OneToMany is only as true as whoever maintained
         * it. `Position::setDepartment()` is the owning side and there is no
         * `addPosition()` keeping the other end in step, so a department read
         * back in the same unit of work as the position filed into it reports
         * an empty collection. Reading the owning side is reading the fact.
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

        // HEADCOUNT IS REACHED THROUGH THE POSITIONS. A department holds
        // nobody directly, and a count that pretended otherwise would be the
        // first place this page lied about the model.
        $headcount = [];
        foreach ($departments as $department) {
            $key = $department->getUuidString() ?? '';
            $headcount[$key] = $this->users->countActiveHoldingAnyPosition($owned[$key] ?? []);
        }

        return new Response($this->twig->render('@UhifadhiTeam/departments/index.html.twig', [
            'departments' => $departments,
            'owned' => $owned,
            'headcount' => $headcount,
            'holders' => $this->holders(),
            'loose' => $loose,
            'looseHeadcount' => $this->users->countActiveHoldingAnyPosition($loose),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    /**
     * ONE FIELD, SO IT IS ONE FIELD — not a screen. A department is a name; it
     * was never worth a form page, which is part of why it went unbuilt for two
     * releases.
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

        $this->entityManager->persist(new Department()->setName($name));

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // UNIQUE ACROSS THE INSTALLATION, and here that IS the right scope:
            // there is one organisation, and two departments with one name
            // would be the same department entered twice. Unlike a position,
            // whose name is unique only inside its department.
            return $this->back($request, \sprintf(
                'There is already a department called “%s”. A department name is unique across this installation — there is one organisation, and two of them by one name would be the same department entered twice.',
                $name,
            ), 'error');
        }

        return $this->back($request, \sprintf(
            '“%s” exists. It owns no positions yet, and it grants nobody anything — a department is where work is filed, never what permits it.',
            $name,
        ));
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
            return $this->back($request, \sprintf('There is already a department called “%s”.', $name), 'error');
        }

        return $this->back($request, \sprintf('“%s” is now “%s”. Every position it owns is named after it, so they all read differently now.', $was, $name));
    }

    /**
     * FILING A POSITION — the write this screen exists for.
     *
     * The positions page can already choose a department when a position is
     * CREATED, and nothing could move one afterwards. So every position seeded
     * before anybody decided which department owned it was stranded outside the
     * org chart permanently, which is exactly the state a fresh installation is
     * in.
     */
    #[Route('/departments/positions/{uuid}/file', name: 'team_department_file', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function file(Request $request, string $uuid): Response
    {
        $position = $this->positions->findOneByUuid(Uuid::fromString($uuid))
            ?? throw new NotFoundHttpException('No such position on this installation.');
        $this->assertCsrf($request);

        $target = trim((string) $request->request->get('department'));

        // THE EMPTY OPTION IS A DESTINATION, not a missing value. A position
        // whose department was a mistake has to be able to leave it, and the
        // null is a state the model already draws — the roster's Unassigned
        // band is what it looks like.
        $department = '' === $target ? null : $this->department($target);
        $position->setDepartment($department);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // THE RULING THIS MODULE IS BUILT AROUND. Ecology's Analyst and
            // Protection Service's Analyst are two jobs sharing a word;
            // `unique(department, name)` is what keeps them apart, and filing
            // one into the other's department would ask the database to
            // collapse them. The refusal names both, because "that name is
            // taken" without a department is the ambiguity the ruling exists
            // to remove.
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
     * HOW MANY ACTIVE PEOPLE EACH POSITION REACHES — the number beside a row,
     * and the one an administrator wants before moving it anywhere.
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

    private function department(string $uuid): Department
    {
        return (Uuid::isValid($uuid) ? $this->departments->findOneByUuid(Uuid::fromString($uuid)) : null)
            ?? throw new NotFoundHttpException('No such department on this installation.');
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
