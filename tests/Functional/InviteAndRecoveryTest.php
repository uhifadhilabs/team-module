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

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\TeamRoleEnum;

/**
 * BOTH WAYS IN, AND THE THREE SCREENS A STRANGER REACHES.
 *
 * THE SUITE RUNS WITH NO MAILER, which is not a limitation of the test kernel
 * but the state this module cares most about being honest in: a fresh
 * installation has no transport, and what the product does then is the thing
 * that was ruled. Invite-by-email is OFFERED AND REFUSED — form visible, button
 * inert, reason written on it — and the forgot-password screen says the
 * installation cannot send rather than swallowing the request.
 *
 * The path that needs nothing from the deployment is exercised end to end, and
 * so is the invitation-acceptance screen, whose token this suite mints directly
 * because with no mailer nothing would ever post one.
 */
final class InviteAndRecoveryTest extends WebTestCaseWithSchema
{
    public function testTheInvitePageOffersBothWays(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/invite');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.iv-way'), 'Both ways ship, side by side.');
        self::assertStringContainsString('Create with a password', $crawler->html());
        self::assertStringContainsString('Invite by email', $crawler->html());
    }

    /**
     * THE PATH THAT NEEDS NOTHING IS ALWAYS AVAILABLE — and it says so, beside
     * the one that is not.
     */
    public function testTheCreatePathIsMarkedAlwaysAvailable(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/invite');

        self::assertStringContainsString('always available', $crawler->filter('.iv-way')->first()->text());
    }

    /**
     * OFFERED AND REFUSED, NOT HIDDEN. Hiding it would leave an administrator
     * hunting for a feature the product does have; failing silently after the
     * click would leave a colleague waiting for an email nobody sent.
     */
    public function testWithNoMailerTheInvitePathIsOfferedAndRefusedInWriting(): void
    {
        $this->administrator();
        $crawler = $this->client->request('GET', '/team/invite');

        // The form is THERE.
        self::assertCount(1, $crawler->filter('.iv-way[data-mailer="off"] form'));
        // The reason is written on it.
        self::assertStringContainsString('offered and refused', $crawler->filter('.iv-nomailer')->text());
        self::assertStringContainsString('not hidden', $crawler->filter('.iv-nomailer')->text());
        self::assertStringContainsString('MAILER_DSN', $crawler->filter('.iv-nomailer')->text());
        // And the button is inert rather than absent.
        self::assertNotNull($crawler->filter('.iv-way[data-mailer="off"] button[type="submit"]')->attr('disabled'));
    }

    /** A POST that arrives anyway gets the same sentence, not a silent success. */
    public function testAnInviteWithNoMailerIsRefusedRatherThanSwallowed(): void
    {
        $this->administrator();
        $token = $this->tokenFrom('/team/invite', '.iv-way[data-mailer="off"] input[name="_token"]');

        $this->client->request('POST', '/team/invite', [
            '_token' => $token, 'email' => 'h.rajabu@example.test',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('no mail transport configured', $crawler->html());

        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(User::class)->findOneBy(['email' => 'h.rajabu@example.test']),
            'No half-made account is left behind.',
        );
    }

    public function testCreatingSomebodyWithAPasswordWorksWithNothingConfigured(): void
    {
        $this->administrator();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), ['area.view']);
        $this->em->flush();

        $token = $this->tokenFrom('/team/invite');
        $this->client->request('POST', '/team', [
            '_token' => $token,
            'firstName' => 'Joseph',
            'lastName' => 'Mrema',
            'email' => 'J.Mrema@Example.test',
            'rangerCode' => 'R-121',
            'password' => 'a-long-enough-password',
            'position' => $ranger->getUuidString(),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'j.mrema@example.test']);
        self::assertInstanceOf(User::class, $stored);

        self::assertSame('Joseph Mrema', $stored->getFullName());
        self::assertSame('r-121', $stored->getRangerCode());
        // VERIFIED THE MOMENT THEY EXIST: an administrator who typed the
        // password has already proved the account is real.
        self::assertTrue($stored->isVerified());
        // AND NOBODY INVITED THEM. The null is the honest difference between
        // the two paths, and the roster reads it.
        self::assertNull($stored->getInvitedAt());
        self::assertSame('Protection Service / Ranger', $stored->getPosition()?->getQualifiedName());

        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($stored, 'a-long-enough-password'));
    }

    public function testAShortPasswordIsRefusedWithTheRule(): void
    {
        $this->administrator();
        $token = $this->tokenFrom('/team/invite');

        $this->client->request('POST', '/team', [
            '_token' => $token, 'firstName' => 'J', 'lastName' => 'M',
            'email' => 'short@example.test', 'password' => 'short',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('at least 12 characters', $crawler->html());
        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'short@example.test']));
    }

    public function testADuplicateEmailIsRefusedWithASentence(): void
    {
        $this->administrator();
        $token = $this->tokenFrom('/team/invite');

        $this->client->request('POST', '/team', [
            '_token' => $token, 'firstName' => 'N', 'lastName' => 'K',
            'email' => 'N.Kileo@example.test', 'password' => 'a-long-enough-password',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('already exists', $crawler->html());
        self::assertStringNotContainsString('SQLSTATE', $crawler->html());
    }

    // ─── THE THREE SCREENS A STRANGER REACHES ────────────────────────────

    /**
     * WITH NO MAILER THE FORGOT SCREEN SAYS SO. A fresh installation is in
     * exactly this state, and a silently discarded reset is the worst failure
     * this flow has.
     */
    public function testForgotPasswordWithNoMailerIsHonestAboutIt(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('cannot send email yet', $crawler->html());
        self::assertStringContainsString('MAILER_DSN', $crawler->filter('.auth-nomail')->text());
        // No form to fill in that would go nowhere.
        self::assertCount(0, $crawler->filter('input[name="email"]'));
    }

    /** It is on the DOCUMENT rung: no sidebar, no top bar, nothing to navigate. */
    public function testTheRecoveryScreensCarryNoFurniture(): void
    {
        $crawler = $this->client->request('GET', '/reset-password');

        self::assertCount(0, $crawler->filter('aside'));
        self::assertCount(0, $crawler->filter('.topbar'));
        self::assertCount(1, $crawler->filter('main.auth .auth-card'));
    }

    /** And it is reachable by somebody who is not signed in — that is the point. */
    public function testItIsReachableWithNoSession(): void
    {
        $this->client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
    }

    /**
     * A RESET LINK THAT IS PAST ITS HOUR OR ALREADY SPENT GETS ONE ANSWER.
     * Telling a visitor which it was tells whoever holds a stolen link
     * something about the account.
     */
    public function testAnExpiredResetLinkAndAUsedOneReadTheSame(): void
    {
        $grace = $this->person('Grace', 'Ndosi');
        $stale = str_repeat('a', 64);
        $grace->setPasswordResetToken($stale);
        $grace->setPasswordResetRequestedAt(new \DateTimeImmutable('-2 hours'));
        $this->em->flush();

        $expired = $this->client->request('GET', '/reset-password/'.$stale);
        $unknown = $this->client->request('GET', '/reset-password/'.str_repeat('b', 64));

        self::assertStringContainsString('That link has expired', $expired->html());
        self::assertStringContainsString('That link has expired', $unknown->html());
        self::assertStringContainsString('past its hour, or it has already been used', $expired->filter('.auth-stale')->text());
    }

    /** A live link names WHO IT IS FOR from the token, as text and not a field. */
    public function testALiveResetLinkNamesItsPersonFromTheToken(): void
    {
        $grace = $this->person('Grace', 'Ndosi');
        $token = str_repeat('c', 64);
        $grace->setPasswordResetToken($token);
        $grace->setPasswordResetRequestedAt(new \DateTimeImmutable());
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reset-password/'.$token);

        self::assertStringContainsString('Grace Ndosi', $crawler->filter('.auth-for')->text());
        self::assertCount(0, $crawler->filter('input[name="email"]'), 'Who it is for is read from the token, never from a field.');
        self::assertStringContainsString('signs every other session of this account out', $crawler->html());
    }

    /** Setting the password spends the token — SINGLE USE — and verifies the account. */
    public function testUsingAResetLinkSetsThePasswordAndSpendsTheToken(): void
    {
        $grace = $this->person('Grace', 'Ndosi')->setVerified(false);
        $token = str_repeat('d', 64);
        $grace->setPasswordResetToken($token);
        $grace->setPasswordResetRequestedAt(new \DateTimeImmutable());
        $this->em->flush();

        $csrf = $this->tokenFrom('/reset-password/'.$token);
        $this->client->request('POST', '/reset-password/'.$token, [
            '_token' => $csrf,
            'password' => 'a-long-enough-password',
            'password_confirm' => 'a-long-enough-password',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Every other session signed out', $this->client->getResponse()->getContent() ?: '');

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertNull($stored->getPasswordResetToken(), 'Single use: consuming it spends it.');
        self::assertNull($stored->getPasswordResetRequestedAt());
        self::assertTrue($stored->isVerified());

        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid($stored, 'a-long-enough-password'));

        // And the same link does not work twice.
        $again = $this->client->request('GET', '/reset-password/'.$token);
        self::assertStringContainsString('That link has expired', $again->html());
    }

    public function testTheTwoPasswordsHaveToMatch(): void
    {
        $grace = $this->person('Grace', 'Ndosi');
        $token = str_repeat('e', 64);
        $grace->setPasswordResetToken($token);
        $grace->setPasswordResetRequestedAt(new \DateTimeImmutable());
        $this->em->flush();

        $csrf = $this->tokenFrom('/reset-password/'.$token);
        $this->client->request('POST', '/reset-password/'.$token, [
            '_token' => $csrf,
            'password' => 'a-long-enough-password',
            'password_confirm' => 'a-different-password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('not the same', $this->client->getResponse()->getContent() ?: '');
    }

    // ─── ACCEPTING AN INVITATION ─────────────────────────────────────────

    public function testAnOpenInvitationAsksForTheNameAndSaysWhoInvited(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $invited = (new User())
            ->setEmail('h.rajabu@example.test')->setFirstName('')->setLastName('')
            ->setPassword('unusable')->setVerified(false)->setVerificationToken(str_repeat('f', 64));
        $invited->markInvitedBy($naomi);
        $this->em->persist($invited);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/invite/'.str_repeat('f', 64));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('h.rajabu@example.test', $crawler->filter('.auth-for')->text());
        self::assertStringContainsString('invited by Naomi Kileo', $crawler->filter('.auth-for')->text());
        self::assertCount(1, $crawler->filter('input[name="name"]'), 'The name is asked, never guessed.');
    }

    public function testAcceptingSetsTheNameAndPasswordAndSpendsTheInvitation(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $token = str_repeat('9', 64);
        $invited = (new User())
            ->setEmail('h.rajabu@example.test')->setFirstName('')->setLastName('')
            ->setPassword('unusable')->setVerified(false)->setVerificationToken($token);
        $invited->markInvitedBy($naomi);
        $this->em->persist($invited);
        $this->em->flush();

        $csrf = $this->tokenFrom('/invite/'.$token);
        $this->client->request('POST', '/invite/'.$token, [
            '_token' => $csrf, 'name' => 'Hawa Rajabu', 'password' => 'a-long-enough-password',
        ]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'h.rajabu@example.test']);
        self::assertInstanceOf(User::class, $stored);

        self::assertSame('Hawa Rajabu', $stored->getFullName());
        self::assertTrue($stored->isVerified(), 'Setting a password is what marks the account verified.');
        self::assertNull($stored->getVerificationToken(), 'The invitation is spent.');
        // The invitation facts survive: they are how the account came to exist.
        self::assertNotNull($stored->getInvitedAt());
    }

    public function testASpentInvitationSaysSoRatherThanOfferingTheFormAgain(): void
    {
        $token = str_repeat('8', 64);
        $arrived = $this->person('Hawa', 'Rajabu')->setVerificationToken($token);
        $arrived->setVerified(true);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/invite/'.$token);

        self::assertStringContainsString('no longer open', $crawler->html());
        self::assertCount(0, $crawler->filter('input[name="password"]'));
    }
}
