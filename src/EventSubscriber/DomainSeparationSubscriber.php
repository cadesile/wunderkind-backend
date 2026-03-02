<?php

namespace App\EventSubscriber;

use App\Entity\Academy;
use App\Entity\Admin;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsDoctrineListener(event: Events::prePersist)]
class DomainSeparationSubscriber
{
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Admin) {
            $user = $entity->getUser();
            if ($user->getAcademy() !== null) {
                throw new \DomainException(sprintf(
                    'User "%s" already owns an Academy and cannot be promoted to Admin.',
                    $user->getEmail(),
                ));
            }
        }

        if ($entity instanceof Academy) {
            $user = $entity->getUser();
            if ($user->getAdmin() !== null) {
                throw new \DomainException(sprintf(
                    'User "%s" is already an Admin and cannot own an Academy.',
                    $user->getEmail(),
                ));
            }
        }
    }
}
