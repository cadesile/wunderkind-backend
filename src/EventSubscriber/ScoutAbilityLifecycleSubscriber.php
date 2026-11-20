<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Scout;
use App\Service\ScoutAbilityCalculator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
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
 * ⚠️ **prePersist ONLY — an admin EDIT does not re-derive.** Changing a scout's `experience` or
 * `judgements` in the admin leaves the stored ability as it was, exactly as it leaves
 * `weeklySalary` as it was (`ScoutWageCalculator` is not re-run on edit either).
 * `app:backfill-scout-abilities --force` is the documented path, and the admin field's help text
 * says so. A `preUpdate` hook would need `recomputeSingleEntityChangeSet()` to make the write
 * stick, which is a sharp edge worth adding only if editing scouts by hand becomes routine.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class ScoutAbilityLifecycleSubscriber
{
    public function __construct(
        private readonly ScoutAbilityCalculator $abilityCalculator,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->fill($args->getObject());
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
