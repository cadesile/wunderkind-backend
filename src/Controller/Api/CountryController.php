<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\Country;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, unauthenticated — the canonical nationality/country/code mapping, so clients
 * never need their own copy of this list. One row per App\Enum\Country case; static data
 * (changes only on deploy), so it's cacheable.
 */
#[Route('/api/countries', name: 'api_countries', methods: ['GET'])]
class CountryController extends AbstractController
{
    public function __invoke(): JsonResponse
    {
        $countries = array_map(static fn (Country $c) => [
            'name'    => $c->nationality(),
            'country' => $c->label(),
            'code'    => $c->value,
        ], Country::cases());

        $response = new JsonResponse($countries);
        $response->setMaxAge(3600);
        $response->setPublic();

        return $response;
    }
}
