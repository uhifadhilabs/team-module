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

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Uhifadhi\ModuleContracts\Entity\UserInterface as ModuleUserInterface;
use Uhifadhi\Team\Entity\User;
use Uhifadhi\Team\Tests\Integration\Fixtures\Resolution\HostAccount;
use Uhifadhi\Team\Tests\Integration\Fixtures\Resolution\PatrolRecord;
use Uhifadhi\Team\Tests\Integration\Fixtures\Resolution\ResolutionKernel;

/**
 * TEAM ANSWERS THE USER CONTRACT, SO TEAM SAYS SO — and an installation writes
 * nothing.
 *
 * Nearly every module keeps records with a name on them and points at
 * `Uhifadhi\ModuleContracts\Entity\UserInterface` rather than at this bundle's
 * `User`, because a module that type-hinted the latter would be a module no
 * installation could run without this one. Something has to close that loop.
 *
 * IT USED TO BE A DOCUMENTED HAND-STEP, and that was the wrong shape. The line
 * an installation was told to paste says exactly one thing —
 * "`UserInterface` means `Uhifadhi\Team\Entity\User`" — and the package best
 * placed to know that is the package that provides the answer. A hand-step is
 * for a decision only the installation can make; this was not one. Its cost was
 * real: forget it and the container still boots, the kernel still starts, and
 * `doctrine:migrations:diff` stops on "Class
 * 'Uhifadhi\ModuleContracts\Entity\UserInterface' does not exist" — a failure
 * a long way from its cause.
 *
 * SO THE BUNDLE PREPENDS IT ({@see \Uhifadhi\Team\UhifadhiTeamBundle::prependExtension()}),
 * and the whole of this suite already depends on that: the test kernel writes no
 * resolution, so if the prepend stopped happening every integration test here
 * would fail at once. These two assertions are the ones that say so ON PURPOSE
 * rather than as a side effect, in the smallest host the question can be asked
 * in — framework, doctrine, this bundle, and one module's entity.
 *
 * THE ESCAPE HATCH IS SYMFONY'S OWN RULE, not a switch this module invented:
 * prepended configuration LOSES to the application's. An installation whose
 * people are its own entity names that entity in its `doctrine.yaml` and its
 * answer wins, with nothing here to turn off first. That property is what makes
 * the default safe to ship, so it is tested rather than assumed.
 */
final class ResolveTargetEntitiesTest extends TestCase
{
    /** @param array<class-string, class-string> $override */
    private function metadataFor(array $override, string $variant): EntityManagerInterface
    {
        $kernel = new ResolutionKernel($override, $variant);
        $kernel->boot();

        /** @var EntityManagerInterface $em */
        $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    /**
     * AN INSTALLATION THAT WRITES NOTHING GETS THE RIGHT ANSWER. This is the
     * whole ruling: the association a module declared against the contract
     * resolves to this bundle's account, with no `doctrine.yaml` edit anywhere.
     */
    public function testAModulesAssociationResolvesToTeamsUserWithNothingConfigured(): void
    {
        $em = $this->metadataFor([], 'plain');

        $association = $em->getClassMetadata(PatrolRecord::class)->getAssociationMapping('ledBy');

        self::assertSame(User::class, $association['targetEntity']);
    }

    /**
     * And the metadata is COMPLETE, not merely named: the join column is built,
     * which is the part `migrations:diff` needs and the part that was failing.
     */
    public function testTheResolvedAssociationIsAWholeMapping(): void
    {
        $em = $this->metadataFor([], 'plain');

        $metadata = $em->getClassMetadata(PatrolRecord::class);

        self::assertSame(User::class, $metadata->getAssociationTargetClass('ledBy'));
        // The target's own metadata is reachable, which is what the schema tool
        // walks and what stopped before.
        self::assertSame('team_user', $em->getClassMetadata(User::class)->getTableName());
    }

    /**
     * THE ESCAPE HATCH. An installation with its own account class names it and
     * wins, because prepended configuration loses to the application's — which
     * is Symfony's rule and the reason shipping a default here is safe.
     */
    public function testAnApplicationsOwnResolutionWins(): void
    {
        $em = $this->metadataFor(
            [ModuleUserInterface::class => HostAccount::class],
            'override',
        );

        $association = $em->getClassMetadata(PatrolRecord::class)->getAssociationMapping('ledBy');

        self::assertSame(HostAccount::class, $association['targetEntity']);
        self::assertNotSame(User::class, $association['targetEntity'], 'The bundle must not overrule the installation.');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
