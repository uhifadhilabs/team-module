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
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Exception\LastSuperAdminException;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Service\PermissionCatalogue;
use Uhifadhi\Team\Service\SuperAdminInvariant;

/**
 * ONE PERSON'S RECORD — the four fields the table has, the tier that decides
 * whether they stand above the matrix, and the position that decides what they
 * may actually do.
 *
 * THERE IS NO DELETE, AND THERE IS NO ROUTE FOR ONE. Accounts are never
 * hard-deleted: "this ranger left in March" and "this ranger never existed" are
 * different facts, and everything the person recorded keeps its author. The
 * action is Deactivate and its opposite is Reactivate. A `deletedAt` marker
 * exists on the model so a future recycle bin is not foreclosed, and nothing
 * here writes it.
 *
 * TWO CHANGES CAN BE REFUSED, and they are refused with their reason rather
 * than greyed out. Demoting or deactivating the last ACTIVE Super Admin leaves
 * nobody who can administer the team, so {@see SuperAdminInvariant} says no —
 * and the page prints the refusal where the control would have been, because a
 * disabled button says "not now" and leaves the reader guessing.
 *
 * THERE IS NO PER-PERSON PERMISSION ANYWHERE ON THIS PAGE, because the model has
 * no such thing. Authority lives on the position; giving one person an exception
 * would mean giving them a position of their own.
 *
 * EVERY WRITE IS A POST AND EVERY POST IS CSRF-CHECKED.
 */
final readonly class MemberController
{
    /** One token id for the whole record, because it is one screen. */
    public const string CSRF_ID = 'team_member';

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private PositionRepository $positions,
        private PermissionCatalogue $catalogue,
        private SuperAdminInvariant $invariant,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
    ) {
    }

    #[Route('/team/{uuid}', name: 'team_member', requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function show(string $uuid): Response
    {
        $member = $this->member($uuid);

        return new Response($this->twig->render('@UhifadhiTeam/team/member.html.twig', [
            'member' => $member,
            'tiers' => TeamRoleEnum::cases(),
            'groupedPositions' => $this->positions->findAllGroupedByDepartment(),
            // WHAT THAT ACTUALLY GRANTS, RIGHT NOW — every catalogue row with
            // the REASON this person does or does not hold it. The page's whole
            // argument is that a position name is not an answer.
            'effective' => $this->effective($member),
            // Asked before the page draws, so the refusal appears IN PLACE OF
            // the control rather than after somebody has pressed it.
            'isLastSuperAdmin' => $this->invariant->isLastActiveSuperAdmin($member),
            // Impersonation is OFFERED to a Super Admin only; for anybody else
            // the row is ABSENT rather than disabled.
            'mayImpersonate' => $this->signedIn()?->getTeamRole()->canSwitch() ?? false,
            'isSelf' => $this->signedIn()?->getId() === $member->getId(),
            'csrfToken' => $this->csrf->getToken(self::CSRF_ID)->getValue(),
        ]));
    }

    #[Route('/team/{uuid}', name: 'team_member_update', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function update(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        $member
            ->setFirstName(trim((string) $request->request->get('firstName')))
            ->setLastName(trim((string) $request->request->get('lastName')))
            ->setEmail(trim((string) $request->request->get('email')))
            ->setRangerCode(trim((string) $request->request->get('rangerCode')));

        $this->entityManager->flush();

        return $this->back($request, $member, 'Saved.');
    }

    #[Route('/team/{uuid}/tier', name: 'team_member_tier', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function tier(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        $tier = TeamRoleEnum::tryFrom((string) $request->request->get('tier'));
        if (null === $tier) {
            return $this->back($request, $member, 'That is not a tier this installation has.', 'error');
        }

        try {
            $this->invariant->assertMayChangeTier($member, $tier);
        } catch (LastSuperAdminException $refusal) {
            return $this->back($request, $member, $refusal->getMessage(), 'error');
        }

        $member->setTeamRole($tier);
        $this->entityManager->flush();

        return $this->back($request, $member, \sprintf('%s is now %s.', $member->getFullName(), $tier->label()));
    }

    #[Route('/team/{uuid}/position', name: 'team_member_position', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function position(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        $chosen = trim((string) $request->request->get('position'));
        if ('' === $chosen) {
            // NULLABLE, AND THE NULL IS A REAL CHOICE. A Staff member with no
            // position is verified, can sign in, and can do nothing at all.
            $member->setPosition(null);
            $this->entityManager->flush();

            return $this->back($request, $member, \sprintf('%s now holds no position, and therefore no permissions at all.', $member->getFullName()));
        }

        $position = Uuid::isValid($chosen) ? $this->positions->findOneByUuid(Uuid::fromString($chosen)) : null;
        if (null === $position) {
            return $this->back($request, $member, 'That position no longer exists.', 'error');
        }

        $member->setPosition($position);
        $this->entityManager->flush();

        return $this->back($request, $member, \sprintf('%s now holds %s.', $member->getFullName(), $position->getQualifiedName()));
    }

    /**
     * THE WAY SOMEBODY LEAVES. Not a delete, and there is no delete: the row
     * stays, everything they recorded keeps its author, and reactivating is one
     * click.
     */
    #[Route('/team/{uuid}/deactivate', name: 'team_member_deactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function deactivate(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        try {
            $this->invariant->assertMayDeactivate($member);
        } catch (LastSuperAdminException $refusal) {
            return $this->back($request, $member, $refusal->getMessage(), 'error');
        }

        $member->deactivate();
        $this->entityManager->flush();

        return $this->back($request, $member, \sprintf('%s can no longer sign in. Nothing has been deleted — everything they recorded keeps its author, and they stay on the roster under the inactive filter.', $member->getFullName()));
    }

    #[Route('/team/{uuid}/reactivate', name: 'team_member_reactivate', requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function reactivate(Request $request, string $uuid): Response
    {
        $member = $this->member($uuid);
        $this->assertCsrf($request);

        $member->reactivate();
        $this->entityManager->flush();

        return $this->back($request, $member, \sprintf('%s can sign in again.', $member->getFullName()));
    }

    /**
     * EVERY CATALOGUE ROW, WITH THE REASON. "by tier" for the two levels above
     * the matrix, "by position" for what the position grants, "not held"
     * otherwise — and, at the end, the ORPHANS: values this position still
     * holds that no installed module provides any more. They are shown rather
     * than hidden, because the difference between "you no longer have this" and
     * "you cannot see that you still have this" is the whole of the
     * prune-not-purge ruling.
     *
     * @return list<array{value: string, label: string, description: ?string, held: bool, why: string}>
     */
    private function effective(User $member): array
    {
        $byTier = $member->getTeamRole()->canManageContent();
        $held = $member->getPosition()?->getPermissionValues() ?? [];

        $rows = [];
        foreach ($this->catalogue->all() as $permission) {
            $has = $byTier || \in_array($permission->value, $held, true);
            $rows[] = [
                'value' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description,
                'held' => $has,
                'why' => $has ? ($byTier ? 'by tier' : 'by position') : 'not held',
            ];
        }

        $known = $this->catalogue->values();
        foreach ($held as $value) {
            if (!\in_array($value, $known, true)) {
                $rows[] = [
                    'value' => $value,
                    'label' => 'no longer described',
                    'description' => null,
                    'held' => true,
                    'why' => 'orphaned grant',
                ];
            }
        }

        return $rows;
    }

    private function member(string $uuid): User
    {
        return $this->users->findOneByUuid(Uuid::fromString($uuid))
            ?? throw new NotFoundHttpException('No such person on this installation.');
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

    /**
     * Back to the record with a sentence. A refusal is a flash rather than a
     * status code, because it is not an error the browser made — it is the
     * model saying no, and the reader needs the reason beside the control.
     */
    private function back(Request $request, User $member, string $message, string $kind = 'success'): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }

        return new RedirectResponse($this->router->generate('team_member', ['uuid' => $member->getUuidString()]));
    }
}
