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

/**
 * One page of a list: the rows, and enough about the whole to draw a footer.
 *
 * A THIN WRAPPER OVER DOCTRINE'S OWN PAGINATOR, not a replacement for it. The
 * counting query is Doctrine's problem and it already solves it; what a template
 * needs is a handful of plain answers, and reaching into a Paginator for them
 * puts ORM vocabulary in a Twig file.
 *
 * NO API PLATFORM AND NO NEW DEPENDENCY. This is one list on one screen, and a
 * pagination framework earns its place when there are many.
 *
 * @template T of object
 */
final readonly class Page
{
    /**
     * @param list<T> $items   this page's rows, in the list's own order
     * @param int     $total   how many rows the whole query matches
     * @param int     $page    1-based, already clamped by whoever built this
     * @param int     $perPage the roster's footer prints it: it is the fact
     *                         that tells a reader when a second page appears
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * At least one, always. An installation with nobody in it is on page 1 of 1
     * — the list still exists, it is simply empty — and "page 1 of 0" is a
     * sentence no reader can make sense of.
     */
    public function pages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }

    /**
     * Whether the pager is worth drawing at all. Twelve people is one page, and
     * a disabled ‹ › on page 1 of 1 is chrome pretending there is somewhere to
     * go — so the design draws the count and the page size instead, and the
     * arrows appear only when they lead anywhere.
     */
    public function isMultiPage(): bool
    {
        return $this->pages() > 1;
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }
}
