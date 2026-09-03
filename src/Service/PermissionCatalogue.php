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

namespace Uhifadhi\Team\Service;

use Uhifadhi\ModuleContracts\ModuleProviderInterface;
use Uhifadhi\Team\Enum\PermissionEnum;
use Uhifadhi\Team\Model\Permission;

/**
 * THE SINGLE CATALOGUE OF EVERY PERMISSION THAT EXISTS IN THIS DEPLOYMENT:
 * the seven this module owns ({@see PermissionEnum}), plus whatever the
 * installed modules DECLARE through {@see ModuleProviderInterface::permissions()}.
 *
 * Declaring makes a permission assignable — it appears in the matrix and the
 * {@see \Uhifadhi\Team\Security\PermissionVoter} recognises it — and nothing
 * more: no capability role, no default holders. Uninstalling a module removes
 * its provider and its permissions vanish from the catalogue with it, on the
 * next request rather than the next deploy.
 *
 * EVERY ENTRY CARRIES ITS SENTENCE, whichever side wrote it: the core enum's
 * description() and a declaration's fourth string land in the same field, so
 * the matrix prints a row without asking where it came from.
 *
 * CORE ALWAYS WINS. A module redeclaring one of the seven is ignored, so no
 * module can relabel or shadow a permission this module owns; between two
 * modules colliding on a value the earlier registration holds. Never a merge,
 * never a fatal at boot — a third-party module must not be able to take an
 * installation down by picking a string.
 *
 * IT READS THE PROVIDERS LIVE. The tagged iterator is walked on every call, not
 * folded in the constructor, for the same reason the seam does it: what is
 * installed is a fact about the running container, not about deploy time.
 */
final readonly class PermissionCatalogue
{
    /**
     * @param iterable<ModuleProviderInterface> $moduleProviders every installed
     *                                                           module bundle, in registration order (the uhifadhi.module tag)
     */
    public function __construct(
        private iterable $moduleProviders = [],
    ) {
    }

    /**
     * @return list<Permission> core first (enum order), then module-declared
     */
    public function all(): array
    {
        $catalogue = [];
        foreach (PermissionEnum::cases() as $core) {
            $catalogue[$core->value] = new Permission(
                $core->value,
                $core->umbrella(),
                $core->action(),
                $core->description(),
                $core->capabilityRole(),
            );
        }

        foreach ($this->moduleProviders as $provider) {
            foreach ($provider->permissions() as $declared) {
                // No capability role, ever: a module declares, it does not grant.
                $catalogue[$declared->value] ??= new Permission(
                    $declared->value,
                    $declared->umbrella,
                    $declared->action,
                    $declared->description,
                );
            }
        }

        return array_values($catalogue);
    }

    /**
     * Every value this installation currently offers, in catalogue order — what
     * {@see \Uhifadhi\Team\Entity\Position::setPermissionValues()} validates
     * against. The position takes this list rather than this service, so an
     * entity never reaches for a container to check itself.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (Permission $p): string => $p->value, $this->all());
    }

    public function has(string $value): bool
    {
        foreach ($this->all() as $permission) {
            if ($permission->value === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The matrix's shape: permissions grouped under their umbrella heading.
     *
     * @return array<string, list<Permission>>
     */
    public function groupedByUmbrella(): array
    {
        $grouped = [];
        foreach ($this->all() as $permission) {
            $grouped[$permission->umbrella][] = $permission;
        }

        return $grouped;
    }

    /**
     * The submitted values that actually exist, in catalogue order — the
     * write-side filter for whatever form assigns permissions to a position.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function knownValues(array $values): array
    {
        $known = [];
        foreach ($this->all() as $permission) {
            if (\in_array($permission->value, $values, true)) {
                $known[] = $permission->value;
            }
        }

        return $known;
    }
}
