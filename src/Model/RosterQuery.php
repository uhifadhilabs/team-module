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

namespace Uhifadhi\Team\Model;

use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Team\Enum\RosterStateEnum;
use Uhifadhi\Team\Enum\TeamRoleEnum;

/**
 * WHAT THE /team LIST IS CURRENTLY SHOWING — one criteria object rather than
 * five positional arguments on a repository method nobody could call correctly
 * from memory, and which would have grown a sixth the first time a design asked.
 *
 * EVERY FIELD IS A QUERY PARAMETER, which is the whole mechanism of the roster's
 * tool row: the search box, the tier chips, the two selects and the page all
 * live in the URL, so a filtered roster is bookmarkable, shareable and survives
 * the back button. The tool row is a plain GET form and the tier chips are
 * ordinary links — the list works with no JavaScript at all, and a debounced
 * auto-submit would only ever be an enhancement on top.
 *
 * EVERYTHING ARRIVING FROM A URL IS UNTRUSTED. {@see fromRequest()} is where a
 * typed page number, an invented tier and a stale position uuid all become
 * either a value this object can hold or nothing at all — never an exception,
 * because a person editing a query string is not an error condition.
 */
final readonly class RosterQuery
{
    /**
     * Twenty-five to a page. Stated as a constant because the roster's footer
     * prints it — "12 people · 25 per page" is the fact that tells a reader
     * when a second page will appear.
     */
    public const int PER_PAGE = 25;

    /**
     * The position filter's "— no position —" option.
     *
     * HOLDING NOTHING IS A STATE SOMEBODY SEARCHES FOR, not the absence of a
     * filterable fact: a Staff member with no position can sign in and do
     * nothing at all, and finding those people is the point of the option. A
     * sentinel rather than a separate boolean field, because in the form it IS
     * one select with one value.
     */
    public const string NO_POSITION = 'none';

    /**
     * @param string|null $q          matched with ILIKE across first name, last name,
     *                                email and ranger code — exactly what the search
     *                                box's placeholder promises
     * @param string|null $position   a position's uuid, {@see NO_POSITION}, or null for any
     * @param string|null $department a department's uuid, or null for any — reached
     *                                THROUGH the position, since a person has no
     *                                department of their own
     * @param int         $page       1-based; clamped rather than trusted
     */
    public function __construct(
        public ?string $q = null,
        public ?TeamRoleEnum $tier = null,
        public ?string $position = null,
        public ?RosterStateEnum $state = null,
        public ?string $department = null,
        public int $page = 1,
    ) {
    }

    /**
     * The tool row's GET, read defensively. A blank field is no filter rather
     * than a filter on emptiness, and anything unrecognised is dropped: a URL
     * naming a tier that does not exist should show the whole roster, not a 400.
     */
    public static function fromRequest(Request $request): self
    {
        $string = static function (string $key) use ($request): ?string {
            $value = $request->query->get($key);
            $value = \is_string($value) ? trim($value) : '';

            return '' !== $value ? $value : null;
        };

        $tier = $string('tier');
        $state = $string('state');

        return new self(
            q: $string('q'),
            tier: null !== $tier ? TeamRoleEnum::tryFrom($tier) : null,
            position: $string('position'),
            state: null !== $state ? RosterStateEnum::tryFrom($state) : null,
            department: $string('department'),
            page: $request->query->getInt('page', 1),
        );
    }

    /** Whether anything is narrowing the list — what the "showing N of M" line turns on. */
    public function isFiltered(): bool
    {
        return null !== $this->q
            || null !== $this->tier
            || null !== $this->position
            || null !== $this->state
            || null !== $this->department;
    }

    /**
     * This query as query-string parameters, with the empties dropped — what the
     * pager and the tier chips build their hrefs from, so a link never loses the
     * filter it was clicked under.
     *
     * @param array<string, string|int|null> $overrides
     *
     * @return array<string, string|int>
     */
    public function toParams(array $overrides = []): array
    {
        $params = [
            'q' => $this->q,
            'tier' => $this->tier?->value,
            'position' => $this->position,
            'state' => $this->state?->value,
            'department' => $this->department,
            'page' => $this->page > 1 ? $this->page : null,
        ];

        foreach ($overrides as $key => $value) {
            $params[$key] = $value;
        }

        return array_filter(
            $params,
            static fn (string|int|null $value): bool => null !== $value && '' !== $value,
        );
    }
}
