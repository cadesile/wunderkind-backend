<?php
namespace App\EventSubscriber;

use App\Entity\Agent;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\Enum\Appearance\AppearanceRole;
use App\Service\Appearance\AppearanceGeneratorService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Auto-fills a generated appearance for any Player/Staff/Scout/Agent persisted
 * without one. Centralises appearance generation across every creation path
 * (services, commands, admin) so no construction site can forget it.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class AppearanceLifecycleSubscriber
{
    /** Fallback age for staff/scout/agent whose dob is null. */
    private const DEFAULT_STAFF_AGE = 40;

    public function __construct(
        private readonly AppearanceGeneratorService $generator,
    ) {}

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->fill($args->getObject());
    }

    /** Pure logic, no Doctrine args — unit-testable. */
    public function fill(object $entity): void
    {
        if (!$entity instanceof Player
            && !$entity instanceof Staff
            && !$entity instanceof Scout
            && !$entity instanceof Agent) {
            return;
        }
        if ($entity->getAppearance() !== null) {
            return;
        }

        [$role, $age] = $this->roleAndAge($entity);
        $entity->setAppearance(
            $this->generator->generate(
                (string) $entity->getId(),
                $role,
                $age,
                $entity->getNationality(),
            )
        );
    }

    /**
     * Recomputes just the `skinTone` of an entity that already has an
     * appearance, leaving the other nine fields untouched. Used to roll the
     * region-weighted distribution over rows generated before it existed, so
     * existing avatars keep their hair, accessory and kit.
     *
     * @return bool True when the tone actually changed.
     */
    public function refreshSkinTone(object $entity): bool
    {
        if (!$entity instanceof Player
            && !$entity instanceof Staff
            && !$entity instanceof Scout
            && !$entity instanceof Agent) {
            return false;
        }

        $appearance = $entity->getAppearance();
        if ($appearance === null) {
            return false;
        }

        [$role, $age] = $this->roleAndAge($entity);
        $skinTone = $this->generator->generate(
            (string) $entity->getId(),
            $role,
            $age,
            $entity->getNationality(),
        )['skinTone'];

        if (($appearance['skinTone'] ?? null) === $skinTone) {
            return false;
        }

        $entity->setAppearance(array_replace($appearance, ['skinTone' => $skinTone]));

        return true;
    }

    /** @return array{0: AppearanceRole, 1: int} */
    private function roleAndAge(Player|Staff|Scout|Agent $entity): array
    {
        if ($entity instanceof Player) {
            return [AppearanceRole::PLAYER, $this->ageFromDob($entity->getDateOfBirth())];
        }
        if ($entity instanceof Staff) {
            return [AppearanceRole::COACH, $this->ageFromDob($entity->getDob())];
        }
        if ($entity instanceof Scout) {
            return [AppearanceRole::SCOUT, $this->ageFromDob($entity->getDob())];
        }
        return [AppearanceRole::AGENT, $this->ageFromDob($entity->getDob())];
    }

    private function ageFromDob(?\DateTimeImmutable $dob): int
    {
        if ($dob === null) {
            return self::DEFAULT_STAFF_AGE;
        }
        return (int) $dob->diff(new \DateTimeImmutable('now'))->y;
    }
}
