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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Uhifadhi\Team\Entity\Department;
use Uhifadhi\Team\Entity\Position;
use Uhifadhi\Team\Repository\DepartmentRepository;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * A DEPARTMENT IS A REAL ENTITY AND THIS MODULE OWNS IT.
 *
 * The previous release argued the opposite: a department was called an
 * organizational lens another module owns, and a position's name was therefore
 * unique across the whole installation. That was wrong about the organisations
 * this product is for. Two departments really do carry the same post — Ecology
 * has an Analyst and Protection Service has an Analyst, and they are two
 * different jobs with different permission sets that happen to share a word. A
 * model forbidding the pair forces one of them to be renamed to something
 * nobody says out loud.
 *
 * So the constraint is `unique(department, name)`, and the org-wide one is
 * gone. A position's name is unique INSIDE its department and nowhere else.
 */
final class DepartmentTest extends IntegrationTestCase
{
    public function testTheTableIsPrefixed(): void
    {
        self::assertSame('team_department', $this->em->getClassMetadata(Department::class)->getTableName());
    }

    public function testADepartmentPersistsWithAUuid(): void
    {
        $department = (new Department())->setName('Protection Service');

        $this->em->persist($department);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(DepartmentRepository::class)->findOneByName('Protection Service');

        self::assertInstanceOf(Department::class, $stored);
        self::assertNotNull($stored->getUuid());
        self::assertNotNull($stored->getCreatedAt());
    }

    /**
     * THE CASE THE RULING EXISTS FOR. Both positions are called "Analyst" and
     * both save, because the pair is two jobs rather than one duplicated.
     */
    public function testTwoDepartmentsMayEachOwnAPositionOfTheSameName(): void
    {
        $ecology = (new Department())->setName('Ecology');
        $protection = (new Department())->setName('Protection Service');

        $this->em->persist($ecology);
        $this->em->persist($protection);
        $this->em->persist((new Position())->setName('Analyst')->setDepartment($ecology));
        $this->em->persist((new Position())->setName('Analyst')->setDepartment($protection));
        $this->em->flush();
        $this->em->clear();

        $positions = $this->em->getRepository(Position::class)->findBy(['name' => 'Analyst']);

        self::assertCount(2, $positions);
    }

    /** Inside one department the name is still the position's identity. */
    public function testOneDepartmentMayNotOwnTwoPositionsOfTheSameName(): void
    {
        $ecology = (new Department())->setName('Ecology');
        $this->em->persist($ecology);
        $this->em->persist((new Position())->setName('Analyst')->setDepartment($ecology));
        $this->em->persist((new Position())->setName('Analyst')->setDepartment($ecology));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    /**
     * THE UNASSIGNED STATE IS REAL. A position's department is nullable, and the
     * null is a state rather than an unfinished field: a position created before
     * anybody decided which department owns it is a position that exists.
     */
    public function testAPositionMayHaveNoDepartment(): void
    {
        $position = (new Position())->setName('Chief Warden');

        $this->em->persist($position);
        $this->em->flush();
        $this->em->clear();

        $stored = $this->em->getRepository(Position::class)->findOneBy(['name' => 'Chief Warden']);

        self::assertInstanceOf(Position::class, $stored);
        self::assertNull($stored->getDepartment());
    }

    /**
     * NOTHING IS UNIQUE ORG-WIDE ANY MORE, and the old index is named here so
     * that its removal is a decision rather than a diff nobody read.
     */
    public function testThePositionNameIsNoLongerUniqueAcrossTheInstallation(): void
    {
        $constraints = $this->em->getClassMetadata(Position::class)->table['uniqueConstraints'] ?? [];

        self::assertSame(
            ['uniq_team_position_department_name' => ['fields' => ['department', 'name']]],
            $constraints,
            'One unique constraint, and it is the department-scoped one. uniq_team_position_name is gone.',
        );
    }

    /** A department reads back the positions filed under it. */
    public function testADepartmentKnowsItsPositions(): void
    {
        $ecology = (new Department())->setName('Ecology');
        $this->em->persist($ecology);
        $this->em->persist((new Position())->setName('Analyst')->setDepartment($ecology));
        $this->em->persist((new Position())->setName('Veterinary Officer')->setDepartment($ecology));
        $this->em->flush();
        $this->em->clear();

        $stored = $this->service(DepartmentRepository::class)->findOneByName('Ecology');
        self::assertInstanceOf(Department::class, $stored);

        self::assertCount(2, $stored->getPositions());
    }
}
