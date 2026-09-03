# uhifadhi/team-module

**Team**: who an installation's people are and how they sign in — the user
account, the sign-in screen, the position that bundles permissions, and the
permission catalogue every module's declarations fold into.
A [uhifadhi](https://github.com/uhifadhilabs) platform bundle.

> Installs with `composer require uhifadhi/team-module`, registers via Flex, and
> provides two tables (`team_user`, `team_position`), two routes (`/login`,
> `/logout`), a seven-entry permission catalogue that installed modules extend,
> a voter that decides it, and a sign-in screen rendered through the shell's
> document. Its recipe writes your `config/packages/security.yaml`. Needs a
> database and `uhifadhi/shell-module`.

## Contents

- [The architecture](#the-architecture)
- [What it owns](#what-it-owns)
- [What it does not own: enforcement](#what-it-does-not-own-enforcement)
- [The authorization model](#the-authorization-model)
  - [Two axes: tier and position](#two-axes-tier-and-position)
  - [The seven permissions](#the-seven-permissions)
  - [What a module adds](#what-a-module-adds)
  - [The voter](#the-voter)
- [The sign-in screen](#the-sign-in-screen)
- [The schema](#the-schema)
- [What is here](#what-is-here)
- [Installation](#installation)
  - [What a fresh installation gains](#what-a-fresh-installation-gains)
- [Configuration](#configuration)
- [Not here yet](#not-here-yet)
- [Development](#development)
- [License](#license)

## The architecture

**Uhifadhi is one skeleton and a set of bundles.**
[`uhifadhi/uhifadhi`](https://github.com/uhifadhilabs/uhifadhi) is the project
skeleton — copied once, never updated; everything else arrives as a bundle,
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
- **The catalogue** — the seven permissions this bundle owns plus whatever the
  installed modules declared, read live from the container.
- **The voter** — the thing that answers `is_granted('area.create')`.
- **The screen** — `/login` and `/logout`, rendered through the shell's
  document rung.

## What it does not own: enforcement

**Firewalls and `access_control` are application configuration, by Symfony's own
design.** `config/packages/security.yaml` lives in the installing project,
because only that project knows which of its paths are public and what its
sessions should cost. A bundle cannot decide that for a host, and one that tried
would be a bundle every host had to fight.

So there is **no separate security module**, and there is nothing for one to
hold. What this bundle does instead is what a bundle can do: its **Flex recipe
writes that file**, pointed at the user provider and the routes below, and a
host edits it from there. That is the same shape
[`uhifadhi/shell-module`](https://github.com/uhifadhilabs/shell-module) uses for
the welcome route it cannot own either.

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

### The seven permissions

`PermissionEnum`, in catalogue order:

| Value | Umbrella | Action | Capability role |
| --- | --- | --- | --- |
| `area.view` | Areas | View | `ROLE_AREAS` |
| `area.create` | Areas | Create | `ROLE_AREAS` |
| `area.edit` | Areas | Edit | `ROLE_AREAS` |
| `area.delete` | Areas | Delete | `ROLE_AREAS` |
| `ingestion.run` | Ingestion | Run | `ROLE_INGESTION` |
| `module.view` | Modules | View | `ROLE_MODULES` |
| `module.create` | Modules | Add | `ROLE_MODULES` |

A **capability role** is the coarse umbrella `access_control` can name — a
position holding any Areas permission opens the whole `/areas` region, and the
granular action is then checked by the voter. Two gates, one model: the ladder
keeps a URL region shut, the voter decides the verb.

### What a module adds

An installed module bundle **declares** permissions through
`ModuleProviderInterface::permissions()`
([`uhifadhi/module-contracts`](https://github.com/uhifadhilabs/module-contracts)).
Declaring makes a value assignable — it appears in the matrix and the voter
recognises it — and that is the whole of it:

- **no capability role.** A module can never mint an umbrella.
- **no default holders.** Installing a module must never hand an existing user a
  new power.
- **core always wins.** A module redeclaring one of the seven is ignored.
- **it dies with the module.** Uninstall the bundle and the permission is gone
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

Every table is prefixed, as every uhifadhi module bundle's is — including
`team_user`, which additionally avoids `user`, a reserved word every host would
have had to quote.

**The bundle ships entities, not migrations.** The tables are this bundle's, but
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

Flex registers the bundle and copies three files into your project:

- `config/packages/security.yaml` — the firewall, pointed at this bundle's user
  provider and routes. **Yours to edit**, and you will: the `access_control`
  ladder it writes is a starting point.
- `config/packages/team.yaml` — the two options below.
- `config/routes/team.yaml` — mounts `/login` and `/logout`.

Then create the tables:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

An installation needs a database `DATABASE_URL` points at. The skeleton already
carries `doctrine.yaml` and that env var (`uhifadhi/seam-module` brings Doctrine
in); this bundle adds no database configuration of its own beyond mapping its
two entities.

### What a fresh installation gains

- **`/login` answers 200**, in the shell's document, in both palettes.
- **`/logout` ends the session** and returns to `/login`.
- **`/` is unchanged** — still whatever your installation had there (the
  skeleton's welcome page), and still public: the `access_control` the recipe
  writes leaves the front door open, because deciding otherwise is a host's
  decision and not a bundle's.
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
who may reach what — is `security.yaml`, which is yours.

## Not here yet

Stated so nobody looks for them:

- **No team-administration UI.** Creating users, defining positions and ticking
  the permission matrix are screens, and they are the next phase. The model
  underneath them is complete and tested; only the surface is missing.
- **No invitations, verification or password-reset flows.** The columns exist on
  `team_user` (`is_verified`, `verification_token`, `password_reset_token`,
  `password_reset_requested_at`) because they are part of the account this
  bundle ports; the flows that write them arrive with the screens.
- **No department on a position.** A department is an organizational lens owned
  by another ring, and a bundle cannot scope a name by an entity it does not
  have. Until it arrives, a position's name is unique across the installation.
- **No API token authentication.** Machine access is a different credential with
  a different lifecycle, and it belongs to whichever bundle owns the API.

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
