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
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Team\Entity\Trait\TimestampableTrait;
use Uhifadhi\Team\Entity\Trait\UuidTrait;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Repository\UserRepository;

/**
 * A staff account of the single authority that runs an uhifadhi installation. Single-org:
 * no STI, no party isolation. Two authorization axes — a {@see TeamRoleEnum} tier and, for
 * Staff, an assigned {@see Position} bundling granular permissions.
 *
 * THE TABLE IS `team_user`, not `user`. Every module bundle prefixes its tables with its own
 * name, and identity is no exception: an installation's schema has to say which package owns
 * a table, and `user` is additionally a reserved word every installation would have
 * had to quote.
 *
 * ADDRESSED BY UUID. The sequential id is Doctrine's business; anything outside this bundle —
 * a URL, an API payload, another module's foreign key surface — uses the uuid.
 *
 * TWO USER INTERFACES, AND THEY ANSWER DIFFERENT QUESTIONS. Symfony's answers "who is signed
 * in" — the firewall's business. `Uhifadhi\ModuleContracts\Entity\UserInterface`, imported here
 * aliased because the short names collide, answers "who is this record about": it is the
 * stand-in every other module points its associations at, so that no module has to require this
 * bundle to keep a record with a name on it. An installation closes the loop itself:
 *
 *     doctrine:
 *         orm:
 *             resolve_target_entities:
 *                 Uhifadhi\ModuleContracts\Entity\UserInterface: Uhifadhi\Team\Entity\User
 *
 * The contract asks seven questions and this class already answered all seven before it declared
 * them — which is the sign the surface was measured against real modules rather than invented.
 * Nothing here may narrow to suit it: widening the contract is a contracts release, and dropping
 * one of the seven is a breaking change for every module that reads it.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'team_user')]
#[ORM\HasLifecycleCallbacks]
class User implements ModuleUserInterface, PasswordAuthenticatedUserInterface, UserInterface
{
    use TimestampableTrait;
    use UuidTrait;

    /**
     * The one rule this module enforces on a password, stated once so the three
     * doors into an account cannot disagree about it: the bootstrap command, the
     * invitation-acceptance screen and the reset screen all check this number,
     * and the two screens print it.
     *
     * TWELVE, and nothing else. No character classes: a composition rule buys
     * very little entropy and reliably produces "Password1!", while length buys
     * a great deal. Everything stronger than a floor — breach-list checks, a
     * strength meter's opinion — is an installation's own policy to add, and
     * this module does not pretend to have made it.
     */
    public const int PASSWORD_MIN_LENGTH = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * The web sign-in identifier, and the property the security provider looks a user up by.
     * Unique at the DATABASE, not by a validation constraint: a reusable bundle must not force
     * symfony/validator and the doctrine bridge onto every installation, and a unique index
     * is the guard that holds whether or not anything validated first. An installation that
     * wants the friendly "an account already exists" message adds #[UniqueEntity] on its form.
     */
    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    /**
     * The short service number a field worker knows themselves by ("sl-0142")
     * and types into the field app — the API contract's `rangerId`. An email
     * address is the web sign-in identifier and is a poor one on a phone
     * keyboard in the rain, so the two identifiers are deliberately separate.
     * Nullable: office staff never get one, and no existing account is
     * retro-fitted with an invented number.
     */
    #[ORM\Column(length: 32, unique: true, nullable: true)]
    private ?string $rangerCode = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column(enumType: TeamRoleEnum::class)]
    private TeamRoleEnum $teamRole = TeamRoleEnum::Staff;

    /** The position a Staff user holds; Super Admin and Admin hold everything by tier. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Position $position = null;

    /**
     * WHETHER THIS PERSON MAY STILL SIGN IN, and the reason there is no delete.
     *
     * "This ranger left in March" and "this ranger never existed" are different
     * facts, and a DELETE makes them one operation — it would take the author
     * off every patrol they ever recorded. So leaving is deactivation: the flag
     * goes false, {@see $disabledAt} records when, the row stays in every list
     * under an inactive filter, and coming back is one click. Nothing in this
     * module destroys an account.
     */
    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    /**
     * RESERVED, AND NOTHING WRITES IT. A future recycle bin — removed records
     * listed on a surface of their own, with an explicit purge — is a design
     * this release does not draw. The column is here so that design is not
     * foreclosed by a schema with nowhere to put the marker, which is a cheaper
     * thing to carry than a migration on a table full of people.
     *
     * It is NOT the deactivation flag under another name. Deactivation is a
     * state an account lives in and comes back from; this would mark one on its
     * way out of the product entirely.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null; // @phpstan-ignore property.unusedType (reserved: nothing writes it yet — see above)

    /**
     * HOW THE ACCOUNT CAME TO EXIST — two nullable columns, and the null is
     * meaningful rather than missing. An account created with a password by an
     * administrator in the room was never invited by anybody, so both stay
     * null and the roster reads "created directly · no invitation". An invited
     * one reads "invited by Naomi Kileo · 3 days ago". Two kinds of
     * never-signed-in account, wanting two different things done about them.
     *
     * The line is shown only beside "never signed in": once somebody has signed
     * in, the invitation has done its job and stops being news.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $invitedAt = null;

    /**
     * Self-referencing and nullable, with the FK nulled rather than cascading:
     * the person who invited somebody may themselves be deactivated later, and
     * an account must never be deleted because of who introduced it.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $invitedBy = null;

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetRequestedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower($email);

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '').' '.($this->lastName ?? ''));
    }

    public function getRangerCode(): ?string
    {
        return $this->rangerCode;
    }

    /** Stored lower-case: the field app must not fail sign-in over capitalisation. */
    public function setRangerCode(?string $rangerCode): static
    {
        $rangerCode = null === $rangerCode ? null : strtolower(trim($rangerCode));
        $this->rangerCode = '' === $rangerCode ? null : $rangerCode;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        $email = (string) $this->email;

        return '' !== $email ? $email : throw new \LogicException('User has no email identifier.');
    }

    /**
     * Stored roles + ROLE_USER, then the tier's roles and — for Staff — the capability role of
     * each CORE permission in their position. Super Admin and Admin hold every permission by
     * tier (role_hierarchy + the voter's canManageContent()); Staff open only their position's
     * umbrellas here, with the granular action checked by {@see \Uhifadhi\Team\Security\PermissionVoter}.
     *
     * A MODULE-DECLARED PERMISSION MINTS NO ROLE, which is why the loop below
     * resolves each stored value through the core enum and skips what is not
     * one. Declaring is not granting: a module may make a value assignable and
     * may never open a URL region an installation's access_control names.
     *
     * There is no ROLE_MANAGER. The tier that emitted it is gone, and what it
     * used to stand for is `team.manage` — an ordinary permission, whose
     * umbrella role is ROLE_TEAM and which reaches this list through a position
     * like every other capability.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        switch ($this->teamRole) {
            case TeamRoleEnum::SuperAdmin:
                $roles[] = 'ROLE_SUPER_ADMIN';
                $roles[] = 'ROLE_ALLOWED_TO_SWITCH';
                break;
            case TeamRoleEnum::Admin:
                $roles[] = 'ROLE_ADMIN';
                break;
            case TeamRoleEnum::Staff:
                // Position-driven: nothing by tier at all.
                if (null !== $this->position) {
                    foreach ($this->position->getPermissionValues() as $value) {
                        $core = PermissionEnum::tryFrom($value);
                        if (null !== $core) {
                            $roles[] = $core->capabilityRole();
                        }
                    }
                }
                break;
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getTeamRole(): TeamRoleEnum
    {
        return $this->teamRole;
    }

    public function setTeamRole(TeamRoleEnum $teamRole): static
    {
        $this->teamRole = $teamRole;

        return $this;
    }

    public function getPosition(): ?Position
    {
        return $this->position;
    }

    public function setPosition(?Position $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * The way somebody leaves. Not a delete, and there is no delete: everything
     * this person recorded keeps its author, they stay on every list under the
     * inactive filter, and {@see reactivate()} is one click.
     *
     * The sole-active-Super-Admin invariant is NOT checked here. An entity
     * cannot count its own siblings, and an invariant that only holds when the
     * caller remembers to ask is not one — so the check lives in
     * {@see \Uhifadhi\Team\Service\SuperAdminInvariant}, which every write path
     * in this module goes through.
     */
    public function deactivate(?\DateTimeImmutable $at = null): static
    {
        $this->isActive = false;
        $this->disabledAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function reactivate(): static
    {
        $this->isActive = true;
        // Cleared rather than kept: a stale "disabled at" on an active account
        // is a fact that reads as true and is not.
        $this->disabledAt = null;

        return $this;
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    /** Reserved for a recycle bin this release does not draw — see the property. */
    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getInvitedAt(): ?\DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getInvitedBy(): ?self
    {
        return $this->invitedBy;
    }

    /**
     * Records that this account came from an invitation, and from whom. Both
     * facts are written together because half of them is not a fact: "invited
     * three days ago" by nobody is not something a screen can print.
     */
    public function markInvitedBy(self $inviter, ?\DateTimeImmutable $at = null): static
    {
        $this->invitedBy = $inviter;
        $this->invitedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /** Whether this account came from an invitation at all — the null that the roster reads. */
    public function wasInvited(): bool
    {
        return null !== $this->invitedAt;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $verificationToken): static
    {
        $this->verificationToken = $verificationToken;

        return $this;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;

        return $this;
    }

    public function getPasswordResetRequestedAt(): ?\DateTimeImmutable
    {
        return $this->passwordResetRequestedAt;
    }

    public function setPasswordResetRequestedAt(?\DateTimeImmutable $passwordResetRequestedAt): static
    {
        $this->passwordResetRequestedAt = $passwordResetRequestedAt;

        return $this;
    }
}
