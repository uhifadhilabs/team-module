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
use Uhifadhi\ModuleContracts\Entity\AreaInterface;
use Uhifadhi\Team\Entity\Trait\TimestampableTrait;
use Uhifadhi\Team\Entity\Trait\UuidTrait;
use Uhifadhi\Team\Enum\DepartmentScopeEnum;
use Uhifadhi\Team\Exception\MissingScopeChangeReasonException;
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
 * A DEPARTMENT NAME IS UNIQUE PER SCOPE, NOT ORG-WIDE. Two protected areas each
 * run their own Anti-Poaching unit — two units that share a word, the way two
 * departments each carry an Analyst — so a name is unique WITHIN one area, and
 * within the org-wide bucket (a null area), and the two are independent. See the
 * two partial unique indexes on the table below.
 *
 * A DEPARTMENT GRANTS NOTHING TODAY. It is an organizational fact and not an
 * authorization one: every capability an installation has still arrives through
 * a position's permissions. Banding the roster by department re-orders a page;
 * it never changes what anybody may do.
 *
 * IT CARRIES A SCOPE, AND THE SCOPE IS AN AREA OR THE ABSENCE OF ONE. A
 * department belongs either to the whole organisation ({@see $area} is null —
 * org-level, spanning every area) or to one area ({@see $area} is set —
 * area-level, belonging to that area alone). The scope is DERIVED from the
 * nullable area and never stored beside it ({@see getScope()}), because a stored
 * scope and a nullable area are two facts that drift apart and then one is lying.
 * The area is referenced through the platform's {@see AreaInterface} contract
 * (published by uhifadhi/module-contracts) and
 * resolved by whichever package provides the installation's area entity
 * (uhifadhi/area-module), so this module points at an area without ever requiring
 * an area package — the same arrangement by which a module points at a person.
 *
 * THE SCOPE IS CHANGEABLE, AND EVERY CHANGE IS AUDITED. {@see changeScopeTo()}
 * is the one door: it refuses a change with no reason, moves the area, and
 * appends a {@see DepartmentScopeChange} recording who, when, why, and which way.
 * A scope change reaches further than a rename — confining an org-wide department
 * re-scopes everything under it — so the transition leaves a record the current
 * state could never reconstruct.
 *
 * THE SCOPE IS ALSO AUTHORITY NOW. docs/area-scoped-authority.md
 * (module-contracts) rules that an area-level department is the unit that confines
 * a Staff member's authority, and the area-aware
 * {@see \Uhifadhi\Team\Security\PermissionVoter} reads exactly this chain:
 * `authority-area(person) = person.position.department.scope`, org-level (null)
 * meaning every area. So confining a department, or promoting one, now moves the
 * authority of everyone filed under it — which is what makes the scope-change
 * audit ({@see changeScopeTo()}) load-bearing rather than cosmetic. A department
 * still GRANTS nothing directly: capability arrives through a position's
 * permissions; the department only says WHERE those permissions reach.
 */
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'team_department')]
// A DEPARTMENT NAME IS UNIQUE PER SCOPE, NOT ACROSS THE INSTALLATION. Two
// partial unique indexes, not one — a plain unique(name, area_id) would let two
// org-level rows share a name, because in SQL two NULLs are distinct. The org
// bucket (area_id IS NULL) is unique on the name alone; each area (area_id IS
// NOT NULL) is unique on the name within it; the two buckets are independent.
#[ORM\UniqueConstraint(name: 'uniq_team_department_name_org', fields: ['name'], options: ['where' => '(area_id IS NULL)'])]
#[ORM\UniqueConstraint(name: 'uniq_team_department_name_area', fields: ['name', 'area'], options: ['where' => '(area_id IS NOT NULL)'])]
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
     * Unique within its scope — its area, or the org-wide bucket if it has none.
     * Two areas may each own a department of one name; two departments in one
     * area, or two org-wide, may not, because there the pair would be the same
     * department entered twice. Enforced by the two partial indexes on the table.
     */
    #[ORM\Column(length: 120)]
    private ?string $name = null;

    /**
     * THE AREA THIS DEPARTMENT BELONGS TO, OR NULL FOR THE WHOLE ORGANISATION —
     * the one fact the scope is read from. Nullable by construction: the null is
     * org-level, a real state and not an unfinished field.
     *
     * ON DELETE CASCADE, not SET NULL. If the area an area-level department
     * belongs to is removed, the department goes with it rather than silently
     * becoming org-wide — because once the area-aware voter is wired, a SET NULL
     * here would be a privilege escalation nobody asked for, promoting an area
     * team to org-wide authority the moment their area was deleted. Confining and
     * promoting are audited, deliberate acts ({@see changeScopeTo()}); an area
     * deletion must never perform one as a side effect. This mirrors the seam's
     * own AreaModule, whose row also dies with its area.
     */
    #[ORM\ManyToOne(targetEntity: AreaInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?AreaInterface $area = null;

    /**
     * @var Collection<int, Position>
     */
    #[ORM\OneToMany(targetEntity: Position::class, mappedBy: 'department')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $positions;

    /**
     * WHETHER THIS DEPARTMENT IS STILL IN PLAY, and the reason there is no delete.
     *
     * "This unit was folded into another last year" and "this unit never
     * existed" are different facts, and a DELETE makes them one operation — it
     * would take a scope-change history off the ledger and strand the positions
     * filed under it. So a department that is no longer run is DEACTIVATED, not
     * removed: the flag goes false, {@see $deactivatedAt} records when, the row
     * stays in the register greyed under an inactive treatment, and
     * {@see reactivate()} is one click. This is the standing fleet rule
     * (deactivate, never delete), the same one {@see User} keeps.
     *
     * Deactivation HIDES a department from the pickers — the create/confine area
     * pickers and the position move control — so nothing new is filed into a
     * unit that is winding down; it never removes what is already there, and it
     * never gates data. The footprint ("N positions hold this") is shown before
     * the act and INFORMS, never guards: the administrator may proceed.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deactivatedAt = null;

    /**
     * THE APPEND-ONLY HISTORY OF THIS DEPARTMENT'S SCOPE CHANGES, newest last.
     * Cascade-persist so a change written by {@see changeScopeTo()} is saved with
     * the department in one flush; no orphanRemoval, because an audit line is
     * never taken back.
     *
     * @var Collection<int, DepartmentScopeChange>
     */
    #[ORM\OneToMany(targetEntity: DepartmentScopeChange::class, mappedBy: 'department', cascade: ['persist'])]
    #[ORM\OrderBy(['recordedAt' => 'ASC'])]
    private Collection $scopeChanges;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
        $this->scopeChanges = new ArrayCollection();
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

    public function getArea(): ?AreaInterface
    {
        return $this->area;
    }

    /**
     * Sets the area DIRECTLY, without an audit entry — for construction and
     * seeding, where there is no transition to record because there is no prior
     * state. Every change to a LIVE department's scope goes through
     * {@see changeScopeTo()} instead, which is the only path that writes the audit
     * line the model requires.
     */
    public function setArea(?AreaInterface $area): static
    {
        $this->area = $area;

        return $this;
    }

    /**
     * THE SCOPE, DERIVED FROM THE AREA every time it is asked — never a stored
     * column that could disagree with {@see $area}.
     */
    public function getScope(): DepartmentScopeEnum
    {
        return null === $this->area ? DepartmentScopeEnum::Org : DepartmentScopeEnum::Area;
    }

    public function isOrgLevel(): bool
    {
        return null === $this->area;
    }

    public function isAreaLevel(): bool
    {
        return null !== $this->area;
    }

    /**
     * CHANGE THE SCOPE, AND LEAVE A RECORD — the one door for it on a live
     * department.
     *
     * Passing an area confines the department to it (org→area, or a move between
     * areas); passing null promotes it to org-wide (area→org). Either way the
     * change is refused without a reason, the area is moved, and a
     * {@see DepartmentScopeChange} is appended capturing who, when, why and which
     * way — the record the resulting state could never reconstruct on its own.
     *
     * The returned change is added to {@see $scopeChanges}, whose cascade-persist
     * saves it alongside the department; the caller only has to flush. The actor
     * is nullable because a console or seed transition has no signed-in person,
     * and an audit line by nobody is still a truthful audit line.
     *
     * @throws MissingScopeChangeReasonException if the reason is blank
     */
    public function changeScopeTo(?AreaInterface $newArea, ?User $by, string $reason): DepartmentScopeChange
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new MissingScopeChangeReasonException();
        }

        $from = $this->getScope();
        $areaLeft = $this->area;
        $this->area = $newArea;
        $to = $this->getScope();

        // The area-side of the transition: the one it was confined to, or the
        // one it left behind on the way to org-wide.
        $change = new DepartmentScopeChange($this, $from, $to, $newArea ?? $areaLeft, $by, $reason);
        $this->scopeChanges->add($change);

        return $change;
    }

    /**
     * @return Collection<int, DepartmentScopeChange>
     */
    public function getScopeChanges(): Collection
    {
        return $this->scopeChanges;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * The way a department winds down. Not a delete, and there is no delete:
     * its scope history stays on the ledger, the positions filed under it keep
     * their filing, and {@see reactivate()} is one click. Idempotent — a
     * department already inactive keeps the moment it first went so, because the
     * "when" of a deactivation is when it happened, not the last time somebody
     * pressed the button.
     */
    public function deactivate(?\DateTimeImmutable $at = null): static
    {
        if ($this->active) {
            $this->active = false;
            $this->deactivatedAt = $at ?? new \DateTimeImmutable();
        }

        return $this;
    }

    public function reactivate(): static
    {
        $this->active = true;
        // Cleared rather than kept: a stale "deactivated at" on a live
        // department is a fact that reads as true and is not.
        $this->deactivatedAt = null;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeImmutable
    {
        return $this->deactivatedAt;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
