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
 * A position was handed a permission string that is neither in the live
 * catalogue nor already granted on that position.
 *
 * IT IS AN EXCEPTION RATHER THAN A FILTER, and that is the whole point of it.
 * The write path this replaces silently discarded anything it did not
 * recognise, so a permission a module declared could be ticked, saved, and come
 * back unticked with nothing anywhere saying why. Refusing loudly turns that
 * into a bug report on the first run instead of a mystery on the hundredth.
 */
final class UnknownPermissionException extends \InvalidArgumentException
{
    /**
     * @param list<string> $values the submitted strings nothing recognises
     */
    public function __construct(public readonly array $values)
    {
        parent::__construct(\sprintf(
            'Refusing to grant %s: %s in this installation\'s permission catalogue, and not already held by this position. A permission exists because this module declares it or an installed module does — check the value, or check the module is installed.',
            implode(', ', array_map(static fn (string $v): string => '"'.$v.'"', $values)),
            1 === \count($values) ? 'it is not' : 'they are not',
        ));
    }
}
