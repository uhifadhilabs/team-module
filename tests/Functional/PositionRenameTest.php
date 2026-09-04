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

use Uhifadhi\Team\Entity\Position;

/**
 * RENAME, FROM THE PANE HEADER THE DESIGN DRAWS IT IN.
 *
 * `team_position_rename` existed, was gated, was tested through a hand-built
 * POST — and no page linked it. A route nobody can reach is a feature nobody
 * has, and the design had drawn where it goes all along: the pane header's
 * right-hand group, beside the position's name.
 *
 * ONE DEVIATION FROM THE DRAWING, STATED OUT LOUD. The design draws a bare
 * `Rename` button. The route needs a name, and a static page has nowhere to put
 * the thing that collects one, so the header renders the field the button
 * submits — prefilled with the name it is about. The alternative was a button
 * that opens something nobody has designed yet, or a button that does nothing,
 * and the second is the failure this module has already shipped once.
 *
 * DELETE IS NOT HERE. The design draws it next to Rename and its semantics are
 * a question nobody has answered — what happens to the people holding the
 * position — so it stays undrawn until somebody rules on it. Rendering a
 * destructive control ahead of its ruling is how the ruling gets made by
 * accident.
 *
 * THE HEADER LIVES INSIDE THE PERMISSIONS FORM, and forms do not nest. The
 * rename form is a sibling of it and the header's controls join it by `form=`,
 * which is what that attribute is for: two independent writes on one pane, and
 * neither can post the other's fields.
 */
final class PositionRenameTest extends WebTestCaseWithSchema
{
    public function testThePaneHeaderRendersTheRenameControl(): void
    {
        $crawler = $this->matrix();

        $rename = $crawler->filter('[data-pm="b"] .pm-head .hr');
        self::assertCount(1, $rename, 'The pane header has no right-hand control group.');
        self::assertStringContainsString('Rename', $rename->text());
    }

    /** DELETE IS NOT DRAWN, and that is asserted rather than merely true today. */
    public function testDeleteIsNotDrawnBecauseNobodyHasRuledOnIt(): void
    {
        $crawler = $this->matrix();

        self::assertStringNotContainsString('Delete', $crawler->filter('[data-pm="b"] .pm-head')->text());
        self::assertCount(0, $crawler->filter('[data-pm="b"] .pm-head .softbtn.danger'));
    }

    /**
     * THE CONTROL IS THE ONE A PERSON PRESSES, found the way a person finds it,
     * and it renames. The hand-built POST this route already had could not see
     * that nothing rendered it.
     */
    public function testPressingRenameOnTheRenderedPageRenamesThePosition(): void
    {
        $crawler = $this->matrix();

        $form = $crawler->filter('[data-pm="b"]')->selectButton('Rename')->form();
        $form['name'] = 'Senior Ranger';
        $this->client->submit($form);

        self::assertResponseRedirects();

        $this->em->clear();
        self::assertNull($this->em->getRepository(Position::class)->findOneBy(['name' => 'Ranger']));
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Senior Ranger']);
        self::assertInstanceOf(Position::class, $stored);
    }

    /**
     * THE TWO FORMS ON THE PANE STAY SEPARATE. Renaming may not carry the
     * permission boxes along with it, and saving permissions may not carry the
     * name — the `form=` association is the whole mechanism and a nested form
     * would silently break it.
     */
    public function testRenamingDoesNotTouchThePermissionsAndSavingDoesNotTouchTheName(): void
    {
        $crawler = $this->matrix();

        $rename = $crawler->filter('[data-pm="b"]')->selectButton('Rename')->form();
        self::assertArrayNotHasKey('permissions', $rename->getPhpValues(), 'The rename posts the matrix with it.');

        $save = $crawler->filter('form.pane')->selectButton('Save')->form();
        self::assertArrayNotHasKey('name', $save->getPhpValues(), 'The save posts the name with it.');

        $rename['name'] = 'Senior Ranger';
        $this->client->submit($rename);

        $this->em->clear();
        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Senior Ranger']);
        self::assertInstanceOf(Position::class, $stored);
        self::assertSame(['area.view'], $stored->getPermissionValues(), 'A rename revoked a permission.');
    }

    private function matrix(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->administrator();
        $ranger = $this->position('Ranger', $this->department('Protection Service'), ['area.view']);
        $this->em->flush();

        return $this->client->request('GET', '/team/positions?position='.$ranger->getUuidString());
    }
}
