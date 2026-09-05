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
 * A DEPARTMENT'S SCOPE MAY NOT CHANGE WITHOUT A REASON.
 *
 * The reason is not decoration — it is the payload of the audit entry the change
 * writes ({@see \Uhifadhi\Team\Entity\DepartmentScopeChange}). A scope change
 * with a blank reason is an audit line that records who and when and leaves the
 * why empty, which is the one field an audit trail exists to hold. So the entity
 * refuses the change before it touches the area, rather than writing a record
 * that cannot answer the question it was created to answer.
 */
final class MissingScopeChangeReasonException extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('A department scope change must record a reason — it is the why of the audit entry, and an audit entry without it answers nothing.');
    }
}
