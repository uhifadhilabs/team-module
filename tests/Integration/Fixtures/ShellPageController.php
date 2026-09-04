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

namespace Uhifadhi\Team\Tests\Integration\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * SOMEBODY ELSE'S PAGE, in the shell's frame — a stand-in for whatever screen an
 * installation happens to be on when it is not on one of this module's.
 *
 * The sidebar suite needs one, because the interesting questions about a nav row
 * are asked from OFF it: does a colleague without team.manage see it, and is it
 * lit when it should not be. A suite that could only ever ask from /team would
 * answer neither.
 *
 * IT SITS BEHIND THE FIREWALL LIKE EVERY OTHER PAGE. The documented
 * access_control is default-closed, so this page is reachable by a signed-in
 * viewer and by nobody else — which is why the anonymous case in that suite is
 * a redirect to /login rather than a sidebar with nothing in it.
 *
 * The template is built inline rather than shipped as a file, so the test kernel
 * keeps its promise of adding no twig paths: every template this bundle renders
 * is its own.
 */
final class ShellPageController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function __invoke(): Response
    {
        $template = $this->twig->createTemplate(
            "{% extends '@UhifadhiShell/page.html.twig' %}\n"
            ."{% block shell_page_title %}Somewhere else{% endblock %}\n"
            .'{% block shell_page %}a page that is not this module\'s{% endblock %}'
        );

        return new Response($template->render());
    }
}
