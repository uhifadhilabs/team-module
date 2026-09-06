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
use Uhifadhi\Team\Tests\Integration\Fixtures\Area\HostArea;
use Uhifadhi\Team\Tests\Integration\IntegrationTestCase;

/**
 * A DEPARTMENT NAME IS UNIQUE PER AREA, NOT ACROSS THE INSTALLATION.
 *
 * The previous release made a department name unique org-wide, on the reading
 * that there is one organisation and two departments by one name would be the
 * same department entered twice. That is right for the org-wide bucket and wrong
 * for the areas beneath it. Two protected areas each run their own Anti-Poaching
 * unit; forbidding the pair would force one of them to be renamed to something
 * nobody on that area says out loud.
 *
 * So the rule is scoped: within one area a name is unique, within the org-wide
 * bucket (a null area) a name is unique, and the two buckets are independent.
 * The constraint is two partial unique indexes rather than one global one —
 * `unique(name, area_id)` alone would let two org-level rows share a name,
 * because in SQL two NULLs are distinct.
 */
final class DepartmentNameScopeTest extends IntegrationTestCase
{
    private function area(string $name): HostArea
    {
        $area = (new HostArea())->setName($name);
        $this->em->persist($area);

        return $area;
    }

    /**
     * THE CASE THE RULING EXISTS FOR. Two areas each own an "Anti-Poaching"
     * department, and both save, because they are two units that share a word.
     */
    public function testTwoAreasMayEachOwnADepartmentOfTheSameName(): void
    {
        $serengeti = $this->area('Serengeti');
        $ruaha = $this->area('Ruaha');

        $this->em->persist((new Department())->setName('Anti-Poaching')->setArea($serengeti));
        $this->em->persist((new Department())->setName('Anti-Poaching')->setArea($ruaha));
        $this->em->flush();
        $this->em->clear();

        self::assertCount(2, $this->em->getRepository(Department::class)->findBy(['name' => 'Anti-Poaching']));
    }

    /** Inside one area the name is still the department's identity. */
    public function testOneAreaMayNotOwnTwoDepartmentsOfTheSameName(): void
    {
        $serengeti = $this->area('Serengeti');

        $this->em->persist((new Department())->setName('Anti-Poaching')->setArea($serengeti));
        $this->em->persist((new Department())->setName('Anti-Poaching')->setArea($serengeti));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    /**
     * THE ORG-WIDE BUCKET IS STILL UNIQUE. Two org-level departments (null area)
     * with one name are the same department entered twice — the reading the old
     * global constraint got right, and the one a plain composite would lose to
     * NULL-distinctness.
     */
    public function testTwoOrgLevelDepartmentsMayNotShareAName(): void
    {
        $this->em->persist((new Department())->setName('Administration'));
        $this->em->persist((new Department())->setName('Administration'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    /**
     * THE TWO BUCKETS ARE INDEPENDENT. An org-wide department and one confined to
     * an area may carry the same name: they are different scopes, and the scope
     * is part of what the name is unique within.
     */
    public function testAnOrgLevelAndAnAreaLevelDepartmentMayShareAName(): void
    {
        $serengeti = $this->area('Serengeti');

        $this->em->persist((new Department())->setName('Ecology'));
        $this->em->persist((new Department())->setName('Ecology')->setArea($serengeti));
        $this->em->flush();
        $this->em->clear();

        self::assertCount(2, $this->em->getRepository(Department::class)->findBy(['name' => 'Ecology']));
    }

    /**
     * THE CONSTRAINT IS THE SCOPED PAIR, and the old global index is named here
     * so its removal is a decision rather than a diff nobody read.
     */
    public function testTheConstraintIsTheScopedPairNotTheGlobalOne(): void
    {
        $constraints = $this->em->getClassMetadata(Department::class)->table['uniqueConstraints'] ?? [];

        self::assertArrayHasKey('uniq_team_department_name_org', $constraints);
        self::assertArrayHasKey('uniq_team_department_name_area', $constraints);
        self::assertArrayNotHasKey('uniq_team_department_name', $constraints,
            'the org-wide-only constraint is gone; uniqueness is per area');
    }
}
