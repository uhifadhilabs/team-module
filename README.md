# uhifadhi/team-module

**Team**: who an installation's people are and how they sign in — the user
account, the sign-in screen, the position that bundles permissions, and the
permission catalogue every module's declarations fold into.
A [uhifadhi](https://github.com/uhifadhilabs) platform bundle.

> Installs with `composer require uhifadhi/team-module`, registers via Flex, and
> provides two tables (`team_user`, `team_position`), two routes (`/login`,
> `/logout`), a six-entry permission catalogue that installed modules extend,
> a voter that decides it, and a sign-in screen rendered through the shell's
> document. You wire the firewall yourself — the README gives you the file.
> Needs a database and `uhifadhi/shell-module`.

## Contents

- [The architecture](#the-architecture)
- [What it owns](#what-it-owns)
- [What it does not own: enforcement](#what-it-does-not-own-enforcement)
- [The authorization model](#the-authorization-model)
  - [Two axes: tier and position](#two-axes-tier-and-position)
  - [The six permissions](#the-six-permissions)
  - [What a module adds](#what-a-module-adds)
  - [The voter](#the-voter)
- [The sign-in screen](#the-sign-in-screen)
- [The schema](#the-schema)
- [What is here](#what-is-here)
- [Installation](#installation)
- [Wire the security](#wire-the-security)
- [Create the tables](#create-the-tables)
- [What a fresh installation gains](#what-a-fresh-installation-gains)
- [Configuration](#configuration)
- [Not here yet](#not-here-yet)
- [Development](#development)
- [License](#license)

## The architecture

**Uhifadhi is one skeleton and a set of modules.**
[`uhifadhi/uhifadhi`](https://github.com/uhifadhilabs/uhifadhi) is the project
skeleton — copied once, never updated; everything else arrives as a module,
updated forever. A module **registers with the seam**
([`uhifadhi/seam-module`](https://github.com/uhifadhilabs/seam-module)) and
**renders in the shell**
([`uhifadhi/shell-module`](https://github.com/uhifadhilabs/shell-module)).

The skeleton is the application, the seam carries the modules, the shell is what
you see — and this is **who is looking**.

## What it owns

- **The account** — `Uhifadhi\Team\Entity\User`, a Symfony `UserInterface` and
  `PasswordAuthenticatedUserInterface`. Email is the sign-in identifier; a
  UUIDv7 is the public address; `rangerCode` is the short service number a field
  worker types into a phone.
- **The position** — `Uhifadhi\Team\Entity\Position`, a named bundle of granular
  permissions. Every user assigned a position inherits exactly its permissions.
- **The catalogue** — the six permissions this module owns plus whatever the
  installed modules declared, read live from the container.
- **The voter** — the thing that answers `is_granted('area.create')`.
- **The screen** — `/login` and `/logout`, rendered through the shell's
  document rung.

## What it does not own: enforcement

**Firewalls and `access_control` are application configuration, by Symfony's own
design.** They live in the installing project, because only that project knows
which of its paths are public and what its sessions should cost. A module cannot
decide that for an installation, and one that tried would be a module every
installation had to fight.

So there is **no separate security module**, and there is nothing for one to
hold. `config/packages/security.yaml` is your file: you own it, you rework it,
and this module does not write a line of it. What it does instead is tell you
exactly what to put there — see [Wire the security](#wire-the-security), which
is a **required hand-step**, not an optional polish.

This module's recipe therefore ships only what is unambiguously its own: its
`team.yaml` options and the routes that mount its two screens. It ships no
security file at all, by design and not by limitation — one security file, one
owner, and that owner is the application.

## The authorization model

### Two axes: tier and position

Every user carries a **tier** (`TeamRoleEnum`) and, optionally, a **position**.

| Tier | May impersonate | May administer the team | Holds every permission |
| --- | --- | --- | --- |
| Super Admin | yes | yes | yes |
| Admin | no | yes | yes |
| Manager | no | yes | **no** — position-driven |
| Staff | no | no | no — position-driven |

There is no "Owner": nobody owns a national park.

**A Manager holds nothing by tier.** The tier opens team administration and
stops there; what a Manager can *do* comes from their position, exactly as a
Staff user's does. That is deliberate — a tier that quietly granted everything
would make the position matrix decorative for half the people in it.

### The six permissions

`PermissionEnum`, in catalogue order:

| Value | Umbrella | Action | Capability role |
| --- | --- | --- | --- |
| `area.view` | Areas | View | `ROLE_AREAS` |
| `area.create` | Areas | Create | `ROLE_AREAS` |
| `area.edit` | Areas | Edit | `ROLE_AREAS` |
| `area.delete` | Areas | Delete | `ROLE_AREAS` |
| `module.view` | Modules | View | `ROLE_MODULES` |
| `module.create` | Modules | Add | `ROLE_MODULES` |

A **capability role** is the coarse umbrella `access_control` can name — a
position holding any Areas permission opens the whole `/areas` region, and the
granular action is then checked by the voter. Two gates, one model: the ladder
keeps a URL region shut, the voter decides the verb.

### What a module adds

An installed module **declares** permissions through
`ModuleProviderInterface::permissions()`
([`uhifadhi/module-contracts`](https://github.com/uhifadhilabs/module-contracts)).
Declaring makes a value assignable — it appears in the matrix and the voter
recognises it — and that is the whole of it:

- **no capability role.** A module can never mint an umbrella.
- **no default holders.** Installing a module must never hand an existing user a
  new power.
- **core always wins.** A module redeclaring one of the six is ignored.
- **it dies with the module.** Uninstall the module and the permission is gone
  from the catalogue on the next request.

### The voter

```php
$this->authorizationChecker->isGranted('area.create');
$this->authorizationChecker->isGranted('surveys.record'); // a module's, decided identically
```

Anything outside the catalogue is **abstained** on, so role checks still reach
the role voters — and a permission belonging to an uninstalled module is simply
no longer decidable.

## The sign-in screen

`/login` extends `@UhifadhiShell/document.html.twig` — the shell's **bottom**
rung — and not the page frame. A stranger gets the theme and the typefaces and
no furniture at all: there is no sidebar to navigate and no top bar to act from
until there is somebody to act as, and a nav rendered for an anonymous visitor
is a nav that leaks what an installation contains to whoever loads its front
door.

Authentication itself happens in the firewall, not in the controller: the POST
to `/login` is intercepted by `form_login` before any controller runs, and
`/logout` is intercepted entirely. The field names (`_username`, `_password`,
`_csrf_token`, `_remember_me`) are the firewall's contract.

The card's own rules ship as `public/team.css` and spend **only shell tokens**,
which is why the screen is correct in both palettes without a single dark-mode
rule.

## The schema

| Table | What it holds |
| --- | --- |
| `team_user` | the account: email, name, `ranger_code`, roles, tier, position, password hash, verification and reset tokens, uuid, timestamps |
| `team_position` | a named permission bundle: name, permissions (JSON), locked, uuid, timestamps |

Every table is prefixed, as every uhifadhi module's is — including `team_user`,
which additionally avoids `user`, a reserved word every installation would have
had to quote.

**The module ships entities, not migrations.** The tables are this module's, but
the migration history is the installation's: a migration shipped from here would
fight every `doctrine:migrations:diff` you ever run. After installing, run:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

## What is here

```
src/
  Controller/SecurityController.php     /login and /logout
  DependencyInjection/TeamConfiguration.php
  Entity/User.php  Entity/Position.php  Entity/Trait/
  Enum/PermissionEnum.php  Enum/TeamRoleEnum.php
  Model/Permission.php                  one catalogue entry, whoever declared it
  Repository/UserRepository.php  Repository/PositionRepository.php
  Security/PermissionVoter.php
  Service/PermissionCatalogue.php
  UhifadhiTeamBundle.php
config/services.php                     explicit wiring, no autowire
templates/login.html.twig
public/team.css
```

## Installation

```bash
composer require uhifadhi/team-module
```

Flex registers the bundle and copies two files into your project:

- `config/packages/team.yaml` — the two options below.
- `config/routes/team.yaml` — mounts `/login` and `/logout`.

It does **not** touch `config/packages/security.yaml`. Wiring that file is the
next step and it is required — see [Wire the security](#wire-the-security).

## Wire the security

**This step is required, and it is done by hand.** `config/packages/security.yaml`
is application-owned by Symfony's design — only your project knows which of its
paths are public — and a Flex recipe may not edit a file the application owns.
So this module does not write it, does not merge into it, and does not ship a
second file that quietly overrides it. It tells you what belongs there, and you
put it there.

Until you do, the module is installed and inert: `/login` renders, and nothing
authenticates, because the stock configuration still looks users up in memory.

Replace `config/packages/security.yaml` with this. It is the whole file, ready
to copy:

```yaml
security:
    # Hashing is a framework concern: the interface named here is Symfony's, and
    # "auto" is the framework picking the best algorithm available. Every user
    # uhifadhi/team-module stores implements it.
    password_hashers:
        Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface: 'auto'

    # The staff accounts, looked up by email. `property: email` is what makes
    # `_username` on the sign-in form an email address. The stock
    # `users_in_memory` provider is gone — you have user storage now, and a
    # provider nothing names is a provider that will confuse the next reader.
    providers:
        team_user_provider:
            entity:
                class: Uhifadhi\Team\Entity\User
                property: email

    firewalls:
        # The toolbar, the profiler and the asset server. `security: false`
        # rather than a public rule: these must not be authenticated at all.
        dev:
            pattern: ^/(_profiler|_wdt|assets|build)/
            security: false

        main:
            lazy: true
            provider: team_user_provider

            # THE AUTHENTICATION ITSELF, and it is in no controller: this key is
            # what intercepts the POST to /login. Both paths are the same route,
            # which is what makes a failed sign-in re-render the form with its
            # error rather than bouncing through a second URL. The field names
            # the module's template uses (`_username`, `_password`,
            # `_csrf_token`) are this key's defaults.
            form_login:
                login_path: team_login
                check_path: team_login
                enable_csrf: true
                # Where a FRESH sign-in lands — as distinct from team.yaml's
                # `after_sign_in_path`, which only covers somebody already
                # signed in who asks for /login again. Point this at your home
                # screen the day you have one.
                default_target_path: '/'

            # The checkbox on the sign-in card is wired to this. Delete the key
            # and the box is simply ignored — the card still works.
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 604800 # one week
                always_remember_me: false

            logout:
                path: team_logout
                target: team_login

            # Super Admin impersonation. The tier grants ROLE_ALLOWED_TO_SWITCH
            # (see the hierarchy below), so no other tier can reach it.
            switch_user: true

            # Blunting credential stuffing. Commented out because it needs a
            # package you may not have — see the note under this file.
            #login_throttling:
            #    max_attempts: 5

    # THE TIER LADDER, and it is not a permission tree. Admin and above hold the
    # two umbrella capability roles; Manager grants NO umbrella by tier, because
    # a Manager's capabilities come from their assigned position exactly as a
    # Staff user's do. The granular leaves (area.create, module.view, …) are
    # never listed here — they are decided by the module's voter. There are two
    # umbrellas and there is no ROLE_INGESTION.
    role_hierarchy:
        ROLE_ADMIN: [ROLE_MANAGER, ROLE_AREAS, ROLE_MODULES]
        ROLE_SUPER_ADMIN: [ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH]

    # ONLY THE FIRST MATCHING RULE APPLIES.
    #
    # THIS LADDER IS OPEN, AND CLOSING IT IS YOUR ONGOING JOB. One rule ships
    # here — the sign-in screen has to be reachable by somebody who is not
    # signed in — and everything else stays as reachable as it was before this
    # module arrived. That is deliberate: shutting the front door in a snippet
    # would lock every page of every module you already have, in one paste.
    # Add rules as you learn which of your paths are which; the commented lines
    # are the shape most installations end up with.
    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        #- { path: ^/areas, roles: ROLE_AREAS }      # the umbrella; the verb is the voter's
        #- { path: ^/, roles: ROLE_USER }            # sign in to see anything at all

# Hashing is expensive by design. In tests that cost buys nothing, so it is
# floored — the documented Symfony practice, and safe because it applies to no
# other environment.
when@test:
    security:
        password_hashers:
            Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface:
                algorithm: auto
                cost: 4 # lowest possible for bcrypt
                time_cost: 3 # lowest possible for argon
                memory_cost: 10 # lowest possible for argon
```

The `login_throttling` key is commented out because it needs
`symfony/rate-limiter`; run `composer require symfony/rate-limiter` and
uncomment it to cap sign-in attempts per IP.

Two things in that file the module actually depends on — everything else is
yours to change freely:

1. a provider that resolves `Uhifadhi\Team\Entity\User`, and
2. the route names `team_login` / `team_logout` (`config/routes/team.yaml`).

Break either and the container says so at compile time rather than at 3am.

## Create the tables

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

An installation needs a database `DATABASE_URL` points at. The skeleton already
carries `doctrine.yaml` and that env var (`uhifadhi/seam-module` brings Doctrine
in); this module adds no database configuration of its own beyond mapping its
two entities. Note that `doctrine:migrations:diff` walks **all** mapped
metadata, so the seam's own `resolve_target_entities` step
(`config/packages/seam.yaml`) has to be done first — otherwise the diff stops on
`Uhifadhi\Seam\Entity\AreaInterface` before it ever reaches `team_user`.

## What a fresh installation gains

- **`/login` answers 200**, in the shell's document, in both palettes.
- **`/logout` ends the session** and returns to `/login`.
- **`/` is unchanged** — still whatever your installation had there (the
  skeleton's welcome page), and still public: the `access_control` above leaves
  the front door open, because deciding otherwise is an installation's decision
  and not a module's.
- Nothing else changes appearance. There is no team-administration screen yet
  (see below), and no user exists until you create one.

## Configuration

```yaml
# config/packages/team.yaml
team:
    # Where a visitor who asks for /login while already signed in is sent.
    # A PATH, not a route name: this bundle cannot know what you call your
    # home screen.
    after_sign_in_path: '/'

    # The sentence under the mark on the sign-in card.
    sign_in_lede: 'Analytical observatory for protected areas. Sign in to reach this installation and its modules.'
```

Everything else about signing in — session lifetime, remember-me, throttling,
who may reach what — is `security.yaml`, which is yours and which you wired by
hand (see [Wire the security](#wire-the-security)).

## Not here yet

Stated so nobody looks for them:

- **No team-administration UI.** Creating users, defining positions and ticking
  the permission matrix are screens, and they are the next phase. The model
  underneath them is complete and tested; only the surface is missing.
- **No invitations, verification or password-reset flows.** The columns exist on
  `team_user` (`is_verified`, `verification_token`, `password_reset_token`,
  `password_reset_requested_at`) because they are part of the account this
  bundle ports; the flows that write them arrive with the screens.
- **No department on a position.** A department is an organizational lens
  another module owns, and this one cannot scope a name by an entity it does not
  have. Until it arrives, a position's name is unique across the installation.
- **No API token authentication.** Machine access is a different credential with
  a different lifecycle, and it belongs to whichever module owns the API.

## Development

```bash
composer install
composer check        # cs:check -> phpstan (max) -> phpunit
```

The integration and functional suites talk to a **real database** named by
`TEAM_TEST_DATABASE_URL` (see `phpunit.dist.xml`), rebuilding the schema per
test. Create it once:

```bash
createdb team_bundle_test
```

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
