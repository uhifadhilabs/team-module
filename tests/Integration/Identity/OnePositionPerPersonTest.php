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

namespace Uhifadhi\Team\Tests\Integration\Identity;

use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Repository\UserRepository;
use Uhifadhi\Team\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * ONE PERSON, ONE POSITION — and the department follows the position.
 *
 * Multi-position was rejected: a person's authority-area, once the area-aware
 * voter is wired, must read from a single department's scope, and a union of
 * scopes across two positions is a different model with its own open verdicts
 * (docs/area-scoped-authority.md §7.8). These tests lock the single association
 * in as a decision, so a later collection would fail loudly here rather than
 * silently widen the model.
 */
final class OnePositionPerPersonTest extends IntegrationTestCase
{
    private function person(): User
    {
        return new User()
            ->setEmail('dw@example.test')
            ->setFirstName('Daniel')->setLastName('Wanjala')
            ->setPassword('x')->setVerified(true);
    }

    /**
     * THE ASSOCIATION IS SINGLE-VALUED — a to-one, not a to-many. This is the
     * structural fact the ruling rests on, asserted against the mapping so a
     * refactor to a collection cannot pass unnoticed.
     */
    public function testThePositionAssociationIsToOne(): void
    {
        $metadata = $this->em->getClassMetadata(User::class);

        self::assertTrue($metadata->hasAssociation('position'));
        self::assertTrue(
            $metadata->isSingleValuedAssociation('position'),
            'A person holds exactly one position — the association must stay single-valued.',
        );
        self::assertFalse($metadata->isCollectionValuedAssociation('position'));
    }

    /** Assigning a second position REPLACES the first — there is no accumulation. */
    public function testAssigningAPositionReplacesThePrevious(): void
    {
        $ecology = new Department()->setName('Ecology');
        $protection = new Department()->setName('Protection Service');
        $analyst = new Position()->setName('Analyst')->setDepartment($ecology);
        $ranger = new Position()->setName('Ranger')->setDepartment($protection);
        foreach ([$ecology, $protection, $analyst, $ranger] as $entity) {
            $this->em->persist($entity);
        }

        $person = $this->person()->setPosition($analyst);
        $this->em->persist($person);
        $this->em->flush();

        $person->setPosition($ranger);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneBy(['email' => 'dw@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertInstanceOf(Position::class, $stored->getPosition());
        self::assertSame('Ranger', $stored->getPosition()->getName());
    }

    /**
     * A PERSON'S DEPARTMENT FOLLOWS THEIR POSITION, and its scope with it. Move
     * the person to an area-level position and they belong to an area-level
     * department — the chain the voter will one day read authority from.
     */
    public function testTheDepartmentAndScopeFollowThePosition(): void
    {
        $area = new HostArea()->setName('Ngorongoro');
        $this->em->persist($area);

        $orgDept = new Department()->setName('Ecology');
        $areaDept = new Department()->setName('Crater Management')->setArea($area);
        $orgPos = new Position()->setName('Ecologist')->setDepartment($orgDept);
        $areaPos = new Position()->setName('Crater Ecologist')->setDepartment($areaDept);
        foreach ([$orgDept, $areaDept, $orgPos, $areaPos] as $entity) {
            $this->em->persist($entity);
        }

        $person = $this->person()->setPosition($orgPos);
        $this->em->persist($person);
        $this->em->flush();

        self::assertInstanceOf(Department::class, $person->getDepartment());
        self::assertTrue($person->getDepartment()->isOrgLevel());

        $person->setPosition($areaPos);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(UserRepository::class)->findOneBy(['email' => 'dw@example.test']);
        self::assertInstanceOf(User::class, $stored);
        self::assertInstanceOf(Department::class, $stored->getDepartment());
        self::assertSame('Crater Management', $stored->getDepartment()->getName());
        self::assertTrue($stored->getDepartment()->isAreaLevel());
    }

    /** No position, no department — the Unassigned state, honest all the way down. */
    public function testAPersonWithNoPositionHasNoDepartment(): void
    {
        $person = $this->person();
        $this->em->persist($person);
        $this->em->flush();

        self::assertNull($person->getPosition());
        self::assertNull($person->getDepartment());
    }
}
