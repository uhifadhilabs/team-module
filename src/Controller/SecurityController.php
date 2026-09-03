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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * SIGN IN AND SIGN OUT — the two addresses this module owns.
 *
 * AUTHENTICATION ITSELF HAPPENS NOWHERE IN THIS CLASS. The `form_login`
 * firewall — which the installation writes in its own security.yaml, because
 * that file is the application's — intercepts the POST to /login before any
 * controller runs, and the `logout` key intercepts /logout entirely. What is
 * left for a controller is rendering the form and bouncing somebody who is
 * already signed in — which is why login() only ever answers a GET it was
 * allowed to reach.
 *
 * A MODULE MAY SHIP ROUTES; only the shell may not. These two are attribute
 * routes on this class, mounted by the recipe's config/routes/team.yaml the way
 * every other module's are.
 *
 * A PLAIN CLASS, extending nothing, with its collaborators handed to it — the
 * reusable-bundle rule (see config/services.php), patterned on FrameworkBundle's
 * own TemplateController.
 */
final readonly class SecurityController
{
    /**
     * @param string $afterSignInPath where a fresh sign-in lands. A PATH, not a
     *                                route name: this bundle cannot know what an installation calls its
     *                                home screen, and a route name it guessed wrong would be a 500 at the
     *                                exact moment somebody finally got their password right. The firewall's
     *                                own `default_target_path` is what normally decides this; the value
     *                                here only covers the visitor who asks for /login while already signed
     *                                in, which no firewall has an opinion about.
     */
    public function __construct(
        private Environment $twig,
        private AuthenticationUtils $authenticationUtils,
        private TokenStorageInterface $tokens,
        private string $afterSignInPath = '/',
        private string $signInLede = '',
    ) {
    }

    #[Route('/login', name: 'team_login', methods: ['GET', 'POST'])]
    public function login(): Response
    {
        if ($this->tokens->getToken()?->getUser() instanceof UserInterface) {
            return new RedirectResponse($this->afterSignInPath);
        }

        return new Response($this->twig->render('@UhifadhiTeam/login.html.twig', [
            'last_username' => $this->authenticationUtils->getLastUsername(),
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
            'sign_in_lede' => $this->signInLede,
        ]));
    }

    /**
     * Never executed: the firewall's logout listener answers this path. The
     * route has to exist so the listener has something to match and so
     * `path('team_logout')` generates a URL — the body says so out loud rather
     * than returning a response nobody will ever see.
     */
    #[Route('/logout', name: 'team_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on the firewall — see the security.yaml this installation wired (the module README\'s "Wire the security").');
    }
}
