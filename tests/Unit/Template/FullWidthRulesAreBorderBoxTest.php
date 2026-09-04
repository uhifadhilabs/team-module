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

namespace Uhifadhi\Team\Tests\Unit\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ANYTHING THAT ASKS FOR THE FULL WIDTH AND THEN PADS ITSELF SAYS SO.
 *
 * `width: 100%` is the width of the CONTENT box unless a rule says otherwise, so
 * a rule that also carries padding or a border asks for its container's whole
 * width and then adds its own to the outside of it. The element ends up wider
 * than the thing containing it, by exactly the padding and border it declared.
 *
 * That is not a hypothetical. `.rail-item` is 11px of padding and a 1px border
 * on top of `width: 100%`, and on /team/positions it rendered 24px wider than
 * the rail's content box — every position card in the left rail crossed the
 * rail's own border and sat on top of the pane beside it.
 *
 * IT LOOKS RIGHT IN THE DESIGN FOR TWO REASONS THIS BUNDLE HAS NEITHER OF. The
 * static replica loads a `*{box-sizing:border-box}` reset, and it draws the rail
 * item as a `<button>`, which browsers already size as a border box. Neither
 * survives the port: uhifadhi/shell-module ships tokens and furniture and
 * deliberately no reset layer (an application's Tailwind build owns utilities),
 * and the rail item here is an `<a href>`, because in the real surface it is a
 * link to a position and not a button. A `<div>` or an `<a>` gets content-box,
 * and the design's two accidental protections both disappear at once.
 *
 * So the sheet declares it. Where the element is already a border box the
 * declaration changes nothing — it is the floor for the rule that is written
 * next, and forgetting is invisible until somebody opens the page.
 */
final class FullWidthRulesAreBorderBoxTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function fullWidthRules(): iterable
    {
        $path = \dirname(__DIR__, 3).'/public/team.css';
        $css = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', (string) $css, $matches, \PREG_SET_ORDER);

        foreach ($matches as $rule) {
            $selector = trim(preg_replace('/\s+/', ' ', $rule[1]) ?? '');
            $body = $rule[2];

            if (1 !== preg_match('/width\s*:\s*100%/', $body)) {
                continue;
            }

            // Padding, or a border that actually draws — `border: 0` adds nothing.
            $pads = 1 === preg_match('/(?:^|;|\s)padding\s*:/', $body)
                || 1 === preg_match('/(?:^|;|\s)border\s*:\s*(?!0)(?!none)/', $body);

            if (!$pads) {
                continue;
            }

            yield $selector => [$selector, $body];
        }
    }

    #[DataProvider('fullWidthRules')]
    public function testAFullWidthRuleThatPadsItselfDeclaresBorderBox(string $selector, string $body): void
    {
        self::assertMatchesRegularExpression(
            '/box-sizing\s*:\s*border-box/',
            $body,
            \sprintf(
                '`%s` sets width: 100%% and then pads itself, with no box-sizing: border-box — '
                .'it renders wider than whatever contains it by exactly its own padding and border.',
                $selector,
            ),
        );
    }

    /** The sweep is worthless if it matched nothing, so it says how much it read. */
    public function testTheSweepActuallyFoundRulesToCheck(): void
    {
        self::assertGreaterThanOrEqual(5, iterator_count(self::fullWidthRules()));
    }
}
