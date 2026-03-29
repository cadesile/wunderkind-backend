<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Staff;
use App\Entity\User;
use App\Enum\StaffRole;
use App\Enum\Tier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/staff')]
#[IsGranted('ROLE_ACADEMY')]
class StaffController extends AbstractController
{
    #[Route('', name: 'api_staff_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['error' => 'No academy found.'], Response::HTTP_NOT_FOUND);
        }

        $tierParam = $request->query->get('tier');
        $tier      = $tierParam !== null ? Tier::tryFrom($tierParam) : null;

        $coaches = [];
        $scouts  = [];

        foreach ($academy->getStaff() as $member) {
            if ($member->getRole() === StaffRole::SCOUT) {
                if ($tier === null || Tier::fromScore($member->getScoutingRange()) === $tier) {
                    $scouts[] = $this->serializeScout($member);
                }
            } else {
                if ($tier === null || Tier::fromScore($member->getCoachingAbility()) === $tier) {
                    $coaches[] = $this->serializeCoach($member);
                }
            }
        }

        return $this->json(['coaches' => $coaches, 'scouts' => $scouts]);
    }

    private function serializeCoach(Staff $s): array
    {
        return [
            'id'              => $s->getId()->toRfc4122(),
            'firstName'       => $s->getFirstName(),
            'lastName'        => $s->getLastName(),
            'dateOfBirth'     => $s->getDob()?->format('Y-m-d'),
            'nationality'     => $s->getNationality(),
            'role'            => $s->getRoleValue(),
            'coachingAbility' => $s->getCoachingAbility(),
            'scoutingRange'   => $s->getScoutingRange(),
            'weeklySalary'    => $s->getWeeklySalary(),
            'morale'          => $s->getMorale(),
            'specialisms'     => $s->getSpecialisms() ?? [],
        ];
    }

    private function serializeScout(Staff $s): array
    {
        return [
            'id'          => $s->getId()->toRfc4122(),
            'name'        => $s->getFullName(),
            'dateOfBirth' => $s->getDob()?->format('Y-m-d'),
            'nationality' => $s->getNationality(),
            'scoutingRange' => $s->getScoutingRange(),
            'morale'      => $s->getMorale(),
            'weeklySalary' => $s->getWeeklySalary(),
            'specialisms' => $s->getSpecialisms() ?? [],
        ];
    }
}
