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

namespace Uhifadhi\Team\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Team\Entity\User;

/**
 * THE ACCOUNT IS THE ANSWER OTHER MODULES RESOLVE TO. A module keeping a record
 * about a person points at `Uhifadhi\ModuleContracts\Entity\UserInterface` and
 * an installation resolves that interface to this class, so the two have to
 * agree — not only that the class carries the methods, but that they answer
 * what a module reading them expects.
 *
 * The contract is imported ALIASED here on purpose. `User` already implements
 * Symfony's `UserInterface`, the short names collide, and every consumer will
 * hit the same collision.
 */
#[CoversClass(User::class)]
final class UserContractTest extends TestCase
{
    public function testTheAccountImplementsTheModuleUserContract(): void
    {
        self::assertInstanceOf(ModuleUserInterface::class, new User());
    }

    public function testTheContractQuestionsAnswerFromTheAccount(): void
    {
        $user = new User();
        $user->setEmail('Asha.Mollel@Example.org')
            ->setFirstName('Asha')
            ->setLastName('Mollel')
            ->setRangerCode('SL-0142');

        $contract = $user;
        self::assertInstanceOf(ModuleUserInterface::class, $contract);

        // Lower-cased on the way in, and a module reads what was stored.
        self::assertSame('asha.mollel@example.org', $contract->getEmail());
        self::assertSame('Asha', $contract->getFirstName());
        self::assertSame('Mollel', $contract->getLastName());
        self::assertSame('Asha Mollel', $contract->getFullName());
        self::assertSame('sl-0142', $contract->getRangerCode());
    }

    /**
     * THE PUBLIC ADDRESS CROSSES THE BOUNDARY AS A STRING. The contract asks for
     * RFC 4122 text rather than a `Uuid` object so that depending on it costs
     * nothing; this account's uuid is a UUIDv7 and the string is that value.
     */
    public function testThePublicAddressIsTheRfc4122FormOfTheUuid(): void
    {
        $user = new User();

        self::assertNull($user->getUuidString(), 'An account that was never stored has no public address yet.');

        $uuid = Uuid::v7();
        $user->setUuid($uuid);

        self::assertSame($uuid->toRfc4122(), $user->getUuidString());
    }

    /**
     * The two questions an installation is allowed to answer with nothing:
     * office staff never get a service number, and an unstored account has no
     * database identity.
     */
    public function testTheNullableAnswersAreGenuinelyNullable(): void
    {
        $user = new User();
        $user->setFirstName('Asha')->setLastName('Mollel');

        self::assertNull($user->getId());
        self::assertNull($user->getRangerCode());

        $user->setRangerCode('  ');
        self::assertNull($user->getRangerCode(), 'A blank service number is no service number.');
    }
}
