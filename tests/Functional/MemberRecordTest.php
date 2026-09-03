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

use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;

/**
 * ONE PERSON'S RECORD, and the four things it can be asked to change.
 *
 * The states worth asserting are the ones the design argues about: the REFUSAL
 * on the last active Super Admin — printed with its reason where the control
 * would have been, rather than greyed out — the deliberately ABSENT delete, the
 * SA-grant warning at the grant, and the invitation facts line, which appears
 * only beside "never signed in" and reads differently for an account nobody
 * invited.
 */
final class MemberRecordTest extends WebTestCaseWithSchema
{
    /** A second Super Admin, so the invariant is not in the way of ordinary tests. */
    private function withSuccessor(): User
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->person('Asha', 'Mollel', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        return $naomi;
    }

    public function testTheRecordRenders(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertSame('Grace Ndosi', $crawler->filter('h1.pg')->text());
    }

    /** THERE IS NO DELETE, and the page says so where a delete would be. */
    public function testDeleteIsDrawnAsDeliberatelyAbsent(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertStringContainsString('There is no such action, and there is no button for it', $crawler->html());
        self::assertStringContainsString('deliberately absent', $crawler->filter('.mb-drow.absent')->text());
    }

    public function testThereIsNoDeleteRouteEither(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $this->client->request('POST', '/team/'.$grace->getUuidString().'/delete');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeactivatingKeepsTheRowAndStampsTheMoment(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString());
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/deactivate', ['_token' => $token]);

        self::assertResponseRedirects();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored, 'The row is not deleted.');
        self::assertFalse($stored->isActive());
        self::assertNotNull($stored->getDisabledAt());

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Nothing has been deleted', $crawler->html());
    }

    public function testReactivatingClearsTheStamp(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $grace->deactivate();
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString());
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/reactivate', ['_token' => $token]);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'g.ndosi@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($stored->isActive());
        self::assertNull($stored->getDisabledAt());
    }

    /**
     * THE REFUSAL, PRINTED WITH ITS REASON — not a greyed-out control that says
     * "not now" and leaves the reader guessing.
     */
    public function testTheLastActiveSuperAdminSeesTheRefusalWithItsReason(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString());

        self::assertCount(1, $crawler->filter('.mb-refuse'));
        self::assertStringContainsString('it is refused, with the reason', $crawler->filter('.mb-refuse')->text());
        self::assertStringContainsString('only active Super Admin', $crawler->filter('.mb-refuse')->text());
        // And the deactivate control is replaced by the refusal, not disabled.
        self::assertStringContainsString('refused — the last active Super Admin', $crawler->html());
    }

    public function testDemotingTheLastActiveSuperAdminIsRefusedOnSubmitToo(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        $token = $this->tokenFrom('/team/'.$naomi->getUuidString());
        $this->client->request('POST', '/team/'.$naomi->getUuidString().'/tier', [
            '_token' => $token, 'tier' => 'staff',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('only active Super Admin', $crawler->html());

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'n.kileo@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(TeamRoleEnum::SuperAdmin, $stored->getTeamRole(), 'Nothing was written.');
    }

    public function testDeactivatingTheLastActiveSuperAdminIsRefusedOnSubmitToo(): void
    {
        $naomi = $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $this->em->flush();
        $this->client->loginUser($naomi);

        $token = $this->tokenFrom('/team/'.$naomi->getUuidString());
        $this->client->request('POST', '/team/'.$naomi->getUuidString().'/deactivate', ['_token' => $token]);

        $this->client->followRedirect();
        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'n.kileo@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertTrue($stored->isActive(), 'Nothing was written.');
    }

    /** TRANSFER, THEN LEAVE — and the refusal lifts in the same moment. */
    public function testGrantingItToASuccessorLiftsTheRefusal(): void
    {
        $naomi = $this->withSuccessor();

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString());

        self::assertCount(0, $crawler->filter('.mb-refuse'));
    }

    /** THE WARNING LIVES AT THE GRANT — beside somebody who is not yet one. */
    public function testTheSuperAdminGrantCarriesItsWarning(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertCount(1, $crawler->filter('.mb-grant'));
        self::assertStringContainsString('every permission you hold', $crawler->filter('.mb-grant')->text());
        self::assertStringContainsString('transfer before you leave', strtolower($crawler->filter('.mb-grant')->text()));
    }

    /** And it is absent where there is nothing to warn about. */
    public function testSomebodyAlreadySuperAdminGetsNoGrantWarning(): void
    {
        $naomi = $this->withSuccessor();

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString());

        self::assertCount(0, $crawler->filter('.mb-grant'));
    }

    /**
     * THE INVITATION FACTS LINE reads differently for the two ways an account
     * comes to exist, and appears only beside "never signed in".
     */
    public function testAnInvitedAccountNamesWhoInvitedThem(): void
    {
        $naomi = $this->withSuccessor();
        $joseph = $this->person('Joseph', 'Mrema')->setVerified(false);
        $joseph->markInvitedBy($naomi);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$joseph->getUuidString());

        self::assertStringContainsString('invited by Naomi Kileo', $crawler->filter('.mb-state')->text());
    }

    public function testAnAccountCreatedDirectlySaysNobodyInvitedThem(): void
    {
        $this->withSuccessor();
        $hawa = $this->person('Hawa', 'Rajabu')->setVerified(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$hawa->getUuidString());

        self::assertStringContainsString('Nobody invited them', $crawler->filter('.mb-state')->text());
        self::assertStringContainsString('nothing to resend', $crawler->filter('.mb-state')->text());
    }

    public function testSomebodyWhoHasArrivedGetsNoInvitationLine(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertStringContainsString('Signed in and verified', $crawler->filter('.mb-state')->text());
        self::assertStringNotContainsString('invited by', $crawler->filter('.mb-state')->text());
    }

    /**
     * "WHAT THAT ACTUALLY GRANTS, RIGHT NOW" — every catalogue row, with the
     * reason on it. For somebody above the matrix every row reads "by tier".
     */
    public function testTheEffectiveLedgerSaysWhyOnEveryRow(): void
    {
        $this->withSuccessor();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), ['area.view']);
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($ranger);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/'.$grace->getUuidString());

        self::assertStringContainsString('by position', $crawler->filter('.pm-eff')->text());
        self::assertStringContainsString('not held', $crawler->filter('.pm-eff')->text());
        // And the sentence under each name is there too.
        self::assertStringContainsString(PermissionEnum::AreaView->description(), $crawler->filter('.pm-eff')->html());
    }

    public function testATierAboveTheMatrixReadsByTierOnEveryRow(): void
    {
        $naomi = $this->withSuccessor();

        $crawler = $this->client->request('GET', '/team/'.$naomi->getUuidString());

        self::assertStringNotContainsString('by position', $crawler->filter('.pm-eff')->text());
        self::assertStringContainsString('by tier', $crawler->filter('.pm-eff')->text());
        self::assertStringContainsString('Changing the position below changes nothing they can do', $crawler->html());
    }

    public function testAssigningAPositionWritesIt(): void
    {
        $this->withSuccessor();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), ['area.view']);
        $frank = $this->person('Frank', 'Massawe');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$frank->getUuidString());
        $this->client->request('POST', '/team/'.$frank->getUuidString().'/position', [
            '_token' => $token, 'position' => $ranger->getUuidString(),
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['email' => 'f.massawe@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Protection Service / Ranger', $stored->getPosition()?->getQualifiedName());
    }

    /** "No position" is a real choice, and the flash says what it costs. */
    public function testTakingThePositionAwayIsARealChoice(): void
    {
        $this->withSuccessor();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), ['area.view']);
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($ranger);
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString());
        $this->client->request('POST', '/team/'.$grace->getUuidString().'/position', [
            '_token' => $token, 'position' => '',
        ]);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('no permissions at all', $crawler->html());
    }

    public function testTheRecordFieldsSave(): void
    {
        $this->withSuccessor();
        $grace = $this->person('Grace', 'Ndosi');
        $this->em->flush();

        $token = $this->tokenFrom('/team/'.$grace->getUuidString());
        $this->client->request('POST', '/team/'.$grace->getUuidString(), [
            '_token' => $token,
            'firstName' => 'Grace',
            'lastName' => 'Ndosi-Mwangi',
            'email' => 'G.Ndosi@Example.TEST',
            'rangerCode' => 'R-104',
        ]);

        $this->em->clear();
        $stored = $this->em->getRepository(User::class)->findOneBy(['rangerCode' => 'r-104']);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame('Grace Ndosi-Mwangi', $stored->getFullName());
        self::assertSame('g.ndosi@example.test', $stored->getEmail(), 'The entity folds the email itself.');
    }

    /** Impersonation is offered to a Super Admin only; for anybody else, absent. */
    public function testSwitchUserIsAbsentForAnAdministratorWhoIsNotASuperAdmin(): void
    {
        $this->person('Naomi', 'Kileo', TeamRoleEnum::SuperAdmin);
        $senior = $this->position('Senior Ranger', $this->department('Protection Service'), ['team.manage']);
        $grace = $this->person('Grace', 'Ndosi');
        $grace->setPosition($senior);
        $target = $this->person('Zawadi', 'Naisenya');
        $this->em->flush();
        $this->client->loginUser($grace);

        $crawler = $this->client->request('GET', '/team/'.$target->getUuidString());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('_switch_user', $crawler->html());
    }
}
