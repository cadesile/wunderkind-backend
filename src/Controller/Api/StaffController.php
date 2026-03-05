<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Staff;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/staff')]
#[IsGranted('ROLE_ACADEMY')]
class StaffController extends AbstractController
{
    #[Route('', name: 'api_staff_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user    = $this->getUser();
        $academy = $user->getAcademy();

        if ($academy === null) {
            return $this->json(['error' => 'No academy found.'], Response::HTTP_NOT_FOUND);
        }

        $staff = $academy->getStaff()->map(function (Staff $member): array {
            return [
                'id'              => $member->getId()->toRfc4122(),
                'firstName'       => $member->getFirstName(),
                'lastName'        => $member->getLastName(),
                'role'            => $member->getRoleValue(),
                'specialty'       => $member->getSpecialty(),
                'specialisms'     => $member->getSpecialisms(),
                'morale'          => $member->getMorale(),
                'coachingAbility' => $member->getCoachingAbility(),
                'scoutingRange'   => $member->getScoutingRange(),
                'weeklySalary'    => $member->getWeeklySalary(),
            ];
        })->toArray();

        return $this->json(['staff' => array_values($staff)]);
    }
}
