<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\PersonalAssistant\PaAdviceNotFoundException;
use App\Service\PersonalAssistant\PaAdviceRequestParser;
use App\Service\PersonalAssistant\PaAdviceService;
use App\Service\PersonalAssistant\PaAdviceValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `POST /api/pa/advice` — the owner's Personal Assistant puts the device's findings in words.
 *
 * OWNER-SCOPED, NOT CLUB-SCOPED: no X-Club-Id, no ClubResolver. The PA belongs to the account.
 * IS_AUTHENTICATED_FULLY rather than ROLE_CLUB for the reason security.yaml gives for plots.
 *
 * Responses (docs/api/personal-assistant.md):
 *   200 {"status":"ok","personalityId":"pa-marcus","message":"..."}
 *   200 {"status":"unavailable","reason":"rate_limited|provider_error|timeout|not_configured|unsupported_locale","retryAfterSeconds"?:N}
 *   404 {"error":"unknown_personality"}   unknown or inactive PA
 *   413 {"error":"payload_too_large"}
 *   422 {"error":"invalid_topic|invalid_personality|invalid_locale|invalid_json"}
 * `unavailable` is a 200, rate-limited included: it is an expected state the app renders, not
 * a failure, and the app keeps its template advice either way.
 */
#[Route('/api/pa')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PersonalAssistantController extends AbstractController
{
    public function __construct(
        private readonly PaAdviceRequestParser $parser,
        private readonly PaAdviceService $adviceService,
    ) {}

    #[Route('/advice', name: 'api_pa_advice', methods: ['POST'])]
    public function advice(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $adviceRequest = $this->parser->parse($request->getContent());
        } catch (PaAdviceValidationException $e) {
            $status = $e->errorCode === 'payload_too_large' ? Response::HTTP_REQUEST_ENTITY_TOO_LARGE : Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->json(['error' => $e->errorCode, 'message' => $e->getMessage()], $status);
        }

        try {
            return $this->json($this->adviceService->advise($user, $adviceRequest));
        } catch (PaAdviceNotFoundException $e) {
            return $this->json(['error' => 'unknown_personality', 'message' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }
}
