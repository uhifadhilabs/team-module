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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Service\Mail;

/**
 * THE SELF-SERVICE SCREENS — the three a stranger reaches with nobody to ask.
 *
 * THEY STAND ON THE DOCUMENT RUNG, exactly as the sign-in card does and for its
 * reason: there is no sidebar to navigate and no top bar to act from until
 * there is somebody to act as, and a nav rendered for an anonymous visitor
 * leaks what an installation contains to whoever loads its front door.
 *
 * THE ANSWER TO "FORGOT MY PASSWORD" IS NEUTRAL. The same sentence, in the same
 * words, whether or not that address has an account. An installation that
 * answered differently for a known and an unknown address would be an
 * installation whose front door tells a stranger who works here. There is no
 * state of that screen that says "no such user".
 *
 * A LINK IS GOOD FOR ONE HOUR AND WORKS ONCE, and using it SIGNS EVERY OTHER
 * SESSION OF THE ACCOUNT OUT. That last part is the point of a reset — anywhere
 * the account was still open, a handset left in a vehicle, a machine in an
 * office somebody has left — so it is confirmed on screen rather than done
 * quietly.
 *
 * WHY THIS IS HAND-ROLLED AND NOT symfonycasts/reset-password-bundle. That
 * bundle is the standard and it was the first thing considered. Three things
 * decided against it. The columns this flow writes — `passwordResetToken` and
 * `passwordResetRequestedAt` — SHIPPED IN THIS MODULE'S FIRST RELEASE and are
 * what every design annotation names; adopting the bundle would mean a second
 * table, a second lifetime, and two places an installation could read the state
 * of one reset. It does not invalidate other sessions, which is a ruled
 * requirement here and would have had to be bolted on regardless. And what is
 * left after those two is a token, an hour and a lookup — about forty lines,
 * below the line where a dependency pays for itself. The security-relevant part
 * is not the plumbing but the neutral answer and the single use, and both are
 * this module's own rules either way.
 */
final readonly class PasswordResetController
{
    public const string CSRF_REQUEST = 'team_reset_request';
    public const string CSRF_RESET = 'team_reset';
    public const string CSRF_ACCEPT = 'team_accept';

    /** ONE HOUR. Symfony's own recommended default, and the screens say so before you ask. */
    public const int LIFETIME_SECONDS = 3600;

    public function __construct(
        private Environment $twig,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $router,
        private TokenStorageInterface $tokens,
        private Mail $mail,
        private string $afterSignInPath = '/',
    ) {
    }

    #[Route('/reset-password', name: 'team_reset_request', methods: ['GET'])]
    public function ask(): Response
    {
        return new Response($this->twig->render('@UhifadhiTeam/auth/forgot.html.twig', [
            'state' => $this->mail->isConfigured() ? 'ask' : 'nomail',
            'lifetimeHours' => (int) (self::LIFETIME_SECONDS / 3600),
            'csrfToken' => $this->csrf->getToken(self::CSRF_REQUEST)->getValue(),
        ]));
    }

    /**
     * THE NEUTRAL ANSWER. Same words, same screen, whether or not the address
     * has an account — the only difference is whether an email goes out, and
     * nothing on the page can be read to tell which happened.
     */
    #[Route('/reset-password', name: 'team_reset_send', methods: ['POST'])]
    public function send(Request $request): Response
    {
        $this->assertCsrf($request, self::CSRF_REQUEST);

        $email = strtolower(trim((string) $request->request->get('email')));

        if (!$this->mail->isConfigured()) {
            // A FRESH INSTALLATION IS IN EXACTLY THIS STATE until somebody
            // configures a transport. The screen is honest about it rather than
            // swallowing the request, because a silently discarded reset is the
            // worst failure this flow has.
            return new Response($this->twig->render('@UhifadhiTeam/auth/forgot.html.twig', [
                'state' => 'nomail',
                'lifetimeHours' => (int) (self::LIFETIME_SECONDS / 3600),
                'csrfToken' => $this->csrf->getToken(self::CSRF_REQUEST)->getValue(),
            ]));
        }

        $user = $this->users->findOneByEmail($email);
        // A DEACTIVATED ACCOUNT GETS NO LINK, and is told nothing different: a
        // reset that let somebody back through a door the firewall closes would
        // be a reset that undoes a deactivation.
        if (null !== $user && $user->isActive()) {
            // ASKING AGAIN REPLACES THE PREVIOUS LINK, so an old email in an
            // inbox stops working the moment a new one is sent.
            $user->setPasswordResetToken(bin2hex(random_bytes(32)));
            $user->setPasswordResetRequestedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->mail->sendPasswordReset($user, $this->router->generate(
                'team_reset',
                ['token' => (string) $user->getPasswordResetToken()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ));
        }

        return new Response($this->twig->render('@UhifadhiTeam/auth/forgot.html.twig', [
            'state' => 'sent',
            'address' => $email,
            'lifetimeHours' => (int) (self::LIFETIME_SECONDS / 3600),
            'csrfToken' => $this->csrf->getToken(self::CSRF_REQUEST)->getValue(),
        ]));
    }

    #[Route('/reset-password/{token}', name: 'team_reset', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function form(string $token): Response
    {
        $user = $this->liveResetFor($token);

        return new Response($this->twig->render('@UhifadhiTeam/auth/reset.html.twig', [
            'state' => null !== $user ? 'good' : 'stale',
            // WHO THIS LINK IS FOR IS READ FROM THE TOKEN, never from the
            // address bar — so it is text on the card, not a field, and
            // retyping the URL cannot aim it at somebody else.
            'member' => $user,
            'minLength' => User::PASSWORD_MIN_LENGTH,
            'csrfToken' => $this->csrf->getToken(self::CSRF_RESET)->getValue(),
        ]));
    }

    #[Route('/reset-password/{token}', name: 'team_reset_submit', requirements: ['token' => '[0-9a-f]{64}'], methods: ['POST'])]
    public function reset(Request $request, string $token): Response
    {
        $this->assertCsrf($request, self::CSRF_RESET);

        $user = $this->liveResetFor($token);
        if (null === $user) {
            return new Response($this->twig->render('@UhifadhiTeam/auth/reset.html.twig', [
                'state' => 'stale',
                'member' => null,
                'minLength' => User::PASSWORD_MIN_LENGTH,
                'csrfToken' => $this->csrf->getToken(self::CSRF_RESET)->getValue(),
            ]));
        }

        $password = (string) $request->request->get('password');
        $again = (string) $request->request->get('password_confirm');
        $error = $this->passwordProblem($password, $again);
        if (null !== $error) {
            return new Response($this->twig->render('@UhifadhiTeam/auth/reset.html.twig', [
                'state' => 'good',
                'member' => $user,
                'error' => $error,
                'minLength' => User::PASSWORD_MIN_LENGTH,
                'csrfToken' => $this->csrf->getToken(self::CSRF_RESET)->getValue(),
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setPassword($this->hasher->hashPassword($user, $password));
        // SINGLE USE: consuming the token spends it, so the same link cannot be
        // walked back to by anybody holding the email.
        $user->setPasswordResetToken(null);
        $user->setPasswordResetRequestedAt(null);
        $user->setVerified(true);
        $this->entityManager->flush();

        $this->signEveryOtherSessionOut($request);

        return new Response($this->twig->render('@UhifadhiTeam/auth/reset.html.twig', [
            'state' => 'done',
            'member' => $user,
            'continuePath' => $this->afterSignInPath,
            'minLength' => User::PASSWORD_MIN_LENGTH,
            'csrfToken' => $this->csrf->getToken(self::CSRF_RESET)->getValue(),
        ]));
    }

    /** STEP 3 OF THE INVITE FLOW: the person sets their own name and password. */
    #[Route('/invite/{token}', name: 'team_invite_accept', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function acceptForm(string $token): Response
    {
        $user = $this->users->findOneBy(['verificationToken' => $token]);

        return new Response($this->twig->render('@UhifadhiTeam/auth/accept.html.twig', [
            'state' => $user instanceof User && !$user->isVerified() && $user->isActive() ? 'open' : 'stale',
            'member' => $user instanceof User ? $user : null,
            'minLength' => User::PASSWORD_MIN_LENGTH,
            'csrfToken' => $this->csrf->getToken(self::CSRF_ACCEPT)->getValue(),
        ]));
    }

    #[Route('/invite/{token}', name: 'team_invite_accept_submit', requirements: ['token' => '[0-9a-f]{64}'], methods: ['POST'])]
    public function accept(Request $request, string $token): Response
    {
        $this->assertCsrf($request, self::CSRF_ACCEPT);

        $user = $this->users->findOneBy(['verificationToken' => $token]);
        if (!$user instanceof User || $user->isVerified() || !$user->isActive()) {
            return new Response($this->twig->render('@UhifadhiTeam/auth/accept.html.twig', [
                'state' => 'stale',
                'member' => null,
                'minLength' => User::PASSWORD_MIN_LENGTH,
                'csrfToken' => $this->csrf->getToken(self::CSRF_ACCEPT)->getValue(),
            ]));
        }

        $name = trim((string) $request->request->get('name'));
        $password = (string) $request->request->get('password');
        $error = '' === $name
            ? 'Your name is asked here rather than guessed by whoever invited you, so it is required.'
            : $this->passwordProblem($password, $password);

        if (null !== $error) {
            return new Response($this->twig->render('@UhifadhiTeam/auth/accept.html.twig', [
                'state' => 'open',
                'member' => $user,
                'error' => $error,
                'minLength' => User::PASSWORD_MIN_LENGTH,
                'csrfToken' => $this->csrf->getToken(self::CSRF_ACCEPT)->getValue(),
            ]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ONE FIELD, SPLIT ONCE. Asking for "first name" and "last name"
        // separately is a Western assumption about names; asking for the name
        // and taking the last word as the family name is a smaller one, and it
        // is the one the two stored columns force.
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $last = \count($parts) > 1 ? (string) array_pop($parts) : '';
        $user->setFirstName(implode(' ', $parts));
        $user->setLastName($last);

        $user->setPassword($this->hasher->hashPassword($user, $password));
        // SETTING A PASSWORD IS WHAT MARKS THE ACCOUNT VERIFIED, and it spends
        // the token: the invitation has done its job.
        $user->setVerified(true);
        $user->setVerificationToken(null);
        $this->entityManager->flush();

        $this->signEveryOtherSessionOut($request);

        return new Response($this->twig->render('@UhifadhiTeam/auth/accept.html.twig', [
            'state' => 'done',
            'member' => $user,
            'continuePath' => $this->afterSignInPath,
            'minLength' => User::PASSWORD_MIN_LENGTH,
            'csrfToken' => $this->csrf->getToken(self::CSRF_ACCEPT)->getValue(),
        ]));
    }

    /**
     * A LIVE TOKEN, or null. Expired and already-used are ONE ANSWER on
     * purpose: telling a visitor which of the two it was tells whoever is
     * holding a stolen link something about the account.
     */
    private function liveResetFor(string $token): ?User
    {
        $user = $this->users->findOneBy(['passwordResetToken' => $token]);
        if (!$user instanceof User || !$user->isActive()) {
            return null;
        }

        $requestedAt = $user->getPasswordResetRequestedAt();
        if (null === $requestedAt) {
            return null;
        }

        return $requestedAt->getTimestamp() + self::LIFETIME_SECONDS >= time() ? $user : null;
    }

    /** The one rule, stated on the card so nobody meets it only on submit. */
    private function passwordProblem(string $password, string $again): ?string
    {
        if (mb_strlen($password) < User::PASSWORD_MIN_LENGTH) {
            return \sprintf('The password must be at least %d characters.', User::PASSWORD_MIN_LENGTH);
        }
        if ($password !== $again) {
            return 'The two passwords are not the same.';
        }

        return null;
    }

    /**
     * EVERY OTHER SESSION OF THIS ACCOUNT, SIGNED OUT. That is the point of a
     * reset: anywhere the account was still open — another browser, a handset
     * left in a vehicle, a machine in an office somebody has left.
     *
     * Symfony's own mechanism for it is the session's invalidation plus the
     * token being dropped, and the password hash changing is what makes every
     * OTHER remembered session fail its check: `remember_me` signs its cookie
     * with the hash, so rehashing invalidates the lot without this module
     * keeping a session registry it would then have to prune.
     */
    private function signEveryOtherSessionOut(Request $request): void
    {
        $this->tokens->setToken(null);
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken($id, (string) $request->request->get('_token')))) {
            throw new NotFoundHttpException('Invalid CSRF token.');
        }
    }
}
