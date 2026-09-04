# uhifadhi/team-module

**Team**: who an installation's people are and how they sign in — the user
account, the sign-in screen, the position that bundles permissions, and the
permission catalogue every module's declarations fold into.
A [uhifadhi](https://github.com/uhifadhilabs) platform bundle.

> Installs with `composer require uhifadhi/team-module`, registers via Flex, and
> provides three tables (`team_user`, `team_position`, `team_department`), a
> **seven-entry** permission catalogue that installed modules extend, a voter
> that decides it, and the screens: the roster, one person's record, the
> permission matrix, both ways of adding somebody, and the three a stranger
> reaches — sign-in, forgotten password, and accepting an invitation. The first
> administrator comes from `bin/console team:user:create`. You wire the firewall
> yourself — the README gives you the file. Needs a database,
> `uhifadhi/shell-module`, and (for the two letters it sends) a mailer it will
> tell you honestly it does not have.

## Contents

- [The architecture](#the-architecture)
  - [Security is three layers, and one of them is here](#security-is-three-layers-and-one-of-them-is-here)
- [What it owns](#what-it-owns)
- [What it does not own: enforcement](#what-it-does-not-own-enforcement)
- [Modules point at your people](#modules-point-at-your-people)
- [The authorization model](#the-authorization-model)
  - [Two axes: tier and position](#two-axes-tier-and-position)
  - [The seven permissions](#the-seven-permissions)
  - [What a module adds](#what-a-module-adds)
  - [The voter](#the-voter)
- [The screens](#the-screens)
  - [The attention pane collapses to nothing](#the-attention-pane-collapses-to-nothing)
  - [One installation always keeps one Super Admin](#one-installation-always-keeps-one-super-admin)
  - [Both ways of adding somebody ship](#both-ways-of-adding-somebody-ship)
  - [The self-service screens](#the-self-service-screens)
- [The row in the sidebar](#the-row-in-the-sidebar)
- [The first administrator](#the-first-administrator)
- [The sign-in screen](#the-sign-in-screen)
- [The schema](#the-schema)
- [What is here](#what-is-here)
- [Installation](#installation)
- [Wire the security](#wire-the-security)
- [Create the tables](#create-the-tables)
- [Make the first administrator](#make-the-first-administrator)
- [What a fresh installation gains](#what-a-fresh-installation-gains)
- [Configuration](#configuration)
  - [Sending mail](#sending-mail)
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

### Security is three layers, and one of them is here

Asking "may this person do this?" takes three separate things, owned by three
separate places. They are worth naming apart, because the commonest way to get
this wrong is to build one thing that tries to be all three.

**1. Declaration — what permissions exist.** A module declares its own on its
provider: `ModuleProviderInterface::permissions()` returning `ModulePermission`
values, both from
[`uhifadhi/module-contracts`](https://github.com/uhifadhilabs/module-contracts).
The declarations arrive through the seam's `uhifadhi.module` tag — the same
plug-point a module registers everything else through, not a second one invented
for security. That is what lets a permission belonging to a module written years
from now reach the matrix without this module, or the seam, having heard of it.
See [What a module adds](#what-a-module-adds).

**2. Identity — who is asking, and what they hold.** Accounts, positions, tiers,
and what a position grants. This is this module's whole domain, and it is why the
module is named after the **team** rather than after a mechanism: what it knows
is the people of an installation, and permissions are one of the things it knows
about them.

**3. Enforcement — the decision, at the moment it is needed.** A protected module
never calls anything in here. It writes `is_granted('area.edit')`; Symfony's
security layer is the socket; this module's voter answers. Swap this module for a
different identity provider and the protected module changes nothing, because it
was never pointed at this one. Firewalls and `access_control` sit at this layer
too, and they belong to the installation — see
[What it does not own: enforcement](#what-it-does-not-own-enforcement).

**Why the permission layer cannot sit in the seam.** Deciding whether a person
may do something requires knowing what that person's position grants — identity
data. The seam knows nothing about accounts, and keeping it that way is the
point rather than an omission: the seam records what exists, this module decides
who may use it, and Symfony's security layer is where the two meet without
either having to know the other.

## What it owns

- **The account** — `Uhifadhi\Team\Entity\User`, a Symfony `UserInterface` and
  `PasswordAuthenticatedUserInterface`. Email is the sign-in identifier; a
  UUIDv7 is the public address; `rangerCode` is the short service number a field
  worker types into a phone. It is deactivated, never deleted.
- **The position** — `Uhifadhi\Team\Entity\Position`, a named bundle of granular
  permissions belonging to a department. Every user assigned a position inherits
  exactly its permissions, and a Staff user with none holds nothing at all.
- **The department** — `Uhifadhi\Team\Entity\Department`, which this module owns
  because a constraint cannot be spelled across a module boundary: the position
  name's uniqueness is scoped by it.
- **The catalogue** — the seven permissions this module owns plus whatever the
  installed modules declared, read live from the container, every entry carrying
  the sentence that says what holding it does.
- **The voter** — the thing that answers `is_granted('area.create')`.
- **The screens** — the roster, one person's record, the permission matrix, both
  ways of adding somebody, and the three a stranger reaches. See
  [The screens](#the-screens).
- **The bootstrap** — `bin/console team:user:create`, which is how the first
  administrator of a fresh installation exists.

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

**The ladder the README hands you is closed, and that is not a contradiction of
the paragraph above.** A recipe that shut the front door would do it to a
project that never asked, on the day a `composer require` happened to run, and
would lock every page of every module already installed in one unattended step.
The block in [Wire the security](#wire-the-security) is the opposite kind of
act: you read it, you paste it, and you own the result. Closing the door is correct there precisely because it is your
hand doing it — an installation of this application is back-of-house, so a
stranger who asks for any of it belongs at `/login`.

## Modules point at your people

Nearly every module keeps records with a name on them — who reported the
incident, who led the patrol, whose dashboard layout this is. **None of them
type-hints this bundle's `User`**, and none of them should: a module that did
would be a module no installation could run without this one.

They take the contract instead —
`Uhifadhi\ModuleContracts\Entity\UserInterface`, from
[`uhifadhi/module-contracts`](https://github.com/uhifadhilabs/module-contracts) —
and this module's `User` **is** its answer. Seven questions: `getId()`,
`getUuidString()`, `getEmail()`, `getFirstName()`, `getLastName()`,
`getFullName()`, `getRangerCode()`. It implemented all seven before it declared
the interface, because the surface was measured against the modules that read it.

**Whoever knows the answer states the resolution.** That is the fleet's rule:
the package that provides the entity is the package that names it, and an
installation writes a `resolve_target_entities` line only when it wants to
disagree. Registering this bundle prepends the answer to the user contract, so a
fresh installation writes no `doctrine.yaml`:

```yaml
# what the bundle prepends for you — you do not write this
doctrine:
    orm:
        resolve_target_entities:
            Uhifadhi\ModuleContracts\Entity\UserInterface: Uhifadhi\Team\Entity\User
```

It used to be a documented hand-step and that was the wrong shape. A hand-step
is for a decision only the installation can make, and this was not one: the line
says exactly one thing, and for as long as this module is installed it only ever
had one right value. Its cost was real, because forgetting it fails a long way
from its cause — the container compiles, the kernel boots, and
`doctrine:migrations:diff` stops on `Class
'Uhifadhi\ModuleContracts\Entity\UserInterface' does not exist`, with nothing
pointing back at the paragraph that was missed.

**If your people are your own entity, say so and you win.** Prepended
configuration loses to the application's — that is Symfony's rule, not a switch
this module invented — so naming your own class in your own config is all it
takes, with nothing here to disable first:

```yaml
# config/packages/doctrine.yaml — yours, and it overrules the bundle
doctrine:
    orm:
        resolve_target_entities:
            Uhifadhi\ModuleContracts\Entity\UserInterface: App\Entity\Person
```

**Merge it into the block already there** — that file opens with `doctrine:`,
and a second `doctrine:` key in the same file is not valid YAML;
`resolve_target_entities` goes under the existing `orm:`, beside `mappings`.
Your class has to answer the contract's seven questions and be in the mapping
chain. That the override wins is tested, not assumed
(`tests/Integration/Identity/ResolveTargetEntitiesTest`).

**The seam's `AreaInterface` works the same way, and it is answered by
[`uhifadhi/area-module`](https://github.com/uhifadhilabs/area-module)** — the same
rule, the other live instance of it: team knows its `User`, area knows its
`AreaOfInterest`, and each prepends its own. With both installed, a bare
installation reaches `doctrine:migrations:diff` with **zero doctrine edits**. It
used to be the fleet's oldest hand-step, and it is gone.

**It is not Symfony's `UserInterface`.** That one answers "who is signed in" and
still comes from the token storage; this one answers "who is this record about".
`User` implements both, and a class that needs both imports one aliased.

## The authorization model

### Two axes: tier and position

Every user carries a **tier** (`TeamRoleEnum`) and, optionally, a **position**.

| Tier | May impersonate | Holds every permission | May administer the team |
| --- | --- | --- | --- |
| Super Admin | yes | yes | yes, by holding everything |
| Admin | no | yes | yes, by holding everything |
| Staff | no | no — position-driven | **only if their position carries `team.manage`** |

There is no "Owner": nobody owns a national park.

**There were four tiers and now there are three.** *Manager* is gone. It named a
job rather than a system level, and it created the model's single most misread
rule: a Manager could administer the team and yet hold no capability at all,
which made the position matrix decorative for half the people in it.

**Administering the team is now a permission** — `team.manage`, the seventh core
case, granted through a position like everything else. So "a manager" is
something an administrator *composes*: visible in the matrix, countable across
the roster, and revocable in one click. None of those three things was true of a
tier, and all three are why the tier went.

The cost is honest and worth stating: **"who administers this installation" is
no longer answerable from the tier column alone.** Every screen that asks gates
on `is_granted('team.manage')`, never on a tier, and the roster marks every
holder wherever they sit.

**Upgrading from 0.2:** a stored `manager` string no longer resolves to a case.
Decide for each of them — most become Staff with a position that carries
`team.manage`; a few were really Admins:

```sql
UPDATE team_user SET team_role = 'staff' WHERE team_role = 'manager';
```

Then compose the position and assign it. The tier is a decision this module
cannot take for you, which is why it fails loudly rather than guessing.

### The seven permissions

`PermissionEnum`, in catalogue order:

| Value | Umbrella | Action | Capability role |
| --- | --- | --- | --- |
| `area.view` | Areas | View | `ROLE_AREAS` |
| `area.create` | Areas | Create | `ROLE_AREAS` |
| `area.edit` | Areas | Edit | `ROLE_AREAS` |
| `area.delete` | Areas | Delete | `ROLE_AREAS` |
| `module.view` | Modules | View | `ROLE_MODULES` |
| `module.create` | Modules | Add | `ROLE_MODULES` |
| `team.manage` | Team | Manage | `ROLE_TEAM` |

A **capability role** is the coarse umbrella `access_control` can name — a
position holding any Areas permission opens the whole `/areas` region, and the
granular action is then checked by the voter. Two gates, one model: the ladder
keeps a URL region shut, the voter decides the verb.

The Team umbrella carries one row, and that is not an oversight: the umbrella is
the region `access_control` names, and the row is what the voter decides.

**Every permission carries a description** — one sentence saying what holding it
lets a person do, printed under the name in the matrix. The core seven have one
(`PermissionEnum::description()`) and so does every declared one
(`ModulePermission`'s fourth argument, required since module-contracts 0.3).
"Areas · Delete" says which words were chosen; the sentence says what ticking
the box hands over.

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

## The screens

Everything under `/team` is gated on **`is_granted('team.manage')`** by an
attribute on the action itself — a permission this installation's own matrix
grants and revokes, never a tier nobody can audit.

| Route | What it is |
| --- | --- |
| `/team` | **The roster.** A widget surface: nine widgets, six adoptable directions over the same people, and the shipped composition is the counts, the attention pane, the dense table and the tier explainer. |
| `/team/widgets` | its widget library |
| `/team/{uuid}` | **One person's record** — the four fields, the tier, the position, and the account actions. No delete: the page says so where a delete would be. |
| `/team/invite` | **Both ways of adding somebody**, side by side |
| `/team/positions` | **The permission matrix.** A widget surface: thirteen widgets, six adoptable directions, shipping on B — one position at a time. |
| `/team/positions/widgets` | its widget library |
| `/login` `/logout` | the sign-in screen |
| `/reset-password` | forgotten password — **public** |
| `/reset-password/{token}` | set a new one — **public** |
| `/invite/{token}` | accept an invitation — **public** |

**Both surfaces are widget surfaces**, which is why `uhifadhi/widget-module` is a
hard requirement rather than a suggestion. Every drawn direction ships as a
built-in preset carrying its own trade-off line — one sentence naming what it
buys and what it costs — and is adopted, copied and mixed. A direction is never
picked once and frozen, and no option set is a ballot.

### The attention pane collapses to nothing

Accounts that have never signed in, people who hold nothing, and the
sole-Super-Admin risk. When none of those is true the pane is **absent**, not an
empty plate saying everything is fine — a pane that stayed on screen to report
that nothing is wrong would spend the top of the page saying so, every visit.

The sole-Super-Admin row is the one that is not a task: it has no *done*, so it
carries no button. Resolving it means promoting somebody, and that is a decision
rather than a button on a warning.

### One installation always keeps one Super Admin

Demoting or deactivating the **last active** Super Admin is refused in code, with
the reason printed where the control would have been. Not greyed out: a disabled
button says "not now" and leaves the reader guessing, and here the reason is
also the instruction.

There is deliberately no owner flag and no break-glass account. Both were
considered and both are worse — an owner flag makes one row structurally
different and gives an installation a person it cannot replace; a break-glass
account is a credential nobody rotates. Ownership is transferable, and the only
rule is that it may not reach zero: **grant it to your successor, confirm they
can sign in, then have your own account deactivated.** In that order. The
reverse is the one the code refuses.

### Both ways of adding somebody ship

**Create with a password** needs nothing at all from the deployment — no mailer,
no outbound network, no reachable inbox — which is why it is always available.
The person is verified the moment they exist, because an administrator who typed
the password has already proved the account is real. Its cost is operational:
the hash is one-way, so the password has to be handed over in the room.

**Invite by email** ships beside it and starts working by itself the moment
`MAILER_DSN` and `team.mail_from` are set. Nobody ever knows anybody else's
password, which is the whole argument for it.

**Where there is no mailer the second path is offered and refused, not hidden.**
The form is visible, the button is inert, and the reason is written on it.
Hiding it would leave an administrator hunting for a feature the product does
have; failing silently after the click would leave a colleague waiting for an
email nobody sent.

### The self-service screens

A reset link is good for **one hour**, works **once**, and using it **signs every
other session of the account out** — anywhere it was still open. The
forgot-password answer is **neutral**: the same sentence, in the same words,
whether or not that address has an account, because an installation that
answered differently would be one whose front door tells a stranger who works
here. There is no state of that screen that says *no such user*.

With no mailer, that screen says the installation cannot send email yet rather
than swallowing the request. A silently discarded reset is the worst failure the
flow has.

## The row in the sidebar

Screens nobody can find are screens nobody has. Where an installation has
[`uhifadhi/shell-module`](https://github.com/uhifadhilabs/shell-module), this
module contributes **one row** to its sidebar and nothing else:

| | |
| --- | --- |
| Section | **Organization**, at position `20` |
| Row | **Team**, `lucide:users`, linking `team_index` |
| Lit on | `/team` and everything under it, the permission matrix included |
| Visible to | holders of **`team.manage`**, and nobody else |

**One row for two screens**, because the design settled it that way: `/team` and
`/team/positions` are one place in the product, so the matrix lights the Team row
rather than adding a second. What is below that is the page's business.

**It is a nav source, not a seam module.** The shell's `NavigationSourceInterface`
(tagged `shell.nav_section`) is documented to accept exactly this from a bundle —
"the rare platform-wide row that belongs to nobody's area" — and the other thing
a module can be, a per-area capability registered through the seam's
`ModuleProviderInterface`, is wrong here by construction: the seam's ledger is an
area-by-module table, and an installation's people are not an area's. A team that
had to be switched on per area would be a roster that existed four times.

**Gating is this module's job, not the shell's.** The shell holds no
authorization service and asks nothing about the viewer, so the row is **absent**
for anybody without `team.manage` — never hidden, because a hidden row leaks its
existence to whoever reads the HTML. It is the same permission the screens behind
it are gated on, so the sidebar cannot offer a door that closes in somebody's
face. An anonymous visitor gets nothing at all.

**Nothing to wire.** Registering the bundle registers the source; there is no
menu file, and no shell means no row rather than a container that will not
compile. Two things can take the row away and both are yours: revoking
`team.manage`, and unmounting the routes — an installation that deletes
`config/routes/team.yaml` loses this row rather than every page in the product.

## The first administrator

A fresh installation is a closed loop: the invite screen is behind `team.manage`,
`team.manage` is held through a position, and granting one needs an account that
can already administer the team. **This is the one door out of it**, and the only
one that works before there is a browser session, a mailer, or a row in
`team_user`:

```bash
bin/console team:user:create
```

Interactive only, and deliberately so: the one value it must take is a password,
and a password on a command line ends up in the shell history and the process
list. On an empty installation it offers **Super Admin**; once anybody exists it
offers **Staff**, because a command that kept minting installation-wide
administrators by default would be one whose safest answer is the wrong one.

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
| `team_user` | the account: email, name, `ranger_code`, roles, tier, position, password hash, verification and reset tokens, **`is_active` + `disabled_at`**, a reserved **`deleted_at`**, **`invited_at` + `invited_by_id`**, uuid, timestamps |
| `team_position` | a named permission bundle: name, **`department_id`**, permissions (JSON), locked, uuid, timestamps — unique on **(department, name)** |
| `team_department` | the part of the organisation a position belongs to: name (unique), uuid, timestamps |

**`is_active` is why there is no delete.** "This ranger left in March" and "this
ranger never existed" are different facts, and a DELETE makes them one operation
— it would take the author off every patrol they ever recorded. So leaving is
deactivation: the flag goes false, `disabled_at` records when, the row stays in
every list under an inactive filter, and coming back is one click. `deleted_at`
is reserved and nothing writes it, so a future recycle bin is not foreclosed by
a schema with nowhere to put the marker.

**`invited_at` / `invited_by_id` are nullable and the null is meaningful.** An
account created with a password was never invited by anybody, so the roster
reads "created directly · no invitation" rather than inventing one. Two kinds of
never-signed-in account, wanting two different things done about them.

**`unique(department, name)` replaces the org-wide unique on a position's
name.** Ecology has an Analyst and Protection Service has an Analyst: two
different jobs, different permission sets, one word between them. A position is
therefore written **department-first** everywhere in the product — "Protection
Service / Analyst", never a bare "Analyst", which is not a shorter way of saying
the same thing but a different and ambiguous thing. The department is nullable:
a position created before anybody decided which one owns it is a position that
exists, and its holders appear in the roster's Unassigned band.

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
  Command/CreateUserCommand.php         team:user:create — the first administrator
  Controller/SecurityController.php     /login and /logout
  Controller/TeamController.php         the roster, and TeamWidgetsController
  Controller/MemberController.php       one person's record and its writes
  Controller/PositionController.php     the matrix, and PositionWidgetsController
  Controller/InviteController.php       both ways of adding somebody
  Controller/PasswordResetController.php  forgot / reset / accept
  DependencyInjection/TeamConfiguration.php
  Entity/User.php  Entity/Position.php  Entity/Department.php  Entity/Trait/
  Enum/PermissionEnum.php  Enum/TeamRoleEnum.php  Enum/RosterStateEnum.php
  Exception/LastSuperAdminException.php   the refusal, with its reason
  Exception/UnknownPermissionException.php
  Model/Permission.php                  one catalogue entry, whoever declared it
  Model/RosterQuery.php  Model/Page.php  Model/RosterOverview.php
  Repository/                           User, Position, Department
  Security/PermissionVoter.php  Security/ActiveUserChecker.php
  Service/PermissionCatalogue.php  Service/SuperAdminInvariant.php
  Service/TeamOverview.php  Service/Mail.php
  Shell/TeamNavigation.php              the one row this module puts in the sidebar
  Widget/TeamWidgets.php  Widget/PositionWidgets.php
  UhifadhiTeamBundle.php
config/services.php                     explicit wiring, no autowire
templates/                              login, team/, positions/, auth/, widgets/
public/team.css
```

## Installation

```bash
composer require uhifadhi/team-module
```

It brings **`uhifadhi/widget-module`** with it, and that is a hard requirement
rather than a suggestion: the roster and the permission matrix are widget
surfaces, and a surface with no framework under it is a page whose six drawn
directions can never be adopted.

Flex registers both bundles and copies two files into your project:

- `config/packages/team.yaml` — the four options below.
- `config/routes/team.yaml` — mounts every screen this module owns.

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

Replace `config/packages/security.yaml` with this. **It is the whole file: one
paste over the stock one, and there is nothing to edit afterward** — the
commented lines are examples you may want later, not blanks you have to fill.

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

            # A DEACTIVATED ACCOUNT IS REFUSED AT THE DOOR, and told why.
            # Accounts are never deleted — leaving is deactivation — so the
            # thing that has to stop is the sign-in, and Symfony's plug-point
            # for it is a user checker. Without this line a deactivated person
            # signs in normally, which is the one way this module can be
            # installed and still be wrong.
            user_checker: team.user_checker

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
    # three umbrella capability roles; Staff grant nothing by tier, because a
    # Staff user's capabilities come from their assigned position. The granular
    # leaves (area.create, team.manage, …) are never listed here — they are
    # decided by the module's voter.
    #
    # THERE IS NO ROLE_MANAGER. The tier that emitted it is gone, and what it
    # stood for is the team.manage PERMISSION, whose umbrella is ROLE_TEAM.
    # If you are upgrading, delete any ROLE_MANAGER you have: nothing emits it
    # now, so a rule naming it is a rule nobody matches.
    #
    # There are three umbrellas and there is no ROLE_INGESTION.
    role_hierarchy:
        ROLE_ADMIN: [ROLE_AREAS, ROLE_MODULES, ROLE_TEAM]
        ROLE_SUPER_ADMIN: [ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH]

    # ONLY THE FIRST MATCHING RULE APPLIES.
    #
    # THIS LADDER IS CLOSED, AND OPENING A PATH IS THE DELIBERATE ACT. This is
    # a back-of-house installation: once identity is installed there is no page
    # of it a stranger is meant to read, so the last rule takes everything and
    # the rules above it are the exceptions. Adding a public path is a line you
    # write on purpose, one path at a time — which is the way round that fails
    # safely, because a path you forgot to name stays shut rather than open.
    #
    # THREE PATHS MUST STAY PUBLIC and they are the three a stranger reaches
    # with nobody to ask: the sign-in screen, the forgotten-password screen, and
    # the link an invited colleague follows to set their first password. Close
    # any of them and the person it is for has no way in at all.
    #
    # IS_AUTHENTICATED_REMEMBERED, not ROLE_USER, on the catch-all: it admits
    # somebody the remember_me key above signed back in from a cookie, which is
    # the whole point of having configured it. Use IS_AUTHENTICATED_FULLY on any
    # path you want a freshly typed password for.
    access_control:
        - { path: ^/login, roles: PUBLIC_ACCESS }
        - { path: ^/reset-password, roles: PUBLIC_ACCESS }
        - { path: ^/invite/, roles: PUBLIC_ACCESS }
        #- { path: ^/team, roles: ROLE_TEAM }        # the umbrella; the row is the voter's
        #- { path: ^/areas, roles: ROLE_AREAS }      # the umbrella; the verb is the voter's
        - { path: ^/, roles: IS_AUTHENTICATED_REMEMBERED }

    # NOTE ON /team: every action under it already enforces
    # is_granted('team.manage') with an attribute on the controller, so it is
    # shut to the wrong person whether or not you add the commented rule above.
    # The rule is the coarse outer gate; the attribute is the one that decides.

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

Four things in that file the module actually depends on — everything else is
yours to change freely:

1. a provider that resolves `Uhifadhi\Team\Entity\User`,
2. the route names `team_login` / `team_logout` (`config/routes/team.yaml`),
3. `user_checker: team.user_checker`, without which a deactivated account signs
   in normally, and
4. the three `PUBLIC_ACCESS` rules, without which the catch-all below them
   locks the screens a locked-out person needs too — including `/login`, which
   makes the installation unreachable by anybody.

Break the first two and the container says so at compile time rather than at
3am. The other two fail quietly, which is why they are listed.

## Create the tables

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

An installation needs a database `DATABASE_URL` points at. The skeleton already
carries `doctrine.yaml` and that env var (`uhifadhi/seam-module` brings Doctrine
in); this module adds no database configuration of its own beyond mapping its three
entities and answering the user contract, both of which it does for you.

Note that `doctrine:migrations:diff` walks **all** mapped metadata, so the
**seam's** `AreaInterface` has to be answered too — otherwise the diff stops on
`Uhifadhi\Seam\Entity\AreaInterface` before it ever reaches `team_user`. That is
not your homework either: install
[`uhifadhi/area-module`](https://github.com/uhifadhilabs/area-module) and it
prepends that resolution the same way this module prepends the user one. Neither
half needs a line from you.

## Make the first administrator

```bash
bin/console team:user:create
```

Until you run it there is nobody, and nothing behind `/login` can be reached by
anybody. See [The first administrator](#the-first-administrator) for why this is
a console command and not a screen.

## What a fresh installation gains

- **`/login` answers 200**, in the shell's document, in both palettes.
- **`/logout` ends the session** and returns to `/login`.
- **`/reset-password` answers 200** and says honestly that this installation
  cannot send email yet, because it cannot until you configure a transport.
- **`/team` answers 403 to everybody** until somebody holds `team.manage` — and
  on a fresh installation the Super Admin the console made holds it by tier, so
  it answers 200 for them and shows a page that says they are the only person
  here and offers the two useful next steps.
- **`/team/positions` answers 200** for that same person, and its shipped
  direction's empty state is the control that makes the first position.
- **The sidebar has an Organization section with a Team row in it**, for that
  same person and for nobody else — see
  [The row in the sidebar](#the-row-in-the-sidebar). Before this module, a fresh
  installation's sidebar was empty and the shell's welcome page said so; now it
  says one true thing.
- **`/` answers a redirect to `/login` for a stranger**, and whatever your
  installation had there for somebody signed in. The page itself is unchanged —
  nothing in this module special-cases `/` — but the `access_control` above
  shuts it, the way it shuts everything that is not the three public paths.
  On a fresh installation that page is the shell's welcome screen, which under
  this posture is simply a signed-in view; installations replace `/` with a
  real home the day they have one to put there.

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

    # The address invitations and reset links come from. Empty means this
    # installation cannot send — see below.
    mail_from: ''

    # What those letters call this installation in their subject line.
    installation_name: 'Uhifadhi'
```

Everything else about signing in — session lifetime, remember-me, throttling,
who may reach what — is `security.yaml`, which is yours and which you wired by
hand (see [Wire the security](#wire-the-security)).

### Sending mail

This module sends exactly two letters: an invitation, and a password-reset link.
Both need two things, and **neither of them is optional-with-a-default**:

1. a transport — `MAILER_DSN` in your `.env`, which is the framework's setting
   and needs `composer require symfony/mailer` if you do not already have it;
2. `team.mail_from`, an address to send from.

**Until both are set, this installation reports that it cannot send, and every
screen that would have sent something says so in writing.** That is a working
state and not a broken one:

- `/team/invite` keeps the invite-by-email form visible with its button inert
  and the reason on it. The other path on that page — create with a password —
  needs nothing from the deployment and works from day one.
- `/reset-password` says the installation cannot send email yet, and points a
  locked-out person at an administrator, who can send the same link from their
  record.

Configure both and the two paths start working with no other change. Nothing is
hidden and nothing is silently discarded, because a swallowed invitation and a
swallowed reset are the two worst failures these flows have.

## Not here yet

Stated so nobody looks for them:

- **No recycle bin.** Accounts are deactivated, never deleted, and `deleted_at`
  is reserved for a surface that lists removed records with an explicit purge.
  Nothing draws it and nothing writes it.
- **No screen that makes a department.** Positions belong to departments and the
  matrix groups by them, but the three or four rows an organisation needs are
  currently made by whatever seeds your installation. A department is a name;
  the screen for it is small and it is not drawn yet.
- **No per-person permission, and there will not be one.** Authority lives on
  the position. Giving one person an exception means giving them a position of
  their own, which is a thing you can see and revoke.
- **No API token authentication.** Machine access is a different credential with
  a different lifecycle, and it belongs to whichever module owns the API.
- **No audit trail.** Who changed a tier, and when, is not recorded. The
  sole-Super-Admin risk names this as part of why it is a risk.

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
