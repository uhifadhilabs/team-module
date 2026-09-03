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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Repository\PositionRepository;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Service\Mail;

/**
 * ADDING SOMEBODY — and BOTH WAYS SHIP.
 *
 * They are not two settings and not a migration: they are two answers to a real
 * difference, which is whether the person is standing next to you.
 *
 * CREATE WITH A PASSWORD needs nothing at all from the deployment — no mailer,
 * no outbound network, no reachable inbox — which is exactly why it is the one
 * that is always available. The person is verified the moment they exist,
 * because an administrator who typed the password has already proved the
 * account is real. It is the only path when a ranger's only address is one they
 * cannot reach from the field. Its cost is operational rather than technical:
 * the password is hashed on save and the product can never show it again, so it
 * has to be handed over in the room.
 *
 * INVITE BY EMAIL ships beside it and starts working by itself the moment an
 * installation has a mailer. Nobody ever knows anybody else's password, which is
 * the whole argument for it.
 *
 * WHERE THERE IS NO MAILER THE PATH IS OFFERED AND REFUSED, NOT HIDDEN. Hiding
 * it would leave an administrator hunting for a feature the product does have;
 * failing silently after the click would leave a colleague waiting for an email
 * nobody sent. So the form is visible, the button is inert, and the reason is
 * written on it. That is the one deliberate exception to this workspace's
 * never-a-disabled-control rule, and the rule's own wording allows it: absence
 * is for a thing that will NEVER exist, and this one is an environment variable
 * away.
 */
final readonly class InviteController
{
    public const string CSRF_CREATE = 'team_member';
    public const string CSRF_INVITE = 'team_invite';

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private PositionRepository $positions,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
        private Mail $mail,
    ) {
    }

    #[Route('/team/invite', name: 'team_invite', methods: ['GET'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function show(): Response
    {
        return new Response($this->twig->render('@UhifadhiTeam/team/invite.html.twig', [
            'groupedPositions' => $this->positions->findAllGroupedByDepartment(),
            // The one deployment fact this page turns on.
            'mailerConfigured' => $this->mail->isConfigured(),
            'passwordMinLength' => User::PASSWORD_MIN_LENGTH,
            'createToken' => $this->csrf->getToken(self::CSRF_CREATE)->getValue(),
            'inviteToken' => $this->csrf->getToken(self::CSRF_INVITE)->getValue(),
        ]));
    }

    /** THE PATH THAT NEEDS NOTHING FROM THE DEPLOYMENT. */
    #[Route('/team', name: 'team_member_create', methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF_CREATE);

        $email = strtolower(trim((string) $request->request->get('email')));
        $password = (string) $request->request->get('password');

        if ('' === $email) {
            return $this->back($request, 'An email address is the sign-in identifier, so it is required.', 'error');
        }
        if (null !== $this->users->findOneByEmail($email)) {
            return $this->back($request, \sprintf('An account with the email %s already exists. Emails are folded to lower case, so a different capitalisation is the same person.', $email), 'error');
        }
        if (mb_strlen($password) < User::PASSWORD_MIN_LENGTH) {
            return $this->back($request, \sprintf('A password must be at least %d characters.', User::PASSWORD_MIN_LENGTH), 'error');
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName(trim((string) $request->request->get('firstName')))
            ->setLastName(trim((string) $request->request->get('lastName')))
            ->setRangerCode(trim((string) $request->request->get('rangerCode')))
            // VERIFIED THE MOMENT THEY EXIST: an administrator who typed the
            // password has already proved the account is real, which is more
            // than an email round-trip proves.
            ->setVerified(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->assignPosition($user, (string) $request->request->get('position'));

        // invitedAt STAYS NULL. Nobody invited them, and the roster reads that
        // null as "created directly · no invitation" — the honest difference
        // between the two paths.
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->toMember($request, $user, \sprintf('%s exists and can sign in now. The password is hashed and the product cannot show it again, so hand it over before you close this.', $user->getFullName()));
    }

    /** THE PATH THAT NEEDS A MAILER, and says so when there is none. */
    #[Route('/team/invite', name: 'team_invite_send', methods: ['POST'])]
    #[IsGranted(PermissionEnum::TeamManage->value)]
    public function invite(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF_INVITE);

        if (!$this->mail->isConfigured()) {
            // OFFERED AND REFUSED. The form was visible and the reason was
            // written on it; a POST that arrives anyway gets the same sentence
            // rather than a silent success.
            return $this->back($request, 'This installation has no mail transport configured, so there is nothing to send the invitation with. Set MAILER_DSN and try again — or add the person with a password, which needs nothing from the deployment.', 'error');
        }

        $email = strtolower(trim((string) $request->request->get('email')));
        if ('' === $email) {
            return $this->back($request, 'An invitation needs an address to go to.', 'error');
        }
        if (null !== $this->users->findOneByEmail($email)) {
            return $this->back($request, \sprintf('An account with the email %s already exists.', $email), 'error');
        }

        $user = (new User())
            ->setEmail($email)
            // NO NAME. It comes from the person when they accept: an
            // administrator guessing at somebody's own spelling of their own
            // name is a small indignity the product does not need to cause.
            ->setFirstName('')
            ->setLastName('')
            // Unusable until they set one. Not empty — an empty hash is a hash
            // some verifier somewhere will one day accept.
            ->setPassword($this->hasher->hashPassword(new User(), bin2hex(random_bytes(32))))
            ->setVerified(false)
            ->setVerificationToken(bin2hex(random_bytes(32)));

        $this->assignPosition($user, (string) $request->request->get('position'));

        $inviter = $this->signedIn();
        if (null !== $inviter) {
            // RULED IN, so the roster can say who invited somebody and when
            // rather than only that they have not arrived.
            $user->markInvitedBy($inviter);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->mail->sendInvitation($user, $this->router->generate(
            'team_invite_accept',
            ['token' => (string) $user->getVerificationToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ));

        return $this->toMember($request, $user, \sprintf('Invitation sent to %s. They set their own password from the link, so nobody here ever knows it.', $email));
    }

    private function assignPosition(User $user, string $chosen): void
    {
        $chosen = trim($chosen);
        if ('' === $chosen || !Uuid::isValid($chosen)) {
            // OPTIONAL, AND HONESTLY SO: leaving it empty creates somebody who
            // can sign in and do nothing. Better than guessing.
            return;
        }

        $user->setPosition($this->positions->findOneByUuid(Uuid::fromString($chosen)));
    }

    private function signedIn(): ?User
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($id, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }

    private function flash(Request $request, string $message, string $kind): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($kind, $message);
        }
    }

    private function back(Request $request, string $message, string $kind = 'success'): RedirectResponse
    {
        $this->flash($request, $message, $kind);

        return new RedirectResponse($this->router->generate('team_invite'));
    }

    /** Somebody who now exists is somebody whose record is the useful next page. */
    private function toMember(Request $request, User $user, string $message): RedirectResponse
    {
        $this->flash($request, $message, 'success');

        return new RedirectResponse($this->router->generate('team_member', ['uuid' => $user->getUuidString()]));
    }
}
