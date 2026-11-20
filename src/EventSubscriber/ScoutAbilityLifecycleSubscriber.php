<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Scout;
use App\Service\ScoutAbilityCalculator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Derives `Scout::$ability` for every scout on its way into the database.
 *
 * WHY A SUBSCRIBER AND NOT JUST A CALL AT EACH GENERATION SITE. Both generation paths
 * (`ScoutGenerationService::build()` and `app:generate-market-data`) do set it explicitly, and
 * they should — reading those two files should show where the figure comes from. This is the net
 * underneath them, for the same reason `PersonalityLifecycleSubscriber` exists and says so in its
 * own docblock: it "centralises generation across every creation path ... so no construction site
 * can forget it". The sites that would otherwise land the column's floor of 1 are the ADMIN's
 * create form, which cannot set `ability` by design, and any future path nobody has written yet.
 *
 * A 1 is not a harmless placeholder here. A24 prices a scout's wage off this column, so an
 * underived scout would be paid at the very bottom of the band — and the app's pack-is-a-census
 * rule names a zero-or-floor fallback on an ingested value a defect rather than a default.
 *
 * UNCONDITIONAL, AND THAT IS SAFE BECAUSE THE FORMULA IS DETERMINISTIC. It does not check for
 * the floor first and it does not need to: re-deriving a scout that `ScoutGenerationService` just
 * derived produces the identical figure, so the two cannot disagree. Compare
 * `PersonalityLifecycleSubscriber`, which must check `isDefault()` precisely because its
 * generator DOES roll — an unconditional call there would re-roll a deliberately authored matrix.
 * There is nothing to protect here, because `ability` is never authored: the admin exposes it
 * read-only on purpose.
 *
 * ⚠️ **AND ON UPDATE TOO, added 2026-11-20 after this class first shipped prePersist-only.**
 * The original note here said an admin edit deliberately did not re-derive, with
 * `app:backfill-scout-abilities --force` as the documented path. **That was wrong once A24 is in
 * view**, and the reason is worth keeping rather than quietly deleting:
 *
 * `ScoutCrudController` exposes `experience` and `judgementsJson` as EDITABLE while `ability` is
 * read-only. So an admin could move an input and leave the derived figure stale, with nothing in
 * the schema or the UI saying the two now disagree — and A24 prices a scout's WAGE off `ability`,
 * which turns a stale figure into a wrong wage, discovered far from its cause. That is precisely
 * the two-routes-to-one-quantity defect this workstream exists to remove, and relying on an
 * operator to remember a `--force` run is not a mechanism. So the derivation now runs on every
 * write, and `ability` is a true function of its inputs at all times.
 *
 * A useful side effect: because the derivation runs unconditionally on update, a hand-crafted
 * POST that tried to set `ability` directly is CORRECTED rather than merely ignored.
 *
 * ⚠️ **`weeklySalary` is deliberately NOT treated this way, and the asymmetry is principled.**
 * Its admin help text says it is "set by hand here only to override one scout" — it is an
 * intentionally overridable figure, so auto-recomputing it would destroy the override that is its
 * whole purpose. `ability` is intentionally NOT overridable (read-only by design), so it must be
 * recomputed. **The test is whether the field is an override or a derivation**, not whether it
 * happens to be derived at generation.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class ScoutAbilityLifecycleSubscriber
{
    public function __construct(
        private readonly ScoutAbilityCalculator $abilityCalculator,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->fill($args->getObject());
    }

    /**
     * ⚠️ **A changed field in `preUpdate` does NOT stick on its own.** By the time this fires the
     * unit of work has already computed its changeset, so a plain setter call writes to the
     * object and is then ignored by the UPDATE. `recomputeSingleEntityChangeSet()` is Doctrine's
     * documented way to fold the new value in, and it is why this method cannot simply call
     * `fill()` like `prePersist` does.
     *
     * The early return is what keeps that safe and cheap: when the derived figure already matches
     * the stored one — the overwhelmingly common case, since most updates touch neither
     * `experience` nor `judgements` — nothing is written and no changeset is recomputed. A
     * recompute does not re-fire `preUpdate`, so there is no loop here either way.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $scout = $args->getObject();

        if (!$scout instanceof Scout) {
            return;
        }

        $derived = $this->abilityCalculator->abilityForScout($scout);

        if ($derived === $scout->getAbility()) {
            return;
        }

        $scout->setAbility($derived);

        $em = $args->getObjectManager();
        $em->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $em->getClassMetadata(Scout::class),
            $scout,
        );
    }

    /** Pure logic, no Doctrine args — unit-testable, same shape as PersonalityLifecycleSubscriber::fill(). */
    public function fill(object $entity): void
    {
        if (!$entity instanceof Scout) {
            return;
        }

        $entity->setAbility($this->abilityCalculator->abilityForScout($entity));
    }
}
