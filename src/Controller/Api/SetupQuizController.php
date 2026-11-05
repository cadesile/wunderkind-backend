<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Setup\SetupQuizQuestion;
use App\Entity\User;
use App\Exception\Setup\SetupActionException;
use App\Repository\Setup\SetupQuizQuestionRepository;
use App\Service\Setup\SetupQuizService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The onboarding quiz, and the credit answering it pays.
 *
 * NO CLUB, AND THAT IS THE POINT. The bonus multiplies the ACCOUNT's starting grant, so the
 * quiz can be taken before any club exists — which is where it sits in the setup sequence. The
 * route lives outside `/api/club/` for that reason, under the `^/api` catch-all's
 * IS_AUTHENTICATED_FULLY.
 *
 * THE CORRECT ANSWER NEVER LEAVES THE SERVER. Questions are serialised through
 * SetupQuizQuestion::toClientArray(), which omits `correctIndex` — a single method rather than
 * per-action array literals precisely so no future action can forget.
 *
 * ONE ATTEMPT PER ACCOUNT, EVER, and not on a trusted timer: a question already answered is
 * `409 already_answered`, enforced by a unique index rather than a check that could be raced.
 * See SetupQuizService.
 */
#[Route('/api/setup-quiz')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class SetupQuizController extends AbstractController
{
    public function __construct(
        private readonly SetupQuizService            $quizService,
        private readonly SetupQuizQuestionRepository $questionRepository,
    ) {}

    /**
     * The quiz, with this account's progress and what the bonus is worth so far.
     *
     * An empty `questions` array is a valid answer, not an error: an operator who has published
     * no questions has no quiz, and the client should skip the step rather than show a dead
     * screen. `bonus.pence` before completion is what the answers SO FAR are worth — never a
     * projection of a perfect score, which would show a number the player may not earn.
     */
    #[Route('', name: 'api_setup_quiz', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $response = $this->json($this->quizService->quizFor($this->currentUser()));
        // Per-account progress. Never shared-cacheable.
        $response->setPrivate();
        $response->setMaxAge(0);

        return $response;
    }

    /**
     * Answer one question. Body: `{"answer": <option index>}`.
     *
     * The bonus is written when the LAST question is answered, as part of this call — not on a
     * separate finish call, which a client killed at the wrong moment would never make.
     */
    #[Route('/{id}/answer', name: 'api_setup_quiz_answer', methods: ['POST'])]
    public function answer(string $id, Request $request): JsonResponse
    {
        $question = $this->questionRepository->find($id);

        if (!$question instanceof SetupQuizQuestion) {
            return $this->json(['error' => 'question_not_found'], Response::HTTP_NOT_FOUND);
        }

        $body   = json_decode((string) $request->getContent(), true);
        $chosen = is_array($body) ? ($body['answer'] ?? null) : null;

        if (!is_int($chosen) && !(is_string($chosen) && ctype_digit($chosen))) {
            return $this->json(
                [
                    'error'   => 'answer_required',
                    'message' => 'Send the chosen option as {"answer": <index into options>}.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $result = $this->quizService->answer($this->currentUser(), $question, (int) $chosen);
        } catch (SetupActionException $e) {
            $status = match ($e->reason) {
                SetupActionException::ALREADY_ANSWERED,
                SetupActionException::QUIZ_UNAVAILABLE => Response::HTTP_CONFLICT,
                default                                => Response::HTTP_BAD_REQUEST,
            };

            return $this->json(
                ['error' => $e->reason, 'message' => $e->getMessage()] + $e->context,
                $status,
            );
        }

        return $this->json($result);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
