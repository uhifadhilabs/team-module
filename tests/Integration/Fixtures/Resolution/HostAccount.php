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

namespace Uhifadhi\Team\Tests\Integration\Fixtures\Resolution;

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;

/**
 * AN INSTALLATION THAT HAS ITS OWN ACCOUNT CLASS — the escape hatch.
 *
 * This module prepends a resolution so that nobody has to write one, and
 * prepended configuration LOSES to the application's own by Symfony's design.
 * That is not a limitation being worked around: it is exactly the property that
 * makes the default safe to ship. An installation whose people are its own
 * entity says so in its `doctrine.yaml` and its answer wins, with nothing here
 * to disable first.
 *
 * It answers the contract's seven questions and nothing else, because that is
 * all the contract asks.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_host_account')]
class HostAccount implements ModuleUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 180)]
    private string $email = 'somebody@example.test';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuidString(): ?string
    {
        return null;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFirstName(): ?string
    {
        return 'Host';
    }

    public function getLastName(): ?string
    {
        return 'Account';
    }

    public function getFullName(): string
    {
        return 'Host Account';
    }

    public function getRangerCode(): ?string
    {
        return null;
    }
}
