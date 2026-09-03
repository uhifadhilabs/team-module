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

namespace Uhifadhi\Team\Enum;

/**
 * The roster's account-state filter.
 *
 * A SELECT RATHER THAN MORE CHIPS, and the reason is in the model: this is not
 * one more facet of tier. An account has TWO INDEPENDENT AXES —
 * active/deactivated and verified/never-signed-in — and a person can be any
 * combination of them. Hawa Rajabu is both of the awkward ones at once. Chips
 * read as one scale, and these are two.
 *
 * The values are the query-string values, so a filtered roster is a URL.
 */
enum RosterStateEnum: string
{
    /** Everybody who can still sign in. */
    case Active = 'active';

    /**
     * The people who left. A deactivated account stays in every list by ruling,
     * so the list needs a way to say "just the active ones" — and, as
     * importantly, a way to find the switched-off ones again, which is the whole
     * reason they were not deleted.
     */
    case Deactivated = 'off';

    /**
     * `isVerified` is false. The pill says exactly what the boolean says and no
     * more: NOT "invitation pending", because an account created with a password
     * was never invited by anybody, and both kinds of account land here.
     */
    case NeverSignedIn = 'unverified';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Deactivated => 'Deactivated',
            self::NeverSignedIn => 'Never signed in',
        };
    }
}
