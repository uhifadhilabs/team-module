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

namespace Uhifadhi\Team\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Team\Entity\Trait\TimestampableTrait;
use Uhifadhi\Team\Entity\Trait\UuidTrait;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Exception\UnknownPermissionException;
use Uhifadhi\Team\Repository\PositionRepository;

/**
 * A named position that bundles a set of granular permissions. An administrator
 * defines positions and ticks their permissions; every Staff user assigned a
 * position inherits them, and a Staff user with no position holds nothing at
 * all. Super Admin and Admin ignore positions — they hold everything by tier.
 *
 * A POSITION BELONGS TO A DEPARTMENT, AND ITS NAME IS UNIQUE ONLY INSIDE IT.
 * The previous release enforced `unique(name)` across the whole installation
 * and said in this very docblock that the rule it wanted was department-scoped
 * but could not be written, because a department was somebody else's entity.
 * It is this module's entity now ({@see Department}) for exactly that reason: a
 * constraint cannot be spelled across a module boundary. So the index is
 * `unique(department, name)`, and *Ecology / Analyst* and *Protection Service /
 * Analyst* are two jobs that share a word.
 *
 * The consequence reaches every screen and is not negotiable there: a position
 * is written DEPARTMENT-FIRST, never as a bare name. A bare "Analyst" is not a
 * shorter way of saying the same thing — it is a different and ambiguous thing.
 *
 * THE DEPARTMENT IS NULLABLE, and the null is a state rather than an unfinished
 * field: a position created before anybody decided which department owns it is
 * a position that exists, and the roster's Unassigned band is where its holders
 * appear. Postgres treats NULL as distinct in a unique index, so two
 * department-less positions may share a name — which is the honest behaviour
 * for rows nobody has filed yet, and it resolves itself the moment they are.
 */
#[ORM\Entity(repositoryClass: PositionRepository::class)]
#[ORM\Table(name: 'team_position')]
#[ORM\UniqueConstraint(name: 'uniq_team_position_department_name', fields: ['department', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Position
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    /** Nullable: the Unassigned state is real. See the class banner. */
    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'positions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    /**
     * The granted permissions, stored as plain strings — core values and
     * module-declared ones alike, in the order they were granted.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    /** Reserved: a position whose label is fixed. Unused today. */
    #[ORM\Column]
    private bool $locked = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    /**
     * The way this position is written anywhere a person reads it:
     * "Protection Service / Analyst", or the bare name where nothing owns it
     * yet. Here rather than in a template, because every screen has to spell it
     * the same way and a bare name is ambiguous by construction.
     */
    public function getQualifiedName(): string
    {
        $name = $this->name ?? '';

        return null !== $this->department
            ? $this->department->getName().' / '.$name
            : $name;
    }

    /**
     * THE RAW GRANTED VALUES — the only reading surface, because it is the only
     * one that can tell the truth. An enum-typed accessor drops every
     * module-declared permission on the floor, since a module's value is not a
     * case of an enum this module owns.
     *
     * @return list<string>
     */
    public function getPermissionValues(): array
    {
        return $this->permissions;
    }

    /**
     * THE ONLY WRITE PATH, AND IT VALIDATES.
     *
     * There used to be a second one, `setPermissions(PermissionEnum[])`, and it
     * was the obvious one to reach for. It silently discarded everything a
     * module had declared, so an administrator could tick a patrol module's row,
     * save, and watch it come back unticked with nothing anywhere saying why.
     * It is removed rather than deprecated: a call site left compiling is the
     * bug still shipping.
     *
     * The live catalogue is a REQUIRED second argument rather than something
     * this entity fetches, because an entity that reached for a service to
     * validate itself would be an entity you cannot construct in a test — and
     * because making it required is what stops the unvalidated call from
     * existing at all.
     *
     * WHAT IS ACCEPTED is the live catalogue UNION the strings this position
     * already holds, and the union is the design:
     *
     *   · the catalogue half makes an unknown NEW string fail loudly, which is
     *     precisely what the old setter did not do;
     *   · the already-held half is the prune-not-purge ruling in code. A module
     *     uninstalled last week left grants behind in positions' JSON; those
     *     values are in nobody's catalogue now, and saving an unrelated change
     *     to the position must not quietly strip them. Editing a position is
     *     not a migration. They stay, they stop resolving, the matrix draws
     *     them muted, and revoking one still works — it is a grant, not a
     *     fixture.
     *
     * @param list<string> $values    what the position should hold after this call
     * @param list<string> $catalogue every permission value this installation
     *                                currently offers ({@see \Uhifadhi\Team\Service\PermissionCatalogue::values()})
     *
     * @throws UnknownPermissionException if a submitted value is neither in the
     *                                    catalogue nor already granted here
     */
    public function setPermissionValues(array $values, array $catalogue): static
    {
        $accepted = [...$catalogue, ...$this->permissions];

        $unknown = array_values(array_unique(array_filter(
            $values,
            static fn (string $value): bool => !\in_array($value, $accepted, true),
        )));

        if ([] !== $unknown) {
            throw new UnknownPermissionException($unknown);
        }

        $this->permissions = array_values(array_unique($values));

        return $this;
    }

    public function hasPermissionValue(string $value): bool
    {
        return \in_array($value, $this->permissions, true);
    }

    /** A convenience for the one caller that genuinely holds an enum case: the voter's own tests and core code. */
    public function hasPermission(PermissionEnum $permission): bool
    {
        return $this->hasPermissionValue($permission->value);
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): static
    {
        $this->locked = $locked;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getQualifiedName();
    }
}
