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

use PHPUnit\Framework\TestCase;

/**
 * THE SAVE BUTTON THE STYLESHEET HID.
 *
 * A real installation found this one: the matrix rendered, the boxes ticked,
 * the tick mirrored — and there was no way to save. `_w_matrix_b` shipped its
 * save bar as `class="pm-save clean"`, and the stylesheet carried
 * `.pm-save.clean .acts .cta { display: none; }`. The bar was a static frame in
 * the design app, where "clean" is a state a script leaves the moment a box is
 * ticked; ported into a page THAT SHIPS NO JAVASCRIPT AT ALL, "clean" is the
 * only state there is, so the primary was hidden permanently.
 *
 * NOTHING THAT TALKS HTTP COULD SEE IT. Every save test in this suite
 * hand-builds its POST, and DomCrawler does not apply CSS — so the endpoint,
 * the token, the redirect and the persistence were all green while the screen
 * was unusable. The seam is between a template's classes and a stylesheet's
 * rules, and it is read as TEXT here for the same reason widget-module reads
 * its script as text: a mismatch across two files fails only in a browser.
 *
 * The rule is general rather than a check for one class name: whatever state
 * the stylesheet uses to hide the save bar's primary, no shipped template may
 * render the bar already in it.
 */
final class SaveControlIsReachableTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../../templates';

    /**
     * NO RULE MAY HIDE THE SAVE BAR'S ACTIONS.
     *
     * Stated over the stylesheet rather than over one class name, because the
     * defect is not "the word clean": it is that a page with no script cannot
     * leave whatever state a rule hides the primary in. Any such rule is
     * permanent here.
     */
    public function testTheStylesheetHidesNoPartOfTheSaveBarsActions(): void
    {
        $css = (string) file_get_contents(__DIR__.'/../../../public/team.css');

        // Every rule whose selector mentions the save bar, with its body.
        preg_match_all('/([^{}]*\.pm-save[^{}]*)\{([^}]*)\}/', $css, $rules, \PREG_SET_ORDER);
        self::assertNotSame([], $rules, 'The sweep found no .pm-save rules at all, so it proves nothing.');

        foreach ($rules as [, $selector, $body]) {
            $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? '');

            if (!preg_match('/\.(acts|cta|softbtn)\b/', $selector)) {
                continue;
            }

            self::assertDoesNotMatchRegularExpression(
                '/display\s*:\s*none/i',
                $body,
                \sprintf(
                    'public/team.css hides the save bar\'s actions in "%s". This matrix ships no JavaScript — '
                    .'the label wraps a real checkbox and the form posts what is ticked — so whatever state that '
                    .'selector needs is a state the page can never leave, and the position could never be saved.',
                    $selector,
                ),
            );
        }
    }

    /**
     * And the templates never lean on such a state either — the other half of
     * the same seam, so a rule reintroduced under a new name is still caught
     * from the markup side.
     */
    public function testEverySaveBarShippedCarriesItsPrimary(): void
    {
        $checked = 0;

        foreach (self::templates() as $path) {
            $twig = (string) file_get_contents($path);

            if (!str_contains($twig, 'class="pm-save')) {
                continue;
            }

            ++$checked;
            self::assertStringContainsString(
                'type="submit"',
                $twig,
                \sprintf('%s draws a save bar with nothing in it that posts.', basename($path)),
            );
        }

        self::assertGreaterThan(0, $checked, 'The sweep found no save bar to check, so it proves nothing.');
    }

    /**
     * And the bar that edits a position actually offers one — a save bar with
     * no primary in the markup at all would slip past the test above.
     */
    public function testTheEditingMatrixShipsBothControls(): void
    {
        $twig = (string) file_get_contents(self::TEMPLATES.'/positions/_w_matrix_b.html.twig');

        self::assertMatchesRegularExpression(
            '/<button class="cta" type="submit">/',
            $twig,
            'The shipped matrix direction must carry a submit control inside its form.',
        );
        self::assertStringContainsString('Discard', $twig, 'Discarding a mis-tick is as ordinary as keeping it.');
    }

    /** @return list<string> */
    private static function templates(): array
    {
        $found = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES));

        /** @var \SplFileInfo $file */
        foreach ($directory as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.html.twig')) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }
}
