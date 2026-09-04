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
 * ANY MODULE THAT KEEPS A RECORD WITH A NAME ON IT — who led the patrol, who
 * reported the incident, whose dashboard layout this is.
 *
 * It points at the CONTRACT and not at this bundle's `User`, which is the whole
 * arrangement: a module that type-hinted `Uhifadhi\Team\Entity\User` would be a
 * module no installation could run without this one.
 *
 * A FIXTURE RATHER THAN THE WIDGET MODULE'S OWN ENTITY, deliberately. Widget's
 * `WidgetPreference` does exactly this and is present in require-dev, so the
 * whole suite already proves the resolution works — but a test that asserted it
 * THROUGH widget would be a test about widget, and would go quiet the day this
 * module's dev dependencies changed. This class exists so the claim is about
 * team's own prepend and depends on nothing else.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_patrol_record')]
class PatrolRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /** The association the resolution has to answer for. */
    #[ORM\ManyToOne(targetEntity: ModuleUserInterface::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ModuleUserInterface $ledBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLedBy(): ?ModuleUserInterface
    {
        return $this->ledBy;
    }

    public function setLedBy(?ModuleUserInterface $ledBy): static
    {
        $this->ledBy = $ledBy;

        return $this;
    }
}
