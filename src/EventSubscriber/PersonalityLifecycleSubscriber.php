<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Scout;
use App\Entity\Staff;
use App\Service\Personality\PersonalityContext;
use App\Service\Personality\PersonalityGeneratorService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Auto-fills the Personality Matrix for any Staff/Scout persisted without one.
 * Centralises generation across every creation path (MarketPoolService,
 * app:generate-market-data, admin) the same way AppearanceLifecycleSubscriber
 * does for avatars, so no construction site can forget it.
 *
 * Player is deliberately excluded: PlayerGenerationService rolls a player's
 * matrix as part of its blueprint, because the result feeds back into the
 * power/stamina/heart attribute derivation.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class PersonalityLifecycleSubscriber
{
    public function __construct(
        private readonly PersonalityGeneratorService $generator,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->fill($args->getObject());
    }

    /** Pure logic, no Doctrine args — unit-testable. */
    public function fill(object $entity): void
    {
        $context = match (true) {
            $entity instanceof Staff => PersonalityContext::forStaff($entity->getRole()),
            $entity instanceof Scout => PersonalityContext::forScout(),
            default                  => null,
        };

        if ($context === null) {
            return;
        }

        $profile = $entity->getPersonality();
        if (!$profile->isDefault()) {
            return;
        }

        $this->generator->apply($profile, $context);
    }
}
