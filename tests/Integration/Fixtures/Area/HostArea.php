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

namespace Uhifadhi\Team\Tests\Integration\Fixtures\Area;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\ModuleContracts\Entity\AreaInterface;

/**
 * THE HOST'S AREA, PLAYED BY A STAND-IN — the area an area-level department is
 * confined to.
 *
 * An area belongs to the host application; a real installation resolves
 * {@see AreaInterface} to its own entity with
 * `doctrine.orm.resolve_target_entities`, and `uhifadhi/area-module` is the
 * package that answers it. This module never resolves the interface itself — it
 * only points at it, exactly as its {@see \Uhifadhi\Team\Entity\Department}
 * points a nullable association at it. So the suites need a host to play, and
 * this is it: a real entity implementing the interface, resolved to by the two
 * test kernels alone (see TestKernel and the Resolution kernel).
 *
 * IT DELIBERATELY MIRRORS the seam module's own fixture area — the same shape a
 * real host area carries: a sequential id the association is built on, and a
 * public UUIDv7 for addressing. The interface asks for nothing but identity, so
 * everything past `getId()` is the host's business, present here only so a test
 * can name the area it confined a department to.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_host_area')]
#[ORM\HasLifecycleCallbacks]
class HostArea implements AreaInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function getUuidString(): ?string
    {
        return $this->uuid?->toRfc4122();
    }

    #[ORM\PrePersist]
    public function generateUuid(): void
    {
        $this->uuid ??= Uuid::v7();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
