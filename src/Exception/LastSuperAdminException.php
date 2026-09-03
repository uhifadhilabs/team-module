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

namespace Uhifadhi\Team\Exception;

/**
 * The change would have left the installation with no active Super Admin.
 *
 * THE INVARIANT IS A REFUSAL, NOT A GREYED-OUT BUTTON. A disabled control says
 * "not now" and leaves the reader guessing; this carries the reason, and the
 * screens print it where the control would have been. An installation must
 * always keep at least one ACTIVE Super Admin — demote or deactivate the last
 * one and there is nobody who can administer the team and no way back in.
 *
 * There is deliberately no owner flag and no break-glass account. Ownership is
 * simply transferable, and the only rule is that it may not reach zero: grant
 * Super Admin to a successor, confirm they can sign in, then have your own
 * account deactivated. That order works. The reverse is the one this refuses.
 */
final class LastSuperAdminException extends \DomainException
{
    public static function cannotDemote(string $name): self
    {
        return new self(\sprintf(
            '%s is the only active Super Admin on this installation, so their tier cannot be lowered. Demoting the last one leaves nobody who can administer the team. Grant Super Admin to somebody else first — ownership is meant to be transferred, and this unlocks the moment it is.',
            $name,
        ));
    }

    public static function cannotDeactivate(string $name): self
    {
        return new self(\sprintf(
            '%s is the only active Super Admin on this installation, so this account cannot be deactivated. Deactivating the last one leaves nobody who can administer the team. Grant Super Admin to a successor, confirm they can sign in, then deactivate this account — in that order.',
            $name,
        ));
    }
}
