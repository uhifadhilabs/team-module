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

namespace Uhifadhi\Team\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Repository\UserRepository;

/**
 * HOW THE FIRST ADMINISTRATOR EXISTS.
 *
 * Every other way into this module needs somebody already signed in. The invite
 * screen is behind `team.manage`; `team.manage` is held through a position
 * somebody had to grant; granting it needs an account that can administer the
 * team. A fresh installation is therefore a closed loop with exactly one door,
 * and it is this — the only door that also works before there is a browser
 * session, a configured mailer, or a single row in `team_user`.
 *
 * INTERACTIVE, AND ONLY INTERACTIVE. There are no arguments and no options, and
 * that is a decision rather than an omission: the one value this command must
 * take is a password, and a password passed on a command line is a password in
 * the shell history, in the process list, and in whatever shipped that host's
 * logs. So every field is prompted for, the password is hidden and confirmed,
 * and `--no-interaction` fails with a sentence instead of inventing anything.
 *
 * THE TIER DEFAULT MOVES. On an empty installation it offers Super Admin,
 * because an account that cannot administer the team would leave the loop
 * closed. Once anybody exists it offers Staff. A command whose safest answer
 * kept minting installation-wide administrators would be a command whose safest
 * answer is the wrong one.
 *
 * THE ACCOUNT IS VERIFIED ON CREATION. Verification exists to prove somebody
 * controls an address; whoever ran this command was at the server's console,
 * which is a stronger proof than an email round-trip. And `invitedAt` stays
 * NULL — nobody invited them — which is the honest difference between the two
 * ways an account comes to exist, and one the roster reads.
 */
#[AsCommand(
    name: 'team:user:create',
    description: 'Create a staff account — the way the first administrator of a fresh installation exists.',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('team:user:create is interactive only. It asks for a password, and a password given on a command line ends up in the shell history and the process list — so there is deliberately no option to pass one. Run it without --no-interaction.');

            return Command::FAILURE;
        }

        $first = $this->users->isEmpty();
        $io->title($first
            ? 'The first account on this installation'
            : 'A new staff account');

        if ($first) {
            $io->text([
                'Nobody exists here yet, so this account is offered the Super Admin tier:',
                'somebody has to be able to administer the team before anybody else can be',
                'added through the product.',
            ]);
        }

        $email = strtolower($this->askRequired($io, 'Email — the sign-in identifier', 'An email address'));

        // ASKED BEFORE ANYTHING ELSE IS TYPED. The unique index would catch this
        // at the flush, in a wall of SQL; the person at this prompt wants a
        // sentence, and wants it before they have chosen a password.
        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(\sprintf('An account with the email %s already exists on this installation. Emails are the sign-in identifier and are folded to lower case, so a different capitalisation is the same person. Use a different address, or reset that account\'s password from its own record.', $email));

            return Command::FAILURE;
        }

        $firstName = $this->askRequired($io, 'First name', 'A first name');
        $lastName = $this->askRequired($io, 'Last name', 'A last name');

        $default = $first ? TeamRoleEnum::SuperAdmin : TeamRoleEnum::Staff;
        $tierValue = $io->choice(
            'Tier',
            array_combine(
                array_map(static fn (TeamRoleEnum $t): string => $t->value, TeamRoleEnum::cases()),
                array_map(static fn (TeamRoleEnum $t): string => $t->label().' — '.$t->grants(), TeamRoleEnum::cases()),
            ),
            $default->value,
        );
        $tier = TeamRoleEnum::from(\is_string($tierValue) ? $tierValue : $default->value);

        $password = $this->askPassword($io);
        $again = $io->askHidden('Password again (hidden)');
        if ($password !== $again) {
            $io->error('The two passwords are not the same. Nothing has been created — run the command again.');

            return Command::FAILURE;
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setTeamRole($tier)
            // Whoever ran this was at the server's console, which proves more
            // about the account than an email round-trip would.
            ->setVerified(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('%s can now sign in at /login as %s.', $user->getFullName(), $email));

        if (TeamRoleEnum::Staff === $tier) {
            $io->note('Staff hold exactly what their position grants, and this account has no position yet — so it can sign in and do nothing at all. Assign one from the team page.');
        }

        return Command::SUCCESS;
    }

    /**
     * A NON-EMPTY ANSWER, refused at the prompt rather than stored blank. The
     * columns are NOT NULL, so an empty name would fail at the flush with a
     * driver message nobody should have to read.
     *
     * The validator re-asks rather than aborting, which is Console's own
     * behaviour and the right one: a typo at the second of five prompts should
     * not throw away the three answers already given.
     */
    private function askRequired(SymfonyStyle $io, string $label, string $what): string
    {
        $answer = $io->ask($label, null, static function (mixed $given) use ($what): string {
            $given = \is_string($given) ? trim($given) : '';
            if ('' === $given) {
                throw new \RuntimeException($what.' is required.');
            }

            return $given;
        });

        return \is_string($answer) ? $answer : '';
    }

    /**
     * The one rule this module enforces on a password, and it is a length
     * ({@see User::PASSWORD_MIN_LENGTH}). The self-service screens state the
     * same floor, so the doors into an account cannot disagree about what a
     * password has to be.
     */
    private function askPassword(SymfonyStyle $io): string
    {
        $answer = $io->askHidden('Password (hidden)', static function (mixed $given): string {
            $given = \is_string($given) ? $given : '';
            if (mb_strlen($given) < User::PASSWORD_MIN_LENGTH) {
                throw new \RuntimeException(\sprintf('A password must be at least %d characters.', User::PASSWORD_MIN_LENGTH));
            }

            return $given;
        });

        return \is_string($answer) ? $answer : '';
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
            Creates a staff account from the console — the way the FIRST administrator of a
            fresh installation comes to exist, before there is anybody who could add them
            through the product.

            Everything is asked at a prompt. There is deliberately no way to pass the
            password as an argument: it would end up in the shell history and the process
            list. On an empty installation the tier is offered as Super Admin; after that,
            as Staff.
            HELP);
    }
}
