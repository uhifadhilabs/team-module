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

namespace Uhifadhi\Team\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * HOW THE FIRST ADMINISTRATOR EXISTS.
 *
 * Every other way into this module needs somebody already signed in: the invite
 * screen is behind `team.manage`, and `team.manage` is held through a position
 * somebody has to have granted. A fresh installation therefore has exactly one
 * door, and it is a console command — which is also the only door that works
 * before there is a browser session, a mailer, or a single row in `team_user`.
 *
 * THE TIER DEFAULTS TO SUPER ADMIN WHEN THE INSTALLATION IS EMPTY, and to Staff
 * afterwards. The first account has to be able to administer the team or the
 * command has solved nothing; the tenth almost certainly should not, and a
 * command that kept minting Super Admins by default would be one whose safest
 * answer is the wrong one.
 *
 * THE PASSWORD IS NEVER AN ARGUMENT. A password on a command line is a password
 * in the shell history and in the process list, so it is prompted for, hidden,
 * and confirmed.
 */
final class CreateUserCommandTest extends IntegrationTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));

        return new CommandTester($application->find('team:user:create'));
    }

    private function users(): UserRepository
    {
        return $this->service(UserRepository::class);
    }

    public function testItIsRegisteredAsAConsoleCommand(): void
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));

        self::assertTrue($application->has('team:user:create'));
    }

    /**
     * THE FIRST ACCOUNT. Everything is typed at the prompts, the tier is offered
     * as Super Admin because nobody exists yet, and the password is confirmed.
     */
    public function testTheFirstAccountIsASuperAdminByDefault(): void
    {
        $tester = $this->tester();
        $tester->setInputs([
            'Naomi@Example.test',
            'Naomi',
            'Kileo',
            '',                       // accept the offered tier
            'a-long-enough-password',
            'a-long-enough-password',
        ]);

        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);

        $stored = $this->users()->findOneByEmail('naomi@example.test');
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(TeamRoleEnum::SuperAdmin, $stored->getTeamRole());
        self::assertSame('Naomi Kileo', $stored->getFullName());
        // Made by hand at a console, so there is nothing to verify by email.
        self::assertTrue($stored->isVerified());
        self::assertTrue($stored->isActive());
        // Nobody invited them. The null is the honest difference between the
        // two ways an account comes to exist.
        self::assertNull($stored->getInvitedAt());
    }

    public function testThePasswordIsStoredAsAHashTheFirewallVerifies(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['boss@example.test', 'B', 'O', '', 'a-long-enough-password', 'a-long-enough-password']);
        $tester->execute([]);

        $stored = $this->users()->findOneByEmail('boss@example.test');
        self::assertInstanceOf(User::class, $stored);

        $hasher = static::getContainer()->get('test_public.hasher');
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        self::assertNotSame('a-long-enough-password', $stored->getPassword());
        self::assertTrue($hasher->isPasswordValid($stored, 'a-long-enough-password'));
    }

    /**
     * ONCE SOMEBODY EXISTS THE DEFAULT DROPS TO STAFF. The command's safest
     * answer must not be the one that mints a second installation-wide
     * administrator.
     */
    public function testAfterTheFirstAccountTheDefaultTierIsStaff(): void
    {
        $this->em->persist(
            (new User())->setEmail('first@example.test')->setFirstName('F')->setLastName('T')
                ->setPassword('x')->setTeamRole(TeamRoleEnum::SuperAdmin),
        );
        $this->em->flush();

        $tester = $this->tester();
        $tester->setInputs(['second@example.test', 'S', 'C', '', 'a-long-enough-password', 'a-long-enough-password']);
        $tester->execute([]);

        $stored = $this->users()->findOneByEmail('second@example.test');
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(TeamRoleEnum::Staff, $stored->getTeamRole());
    }

    public function testATierMayBeChosen(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a@example.test', 'A', 'D', 'admin', 'a-long-enough-password', 'a-long-enough-password']);
        $tester->execute([]);

        $stored = $this->users()->findOneByEmail('a@example.test');
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(TeamRoleEnum::Admin, $stored->getTeamRole());
    }

    /**
     * A DUPLICATE EMAIL IS A CLEAR ERROR AND NOT A CONSTRAINT VIOLATION. The
     * unique index would have caught it, in a wall of SQL; the person running
     * this command wants a sentence.
     */
    public function testADuplicateEmailFailsWithSomethingWorthReading(): void
    {
        $this->em->persist(
            (new User())->setEmail('taken@example.test')->setFirstName('T')->setLastName('K')->setPassword('x'),
        );
        $this->em->flush();

        $tester = $this->tester();
        $tester->setInputs(['Taken@example.test', 'T', 'W', '', 'a-long-enough-password', 'a-long-enough-password']);

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        $output = $tester->getDisplay();
        self::assertStringContainsString('taken@example.test', $output);
        self::assertStringContainsString('already', strtolower($output));
        self::assertStringNotContainsString('SQLSTATE', $output);
    }

    /** The email folds on the way in, so a capitalised retry is caught as the same person. */
    public function testTheEmailIsStoredFolded(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['MiXeD@Example.TEST', 'M', 'X', '', 'a-long-enough-password', 'a-long-enough-password']);
        $tester->execute([]);

        self::assertInstanceOf(User::class, $this->users()->findOneByEmail('mixed@example.test'));
    }

    /** Non-interactive is refused rather than half-guessed: there is no password to invent. */
    public function testItRefusesToRunWithNoWayToAsk(): void
    {
        $tester = $this->tester();

        $status = $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('interactive', strtolower($tester->getDisplay()));
    }
}
