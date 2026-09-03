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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Team\Entity\Trait\TimestampableTrait;
use Uhifadhi\Team\Entity\Trait\UuidTrait;
use Uhifadhi\Team\Repository\DepartmentRepository;

/**
 * A part of the organisation that owns positions — Ecology, Protection Service,
 * Administration.
 *
 * THIS MODULE OWNS IT, and the previous release argued that it should not. A
 * department was called an organizational lens belonging to whichever ring
 * introduced it, and a position's name was therefore unique across the whole
 * installation. That was wrong about the organisations this product is for.
 * Ecology has an Analyst and Protection Service has an Analyst: two different
 * jobs, different permission sets, one word between them. A model that forbade
 * the pair would force one of them to be renamed to something nobody in the
 * building says out loud.
 *
 * So the department lives here, beside the position whose name it scopes,
 * because a constraint cannot be spelled across a module boundary:
 * `unique(department, name)` needs both columns in one table's index, and an
 * entity somewhere else could not supply one of them. Owning it is not a claim
 * on what a department MEANS to the rest of the product — another module wanting
 * departments as a lens over its own records points at this entity the way it
 * points at a person, through the contract, and nothing here has to know.
 *
 * A DEPARTMENT GRANTS NOTHING. It is an organizational fact and not an
 * authorization one: every capability an installation has still arrives through
 * a position's permissions. Banding the roster by department re-orders a page;
 * it never changes what anybody may do.
 */
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'team_department')]
#[ORM\UniqueConstraint(name: 'uniq_team_department_name', fields: ['name'])]
#[ORM\HasLifecycleCallbacks]
class Department
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * Unique across the installation, and here that IS the right scope: there
     * is one organisation, and two departments with one name would be the same
     * department entered twice.
     */
    #[ORM\Column(length: 120)]
    private ?string $name = null;

    /**
     * @var Collection<int, Position>
     */
    #[ORM\OneToMany(targetEntity: Position::class, mappedBy: 'department')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $positions;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
    }

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

    /**
     * @return Collection<int, Position>
     */
    public function getPositions(): Collection
    {
        return $this->positions;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
