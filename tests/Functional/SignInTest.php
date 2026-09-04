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

namespace Uhifadhi\Team\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Team\Entity\User;

/**
 * THE ONE SCREEN THIS MODULE DRAWS, exercised end to end: it answers, it is the
 * shell's document (not a bare form on a white page), and posting the right
 * credentials into it actually signs somebody in.
 */
final class SignInTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    private function seedWarden(string $password = 'correct horse'): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get('test_public.hasher');

        $user = (new User())->setEmail('warden@example.test')->setFirstName('Asha')->setLastName('Mollel');
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();
    }

    public function testTheSignInScreenAnswersInsideTheShellsDocument(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $html = (string) $this->client->getResponse()->getContent();
        // The shell's document rung: its stylesheet and its tab icon, which is
        // what "rendered through the shell" means for a page with no furniture.
        // Matched by STEM: AssetMapper content-digests these paths, so the
        // filename carries a hash that changes with the file.
        self::assertMatchesRegularExpression('#bundles/uhifadhishell/shell[-.][^"]*\.css#', $html);
        self::assertMatchesRegularExpression('#bundles/uhifadhishell/favicon[-.][^"]*\.svg#', $html);
        // And this module's own sheet, for the card the shell knows nothing about.
        self::assertMatchesRegularExpression('#bundles/uhifadhiteam/team[-.][^"]*\.css#', $html);

        // NO NAVIGATION. A stranger gets no sidebar and no top bar: there is
        // nowhere to go and nobody to go as.
        self::assertSame(0, $crawler->filter('.side')->count());
        self::assertSame(0, $crawler->filter('nav')->count());

        // The form, with the field names form_login reads and a CSRF token.
        self::assertSame(1, $crawler->filter('form[action="/login"] input[name="_username"]')->count());
        self::assertSame(1, $crawler->filter('form[action="/login"] input[name="_password"]')->count());
        self::assertSame(1, $crawler->filter('form[action="/login"] input[name="_csrf_token"]')->count());
    }

    public function testTheRightCredentialsSignSomebodyIn(): void
    {
        $this->seedWarden();

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'warden@example.test',
            '_password' => 'correct horse',
        ]);
        $this->client->submit($form);

        // The firewall's default_target_path, which is the installation's front
        // door — not a page this module owns.
        self::assertResponseRedirects('http://localhost/');

        // AND THE SESSION HOLDS, which is the actual claim. Asserted by walking
        // to a guarded page rather than by reading the token out of the test
        // container: the token storage is per-request, so a container read after
        // the redirect would be asking a fresh request what the last one knew.
        $this->client->request('GET', '/_guarded');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testTheWrongPasswordIsRefusedAndSaidSoOnThePage(): void
    {
        $this->seedWarden();

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => 'warden@example.test',
            '_password' => 'wrong horse',
        ]);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();

        self::assertSame(1, $crawler->filter('.auth-error')->count());
        // The email survives the round trip; only the password is retyped.
        self::assertSame('warden@example.test', $crawler->filter('input[name="_username"]')->attr('value'));
    }

    public function testAGuardedPageSendsAStrangerToTheSignInScreen(): void
    {
        $this->client->request('GET', '/_guarded');

        self::assertResponseRedirects('http://localhost/login');
    }

    /**
     * THE FRONT DOOR IS ONE OF THE GUARDED PAGES. This installation is a
     * back-of-house application: once identity is installed there is no page of
     * it a stranger is meant to read, `/` included. The catch-all rule in the
     * documented `access_control` is what makes that true, and this is the test
     * that would notice if the documented ladder ever went open again.
     */
    public function testTheFrontDoorSendsAStrangerToTheSignInScreen(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/login');
    }

    /** And the sign-in screen itself stays reachable with nobody to ask. */
    public function testTheSignInScreenIsReachableByAStranger(): void
    {
        $this->client->request('GET', '/login');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    /**
     * THE RECOVERY PATHS STAY OPEN, because the person they are for is by
     * definition somebody who cannot sign in. Closing either of them with the
     * catch-all would leave a locked-out colleague with no way back in at all.
     */
    public function testTheRecoveryPathsStayReachableByAStranger(): void
    {
        $this->client->request('GET', '/reset-password');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        // An unknown token still renders its own refusal rather than bouncing
        // to /login: reaching the screen is the public part, not the token.
        $this->client->request('GET', '/invite/'.str_repeat('f', 64));
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testAnAlreadySignedInVisitorIsNotShownTheFormAgain(): void
    {
        $this->seedWarden();
        /** @var User $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'warden@example.test']);

        $this->client->loginUser($user);
        $this->client->request('GET', '/login');

        self::assertResponseRedirects();
    }

    public function testSigningOutIsIntercepted(): void
    {
        $this->seedWarden();
        /** @var User $user */
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'warden@example.test']);

        $this->client->loginUser($user);
        // The controller body throws; reaching it would be a 500, so a redirect
        // is the proof that the firewall's logout listener answered instead.
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('http://localhost/login');

        // And the session is gone: the guarded page bounces again.
        $this->client->request('GET', '/_guarded');
        self::assertResponseRedirects('http://localhost/login');
    }
}
