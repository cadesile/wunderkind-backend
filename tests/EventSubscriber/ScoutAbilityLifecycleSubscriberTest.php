<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Scout;
use App\Service\ScoutAbilityCalculator;
use App\Service\ScoutWageCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * THE WIRE between `ScoutAbilityCalculator`, `ScoutWageCalculator` and Doctrine, exercised
 * through a real flush.
 *
 * ⚠️ **Why this is a functional test and not a unit test, and why it exists at all.**
 * `ScoutAbilityCalculatorTest` proves the formula. `debug:container` proves the listener is
 * tagged. **Neither proves the wire between them**, and a subscriber that silently does not fire
 * looks exactly like one that does: every other test still passes, because the two generation
 * paths set `ability` explicitly and never need the subscriber at all. The only paths that DO
 * need it are the admin create form and any future writer nobody has written yet.
 *
 * That gap was shipped knowingly and then closed on review, because **A24 prices a scout's WAGE
 * off `ability`**. A scout written outside the generation path with a stale or defaulted ability
 * becomes a wrong wage, discovered far from its cause and long after. "Registration is verified,
 * behaviour is not" is an acceptable gap right up until a money path reads the field.
 *
 * It is also the shape of defect this whole workstream keeps finding: two routes to one quantity
 * where only one is actually exercised.
 *
 * ⚠️ **If every test here errors with `column "ability" does not exist`, the test database is the
 * cause, not this change.** `wunderkind_test` is not migration-tracked — see
 * `.context/stages/01_overview/output/environment.md` for the five commands.
 *
 * ⚠️ **A24, 2026-11-20: `weeklySalary` IS DERIVED HERE TOO NOW.** Owner ruling — once a wage is
 * priced from a rule it is a derivation, not an override, so it gets the same `preUpdate`
 * treatment `ability` has and the admin field is read-only. The cases at the bottom of this file
 * are that half, and they are the ones that catch a wage left stale behind an edited input, which
 * is the exact failure the `ability` half was added to prevent one layer up. **No schema change
 * was needed** — both columns already exist, so the hand-migration note above does not apply to
 * A24.
 */
class ScoutAbilityLifecycleSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ScoutAbilityCalculator $calculator;
    private ScoutWageCalculator $wageCalculator;

    /** @var Scout[] */
    private array $cleanup = [];

    /** The five keys `ScoutGenerationService` actually writes. */
    private const JUDGEMENTS = [
        'potential'   => 40,
        'technical'   => 40,
        'physical'    => 40,
        'mental'      => 40,
        'personality' => 40,
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->calculator = self::getContainer()->get(ScoutAbilityCalculator::class);
        $this->wageCalculator = self::getContainer()->get(ScoutWageCalculator::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $scout) {
            $managed = $this->em->find(Scout::class, $scout->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    /**
     * The admin create form's path: a `Scout` persisted with its inputs set and `ability` left at
     * the entity default. Nothing in the request sets `ability` — the admin field is read-only by
     * design — so the subscriber is the only thing that can fill it.
     */
    public function testPersistingAScoutDerivesItsAbility(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);

        // 0.7 * 80 + 0.3 * 40 = 68.
        self::assertSame(68, $scout->getAbility());
        self::assertSame(
            $this->calculator->abilityFor(80, self::JUDGEMENTS),
            $scout->getAbility(),
            'the stored figure must be exactly what the calculator derives',
        );
    }

    /**
     * ⚠️ The invariant that matters, asserted against the DATABASE rather than the object: a
     * persisted scout is never left at the column's floor. An in-memory assertion would pass even
     * if the value never reached the UPDATE.
     */
    public function testAPersistedScoutIsNeverLeftAtTheFloorInTheDatabase(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);

        $stored = $this->em->getConnection()->fetchOne(
            'SELECT ability FROM scout WHERE id = ?',
            [(string) $scout->getId()],
        );

        self::assertSame(68, (int) $stored, 'the derived figure must be what actually landed');
        self::assertNotSame(1, (int) $stored, 'a floor value means the subscriber did not fire');
    }

    /**
     * 🔴 **THE CASE A GENERATION-TIME TEST COULD NEVER COVER.** `experience` and `judgementsJson`
     * are both EDITABLE in `ScoutCrudController` while `ability` is read-only, so without a
     * `preUpdate` hook an admin could move an input and leave the derived figure stale — with
     * nothing in the schema or the UI saying the two disagree. Under A24 that is a wrong wage.
     */
    public function testChangingExperienceReDerivesAbilityOnUpdate(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);
        self::assertSame(68, $scout->getAbility());

        $scout->setExperience(20);
        $this->em->flush();

        // 0.7 * 20 + 0.3 * 40 = 26.
        self::assertSame(26, $scout->getAbility(), 'ability must follow its inputs on update');

        $this->em->clear();
        $reloaded = $this->em->find(Scout::class, $scout->getId());
        self::assertSame(26, $reloaded->getAbility(), 'and must have been written, not just set');
    }

    /** The other input, since the blend reads both. */
    public function testChangingJudgementsReDerivesAbilityOnUpdate(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);
        self::assertSame(68, $scout->getAbility());

        $scout->setJudgements(['potential' => 90, 'technical' => 90, 'physical' => 90, 'mental' => 90, 'personality' => 90]);
        $this->em->flush();

        // 0.7 * 80 + 0.3 * 90 = 83.
        self::assertSame(83, $scout->getAbility());
    }

    /**
     * `ability` is read-only in the admin by design. This proves the design is ENFORCED rather
     * than merely presented: a write that sets it directly — a hand-crafted POST, or any future
     * code that "helpfully" assigns it — is corrected back to the derivation on flush.
     */
    public function testAnAbilityWrittenByHandIsCorrectedRatherThanHonoured(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);

        $scout->setAbility(5);
        $this->em->flush();

        self::assertSame(68, $scout->getAbility(), 'the derivation wins; there is one source');
    }

    /**
     * An update that touches neither input must leave `ability` alone — which is also what makes
     * the hook cheap, since the early return skips the changeset recompute entirely.
     */
    public function testAnUnrelatedUpdateLeavesAbilityUntouched(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);

        $scout->setName('Renamed Scout');
        $this->em->flush();

        self::assertSame(68, $scout->getAbility());
        self::assertSame('Renamed Scout', $scout->getName());
    }

    /**
     * Not a judgement-shaped row: the blend falls back to experience alone rather than averaging
     * against a fabricated 0, and the subscriber must carry that through a real flush too.
     */
    public function testAScoutWithNoJudgementsFallsBackToExperienceNotZero(): void
    {
        $scout = $this->persistScout(60, []);

        self::assertSame(60, $scout->getAbility());
    }

    // ── A24: the wage half ────────────────────────────────────────────────────────────────

    /**
     * 🔴 **THE CORE A24 ASSERTION.** A scout persisted with its inputs set and `weeklySalary`
     * left at the column's DEFAULT 0 must come out priced. Nothing in the admin request can set
     * it — the field is read-only by design since A24 — so the subscriber is the only thing that
     * can, exactly as for `ability`.
     *
     * A 0 here is not a harmless placeholder: a free scout understates every club's running
     * costs, which is the projection `POST /api/club/fund` reports.
     */
    public function testPersistingAScoutDerivesItsWeeklySalary(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);

        self::assertSame(68, $scout->getAbility());
        self::assertNotSame(0, $scout->getWeeklySalary(), 'a 0 means the subscriber did not fire');
        self::assertSame(
            $this->wageCalculator->weeklyWageFor(68),
            $scout->getWeeklySalary(),
            'the wage must be exactly what the calculator derives from the DERIVED ability',
        );
    }

    /**
     * 🔴 **THE WAGE IS PRICED OFF ABILITY, NOT EXPERIENCE.** The regression this pins is the whole
     * of A24's ruling, and it is invisible to a type checker: both are 0-100 ints, so feeding the
     * wrong one produces a plausible wage that disagrees with every other scout in the game.
     * 80 and 68 are deliberately different here for exactly that reason.
     */
    public function testTheWageIsDerivedFromAbilityAndNotFromExperience(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);

        self::assertSame($this->wageCalculator->weeklyWageFor(68), $scout->getWeeklySalary());
        self::assertNotSame($this->wageCalculator->weeklyWageFor(80), $scout->getWeeklySalary());
    }

    /**
     * ⚠️ The case the `--force` backfill was being relied on for, and an operator remembering to
     * run a command is not a mechanism. An admin edits `experience`, `ability` re-derives — and
     * before A24 the WAGE did not follow, so the row's three figures disagreed with nothing in
     * the schema or the UI saying so.
     */
    public function testChangingExperienceReDerivesTheWageOnUpdate(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);
        $before = $scout->getWeeklySalary();

        $scout->setExperience(20);
        $this->em->flush();

        // 0.7 * 20 + 0.3 * 40 = 26.
        self::assertSame(26, $scout->getAbility());
        self::assertSame($this->wageCalculator->weeklyWageFor(26), $scout->getWeeklySalary());
        self::assertLessThan($before, $scout->getWeeklySalary(), 'a worse scout must cost less');

        $this->em->clear();
        $reloaded = $this->em->find(Scout::class, $scout->getId());
        self::assertSame(
            $this->wageCalculator->weeklyWageFor(26),
            $reloaded->getWeeklySalary(),
            'and must have been WRITTEN, not just set on the object',
        );
    }

    /**
     * A DERIVATION, NOT AN OVERRIDE — the owner's ruling, enforced rather than merely documented.
     *
     * The help text this replaces promised the field was "set by hand here only to override one
     * scout". A live-looking admin field nothing honours is the defect class this workstream
     * exists to remove, so the promise had to go in the same change as the behaviour.
     */
    public function testAWageWrittenByHandIsCorrectedRatherThanHonoured(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);

        $scout->setWeeklySalary(999_999);
        $this->em->flush();

        self::assertSame(
            $this->wageCalculator->weeklyWageFor(68),
            $scout->getWeeklySalary(),
            'the derivation wins; there is one source for a wage',
        );
    }

    /** An update that touches neither input leaves the wage alone, same as `ability`. */
    public function testAnUnrelatedUpdateLeavesTheWageUntouched(): void
    {
        $scout = $this->persistScout(80, self::JUDGEMENTS);
        $before = $scout->getWeeklySalary();

        $scout->setName('Renamed Scout');
        $this->em->flush();

        self::assertSame($before, $scout->getWeeklySalary());
    }

    /** @param array<string, int> $judgements */
    private function persistScout(int $experience, array $judgements): Scout
    {
        $scout = new Scout('Subscriber Test ' . bin2hex(random_bytes(4)));
        $scout->setExperience($experience);
        $scout->setJudgements($judgements);
        // `ability` is deliberately NOT set — that is the whole point.

        $this->em->persist($scout);
        $this->em->flush();

        $this->cleanup[] = $scout;

        return $scout;
    }
}

