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

use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Seam\Entity\AreaInterface;
use Uhifadhi\Team\Entity\Trait\UuidTrait;
use Uhifadhi\Team\Enum\DepartmentScopeEnum;
use Uhifadhi\Team\Repository\DepartmentScopeChangeRepository;

/**
 * ONE LINE OF A DEPARTMENT'S SCOPE HISTORY — who changed the scope, when, why,
 * and which way it went.
 *
 * Changing a department's scope is not like renaming it. Confining an org-wide
 * department to a single area re-scopes everything filed under it, and — once the
 * area-aware voter is wired (docs/area-scoped-authority.md) — promoting an
 * area-level department to org-wide will hand every one of its people authority
 * in every area. A change with that reach is not something the current state can
 * explain after the fact: the department ends up org-wide either way, and the row
 * itself cannot say it used to be Ngorongoro's, who widened it, or why. This
 * entity is that record, written on the transition and never edited afterwards.
 *
 * IT IS AN APPEND-ONLY LEDGER. Nothing here has a setter. The four facts are
 * fixed at construction — {@see Department::changeScopeTo()} is the only thing
 * that builds one — because an audit line you can edit is an audit line that
 * proves nothing. It carries a `recordedAt` set at construction rather than the
 * usual persist-time timestamp: the "when" of an audit event is when the event
 * happened, not when the row reached the database.
 *
 * THE ACTOR AND THE AREA ARE NULLED, NEVER CASCADED. `changedBy` is SET NULL
 * because the person who confined a department may themselves be deactivated
 * later, and this module never deletes an account anyway — but even a future
 * purge must not take the history of what they did with them. The `area` is SET
 * NULL for the same reason: the audit outlives the area it names. Only the
 * department cascades, because a scope-change history belongs to its department
 * and means nothing without it.
 */
#[ORM\Entity(repositoryClass: DepartmentScopeChangeRepository::class)]
#[ORM\Table(name: 'team_department_scope_change')]
#[ORM\HasLifecycleCallbacks]
class DepartmentScopeChange
{
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'scopeChanges')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Department $department = null;

    #[ORM\Column(enumType: DepartmentScopeEnum::class)]
    private DepartmentScopeEnum $fromScope;

    #[ORM\Column(enumType: DepartmentScopeEnum::class)]
    private DepartmentScopeEnum $toScope;

    /**
     * The area on the area-side of the transition — the one it was confined to
     * when going org→area, or the one it left behind when going area→org. Null
     * only if the area has since been removed. Mapped to the seam's contract, so
     * this module points at an area without ever requiring an area package.
     */
    #[ORM\ManyToOne(targetEntity: AreaInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AreaInterface $area = null;

    /** WHO. Nulled if the account is ever removed; the line survives them. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $changedBy = null;

    /** WHY. The reason an administrator typed; the payload of the audit line. */
    #[ORM\Column(length: 1000)]
    private string $reason;

    /** WHEN. The moment the change was made, fixed at construction. */
    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct(
        Department $department,
        DepartmentScopeEnum $fromScope,
        DepartmentScopeEnum $toScope,
        ?AreaInterface $area,
        ?User $changedBy,
        string $reason,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        $this->department = $department;
        $this->fromScope = $fromScope;
        $this->toScope = $toScope;
        $this->area = $area;
        $this->changedBy = $changedBy;
        $this->reason = $reason;
        $this->recordedAt = $recordedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function getFromScope(): DepartmentScopeEnum
    {
        return $this->fromScope;
    }

    public function getToScope(): DepartmentScopeEnum
    {
        return $this->toScope;
    }

    public function getArea(): ?AreaInterface
    {
        return $this->area;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
