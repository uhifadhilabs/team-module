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

namespace Uhifadhi\Team\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Gives an entity a public, time-ordered UUIDv7 used for external addressing (URLs,
 * APIs) so the sequential integer primary key is never exposed. Generated on first
 * persist; the entity needs #[ORM\HasLifecycleCallbacks].
 *
 * COPIED, NOT SHARED, and deliberately: uhifadhi/seam-module carries its own
 * copy of this same twenty lines. A trait hoisted into module-contracts would
 * put a Doctrine mapping into a package whose whole point is that it depends on
 * nothing, and would make every module's schema hostage to a contracts release.
 * Twenty lines duplicated is cheaper than that coupling.
 */
trait UuidTrait
{
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $uuid = null;

    public function getUuid(): ?Uuid
    {
        return $this->uuid;
    }

    public function setUuid(Uuid $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
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
}
