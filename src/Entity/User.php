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
use Uhifadhi\Team\Entity\Trait\TimestampableTrait;
use Uhifadhi\Team\Entity\Trait\UuidTrait;
use Uhifadhi\Team\Enum\TeamRoleEnum;
use Uhifadhi\Team\Repository\UserRepository;

/**
 * A staff account of the single authority that runs an uhifadhi installation. Single-org:
 * no STI, no party isolation. Two authorization axes — a {@see TeamRoleEnum} tier and, for
 * Staff, an assigned {@see Position} bundling granular permissions.
 *
 * THE TABLE IS `team_user`, not `user`. Every module bundle prefixes its tables with its own
 * name, and identity is no exception: an installation's schema has to say which package owns
 * a table, and `user` is additionally a reserved word every host would have had to quote.
 *
 * ADDRESSED BY UUID. The sequential id is Doctrine's business; anything outside this bundle —
 * a URL, an API payload, another module's foreign key surface — uses the uuid.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'team_user')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampableTrait;
    use UuidTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * The web sign-in identifier, and the property the security provider looks a user up by.
     * Unique at the DATABASE, not by a validation constraint: a reusable bundle must not force
     * symfony/validator and the doctrine bridge onto every host that installs it, and a unique
     * index is the guard that holds whether or not anything validated first. A host that wants
     * the friendly "an account already exists" message adds #[UniqueEntity] on its own form.
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

    /** The position a Staff user holds; Super Admin / Admin / Manager hold everything by tier. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Position $position = null;

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
     * each permission in their position. Super Admin / Admin / Manager hold every permission by
     * tier (role_hierarchy + the voter's canManageContent()); Staff open only their position's
     * umbrellas here, with the granular action checked by {@see \Uhifadhi\Team\Security\PermissionVoter}.
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
            case TeamRoleEnum::Manager:
            case TeamRoleEnum::Staff:
                // Manager and Staff are position-driven: their capability roles come from
                // the assigned Position, nothing by tier.
                if (TeamRoleEnum::Manager === $this->teamRole) {
                    $roles[] = 'ROLE_MANAGER';
                }
                if (null !== $this->position) {
                    foreach ($this->position->getPermissions() as $permission) {
                        $roles[] = $permission->capabilityRole();
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
