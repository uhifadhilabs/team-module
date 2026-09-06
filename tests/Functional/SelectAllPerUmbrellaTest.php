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

namespace Uhifadhi\Team\Tests\Functional;

use Uhifadhi\Team\UhifadhiTeamBundle;

/**
 * SELECT ALL, PER UMBRELLA — AND ONLY WHERE THERE IS JAVASCRIPT TO DO IT.
 *
 * The design draws the umbrella's count as a button: "all · 1 of 4", pressed to
 * tick or untick that module's whole group. It is a convenience over the rows
 * that are already there, so it is shipped the only way a convenience may be
 * shipped on a screen that also has to work without JavaScript: as a
 * PROGRESSIVE ENHANCEMENT.
 *
 * WHAT THAT MEANS HERE, EXACTLY:
 *
 *   · The matrix is unchanged without it. The rows are real checkboxes in a
 *     real form, and every one of them can still be ticked and saved by hand —
 *     there is no path through this screen that requires the button.
 *   · The control ships INERT and the controller makes it live. It renders
 *     `disabled`, and the Stimulus controller's connect() is what enables it.
 *     A control that looks operable and is not is worse than no control (the
 *     shell's whole furniture spec exists because of that failure), and a
 *     `<button>` whose behaviour never arrived is exactly that. Disabled, it
 *     reads as the count span it has always been, and it says so to a screen
 *     reader instead of lying about it.
 *   · It writes nothing. Ticking is a change to the boxes and no more; the
 *     matrix's no-surprise write model is unmoved — nothing reaches the
 *     database until Save is pressed, which is the same sentence the save bar
 *     has always carried.
 *
 * The rest of this file is the wiring, pinned end to end EXCEPT the click,
 * which is a browser's business: that the markup names a controller, that the
 * controller is in the package, that the package declares it under the name the
 * host will enable, and that the identifier in the template is the one
 * StimulusBundle derives from the package name. This is team's FIRST controller,
 * so every one of those links is new here and none of them have ever been
 * exercised.
 */
final class SelectAllPerUmbrellaTest extends WebTestCaseWithSchema
{
    private const string IDENTIFIER = 'uhifadhi--team-module--permission-group';

    /**
     * EVERY UMBRELLA GROUP IS A CONTROLLER SCOPE, and the boxes inside it are
     * its targets. The button is in the group's heading and the rows are its
     * siblings, so the scope is the group — not the heading, which cannot reach
     * a single checkbox.
     */
    public function testEachUmbrellaGroupIsAScopeWhoseBoxesAreItsTargets(): void
    {
        $crawler = $this->matrix();

        $groups = $crawler->filter('[data-pm="b"] .pm-group[data-controller="'.self::IDENTIFIER.'"]');
        self::assertGreaterThanOrEqual(4, $groups->count(), 'The umbrella groups are not controller scopes.');

        $boxes = $crawler->filter('[data-pm="b"] .pm-group .pm-check[data-'.self::IDENTIFIER.'-target="box"]');
        self::assertGreaterThan(0, $boxes->count(), 'The checkboxes are not the controller\'s targets.');
    }

    /**
     * THE CONTROL IS THE DESIGN'S BUTTON, WIRED TO THE METHOD THAT ANSWERS IT.
     * A `data-action` naming a controller nobody ships is the failure mode this
     * whole file is about.
     */
    public function testTheCountIsTheDesignsButtonAndItNamesTheMethodThatAnswersIt(): void
    {
        $crawler = $this->matrix();

        $buttons = $crawler->filter('[data-pm="b"] button.pm-all');
        self::assertGreaterThanOrEqual(4, $buttons->count(), 'The umbrella count is not the design\'s button.');

        foreach ($buttons->each(static fn ($node): array => [$node->attr('data-action'), $node->attr('type')]) as $pair) {
            self::assertSame(self::IDENTIFIER.'#toggle', $pair[0]);
            self::assertSame('button', $pair[1], 'A button with no type submits the form it is in.');
        }

        // The design's own words, and the count it has always carried.
        self::assertStringContainsString('all', $buttons->first()->text());
        self::assertCount(1, $buttons->first()->filter('span.c'));
    }

    /**
     * INERT UNTIL ITS BEHAVIOUR ARRIVES. Rendered disabled by the server and
     * enabled by connect() — so the one state that can never happen is the one
     * that matters: a live-looking button with no controller behind it.
     */
    public function testTheControlShipsDisabledAndTheControllerIsWhatEnablesIt(): void
    {
        $crawler = $this->matrix();

        foreach ($crawler->filter('[data-pm="b"] button.pm-all')->each(static fn ($n): ?string => $n->attr('disabled')) as $disabled) {
            self::assertNotNull($disabled, 'The button is live before anything can answer it.');
        }

        $js = self::controller();
        self::assertStringContainsString('connect()', $js);
        self::assertStringContainsString('disabled = false', $js);
    }

    /**
     * AND THE MATRIX IS UNCHANGED WITHOUT IT: the rows are still checkboxes in
     * the form, and a save still saves. This is the assertion that makes the
     * word "enhancement" true rather than aspirational.
     */
    public function testTheMatrixStillSavesByHandWithNoJavaScriptAtAll(): void
    {
        $this->administrator();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), []);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/team/positions?position='.$ranger->getUuidString());
        $form = $crawler->filter('form.pane')->selectButton('Save')->form();

        /** @var list<\Symfony\Component\DomCrawler\Field\ChoiceFormField> $boxes */
        $boxes = $form['permissions'];
        foreach ($boxes as $box) {
            if (['area.view'] === $box->availableOptionValues()) {
                $box->tick();
            }
        }

        $this->client->submit($form);

        $this->em->clear();
        $stored = $this->em->getRepository(\Uhifadhi\Team\Entity\Position::class)->findOneBy(['name' => 'Ranger']);
        self::assertNotNull($stored);
        self::assertSame(['area.view'], $stored->getPermissionValues());
    }

    /** IT WRITES NOTHING — the toggle touches boxes and never posts. */
    public function testTheEnhancementDoesNotWriteAnything(): void
    {
        $js = self::controller();

        foreach (['fetch(', 'XMLHttpRequest', '.submit(', 'form.requestSubmit'] as $write) {
            self::assertStringNotContainsString($write, $js, 'Nothing saves until Save: the toggle may not write.');
        }
    }

    /**
     * THE ONE KEYWORD THAT IS NOT DECORATION. Flex reads a package's
     * assets/package.json only if the composer package declares the keyword
     * `symfony-ux` (PackageJsonSynchronizer::resolvePackageJson), so without it
     * the controller is shipped, mapped, named in the template — and never
     * written into the host's assets/controllers.json, which means never loaded.
     * Everything installs; nothing binds.
     */
    public function testThePackageIsMarkedAsAUxPackageOrFlexWillNotLookInside(): void
    {
        $keywords = self::composer()['keywords'] ?? null;
        self::assertIsArray($keywords);
        self::assertContains('symfony-ux', $keywords);
    }

    /**
     * The npm-side name has to be the composer name with an '@', because that is
     * the key Flex writes and the key StimulusBundle resolves back to this
     * directory; and the identifier written in the template is StimulusBundle's
     * own normalisation of it. Both are the single likeliest thing to get wrong
     * and the hardest to see.
     */
    public function testTheIdentifierIsTheOneStimulusDerivesFromThePackageName(): void
    {
        $name = self::composer()['name'] ?? null;
        self::assertIsString($name);

        self::assertSame('@'.$name, UhifadhiTeamBundle::ASSET_NAMESPACE);
        self::assertSame(str_replace(['_', '/'], ['-', '--'], $name).'--', UhifadhiTeamBundle::CONTROLLER_PREFIX);
        self::assertSame(UhifadhiTeamBundle::ASSET_NAMESPACE, self::assetPackage()['name'] ?? null);
        self::assertSame(UhifadhiTeamBundle::CONTROLLER_PREFIX.'permission-group', self::IDENTIFIER);
    }

    /**
     * THE PACKAGE DECLARES WHAT THE MARKUP CALLS. A controller named in a
     * template and missing here is a 404 in the console on every page that
     * draws the matrix.
     */
    public function testThePackageShipsTheControllerTheTemplateNames(): void
    {
        $symfony = self::assetPackage()['symfony'] ?? null;
        self::assertIsArray($symfony);
        $controllers = $symfony['controllers'] ?? null;
        self::assertIsArray($controllers);
        // The matrix's select-all controller ships beside the department
        // surfaces' controller (the register's scope toggle and the lens's tabs);
        // both are lazy, host-mapped Stimulus controllers this package declares.
        self::assertSame(['permission-group', 'department'], array_keys($controllers));

        $config = $controllers['permission-group'];
        self::assertIsArray($config);
        self::assertTrue($config['enabled'] ?? false);
        self::assertIsString($config['main'] ?? null);
        self::assertFileExists(\dirname(__DIR__, 2).'/assets/'.$config['main']);

        // LAZY, and that is the honest pairing with a control that ships inert:
        // the matrix is one screen, and a host should not fetch its behaviour on
        // every other page to save a button a few milliseconds of being disabled.
        self::assertSame('lazy', $config['fetch'] ?? null);
    }

    private function matrix(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->administrator();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), ['area.view']);
        $this->em->flush();

        return $this->client->request('GET', '/team/positions?position='.$ranger->getUuidString());
    }

    /** @return array<string, mixed> */
    private static function composer(): array
    {
        $json = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        /** @var array<string, mixed> $json */
        return $json;
    }

    /** @return array<string, mixed> */
    private static function assetPackage(): array
    {
        $json = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/assets/package.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        /** @var array<string, mixed> $json */
        return $json;
    }

    private static function controller(): string
    {
        $js = file_get_contents(\dirname(__DIR__, 2).'/assets/controllers/permission_group_controller.js');
        self::assertIsString($js);

        return $js;
    }
}
