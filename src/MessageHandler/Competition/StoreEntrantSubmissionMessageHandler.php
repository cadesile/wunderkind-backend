<?php

declare(strict_types=1);

namespace App\MessageHandler\Competition;

use App\Entity\Competition\CompetitionEntrant;
use App\Message\Competition\StoreEntrantSubmissionMessage;
use App\Repository\Competition\CompetitionEntrantRepository;
use App\Service\Competition\CompetitionRegistrationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Writes the squad and submission rows for an entrant whose slot is already reserved.
 *
 * Unlike the warm handler next door, **failures here must not be swallowed.** A missing
 * response-cache document self-heals on the next read; a missing squad row does not — it is
 * the match engine's only input, so an entrant left without one would be drawn into a fixture
 * and resolve against an empty XI. Letting the exception out is what puts the envelope on the
 * `failed` transport, where it can be replayed with the payload intact.
 *
 * The entrant stays PENDING until the write lands, and `CompetitionDrawService` refuses to
 * draw a competition in that state, so the window between reserving and storing is visible to
 * the thing that would otherwise be harmed by it rather than merely hoped to be short.
 */
#[AsMessageHandler]
final class StoreEntrantSubmissionMessageHandler
{
    public function __construct(
        private readonly CompetitionEntrantRepository $entrantRepository,
        private readonly CompetitionRegistrationService $registrationService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(StoreEntrantSubmissionMessage $message): void
    {
        $entrant = $this->entrantRepository->find($message->entrantId);

        if (!$entrant instanceof CompetitionEntrant) {
            // The entrant is gone — a competition deleted in admin, say. Retrying cannot
            // bring it back, so this is unrecoverable rather than a transient failure, and
            // marking it so keeps a dead 1.4MB envelope from being retried three more times
            // before it lands on the failed transport anyway.
            throw new UnrecoverableMessageHandlingException(sprintf(
                'Entrant %s no longer exists; discarding its submission.',
                $message->entrantId,
            ));
        }

        if ($entrant->hasStoredSquad()) {
            // Already written — a duplicate delivery, or a resubmission that overtook this
            // message. The writes are upserts so replaying would be harmless, but a
            // resubmission is *newer* than this envelope and rewriting it here would quietly
            // roll the club's XI back to the one it registered with.
            $this->logger->info('competition.submission.skipped', [
                'entrantId' => $message->entrantId,
                'reason'    => 'squad already stored',
            ]);

            return;
        }

        $this->registrationService->storeSubmission($entrant, $message->envelope);

        $this->logger->info('competition.submission.stored', [
            'entrantId'     => $message->entrantId,
            'competitionId' => (string) $entrant->getActiveCompetition()->getId(),
        ]);
    }
}
