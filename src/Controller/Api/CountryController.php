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
            // ADDITIVE, and the point of adding them: `code` is this game's own country code
            // and for five cases it is NOT an ISO 3166-1 alpha-2 code — EN (England has none),
            // SC and NI (which collide with Seychelles and Nicaragua), WA, and XK. A client
            // that needs a real ISO code for a third-party lookup now reads isoAlpha2 instead
            // of deriving one from `code` and getting the wrong country. null for Kosovo,
            // which genuinely has none.
            //
            // `code` keeps its existing meaning and value, so nothing that reads it changes.
            // The client's own countryCodeToFlag() 'EN' special case stays correct, as
            // docs/api/countries.md has always promised.
            'isoAlpha2' => $c->isoAlpha2(),
            'isIsoCode' => $c->isIsoCode(),
        ], Country::cases());

        $response = new JsonResponse($countries);
        $response->setMaxAge(3600);
        $response->setPublic();

        return $response;
    }
}
