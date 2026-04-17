<?php

namespace App\EventSubscriber;

use App\Entity\Club;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

#[AsDoctrineListener(event: Events::prePersist)]
class DomainSeparationSubscriber
{
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Club) {
            $user = $entity->getUser();
            if ($user->getClub() !== null) {
                throw new \DomainException(sprintf(
                    'User "%s" already owns an Club.',
                    $user->getEmail(),
                ));
            }
        }
    }
}
