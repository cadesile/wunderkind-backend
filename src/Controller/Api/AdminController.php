<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
class AdminController extends AbstractController
{
    #[Route('/stats', name: 'api_admin_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        return $this->json([
            'status'  => 'ok',
            'message' => 'Admin stats endpoint — implementation pending.',
        ]);
    }
}
