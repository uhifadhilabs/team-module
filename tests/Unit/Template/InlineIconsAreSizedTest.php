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
 * EVERY INLINE ICON CARRIES ITS OWN SIZE.
 *
 * An `<svg>` with a viewBox and no width or height has no intrinsic size: it
 * takes the width its container offers and scales its height to match. Inside a
 * flex row that is the full width of a card, which is how a 17-pixel warning
 * mark on the member record rendered as a 900-pixel triangle that pushed the
 * sentence beside it off the page.
 *
 * The rule that places an icon usually sizes it, and CSS out-ranks a
 * presentational attribute, so the attribute changes nothing where a size is
 * already given — it is the floor for the site that forgets, and forgetting is
 * invisible until somebody opens the page.
 */
final class InlineIconsAreSizedTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        $root = \dirname(__DIR__, 3).'/templates';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('twig' !== $file->getExtension()) {
                continue;
            }
            yield substr($file->getPathname(), \strlen($root) + 1) => [$file->getPathname()];
        }
    }

    #[DataProvider('templates')]
    public function testEveryInlineSvgDeclaresAWidthAndAHeight(string $path): void
    {
        preg_match_all('/<svg[^>]*>/', (string) file_get_contents($path), $matches);

        // `stroke-width` contains "width": only a whole attribute counts.
        $unsized = array_values(array_filter(
            $matches[0],
            static fn (string $tag): bool => 1 !== preg_match('/(?:^|\s)width=/', $tag)
                || 1 !== preg_match('/(?:^|\s)height=/', $tag),
        ));

        self::assertSame(
            [],
            $unsized,
            \sprintf('%s has an <svg> with no width or height — it grows to fill whatever contains it.', basename($path)),
        );
    }
}
