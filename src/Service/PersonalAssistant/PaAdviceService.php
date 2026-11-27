<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

use App\Entity\User;
use App\Repository\GameConfigRepository;
use App\Repository\PersonalAssistantRepository;
use Psr\Log\LoggerInterface;

/**
 * Personal Assistant advice: the device's findings, put in the chosen PA's voice by the AI.
 *
 * DISPLAY ONLY. The answer is a sentence or three of text; nothing here reads or writes any
 * club, ledger or money state (finance rule: all financial calculation happens on the device).
 *
 * EVERY FAILURE IS AN ANSWER. The app always shows its own template advice first and only swaps
 * in this text on `ok`, so anything that goes wrong becomes `unavailable` with a reason, never
 * an exception the controller has to turn into a 500.
 *
 * Order matters for the cap: only a call that is actually about to reach the provider spends
 * one. A bad request, an unknown PA, an unsupported locale or an unconfigured provider costs
 * the owner nothing.
 *
 * CACHE HITS ARE FREE (2026-11-27). An identical request (PaAdviceCache) returns the stored
 * reply before the cap is consumed, so it never counts toward `paAdviceDailyCallLimit` and is
 * served even to an owner who has hit the cap. A limit of 0 still turns AI wording off
 * entirely, cached replies included.
 */
final class PaAdviceService
{
    public function __construct(
        private readonly PersonalAssistantRepository $personalAssistants,
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly PaAdvicePromptBuilder $promptBuilder,
        private readonly PaAdviceGeneratorInterface $generator,
        private readonly PaAdviceRateLimiter $rateLimiter,
        private readonly PaAdviceCache $replyCache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, mixed> the response body
     *
     * @throws PaAdviceNotFoundException when the PA is unknown or inactive
     */
    public function advise(User $user, PaAdviceRequest $request): array
    {
        $pa = $this->personalAssistants->findActiveBySlug($request->personalityId);
        if ($pa === null) {
            throw new PaAdviceNotFoundException($request->personalityId);
        }

        if (!\in_array($request->locale, PaAdviceRequestParser::SUPPORTED_LOCALES, true)) {
            return self::unavailable('unsupported_locale');
        }

        if (!$this->generator->isConfigured()) {
            return self::unavailable('not_configured');
        }

        $config = $this->gameConfigRepository->getConfig();
        $limit  = $config->getPaAdviceDailyCallLimit();
        if ($limit <= 0) {
            return self::unavailable('rate_limited');
        }

        $system   = $this->promptBuilder->system($pa);
        $cacheKey = $this->replyCache->key($system, $request);
        $cached   = $this->replyCache->get($cacheKey);
        if ($cached !== null) {
            return ['status' => 'ok', 'personalityId' => $pa->getSlug(), 'message' => $cached];
        }

        $retryAfter = $this->rateLimiter->consume((string) $user->getId(), $limit);
        if ($retryAfter !== null) {
            return self::unavailable('rate_limited') + ['retryAfterSeconds' => $retryAfter];
        }

        try {
            $message = $this->generator->generate(
                $system,
                $this->promptBuilder->user($request),
                $this->promptBuilder->schema(),
                $config->getPaAdviceTimeoutSeconds(),
            );
        } catch (PaAdviceUnavailableException $e) {
            $this->logger->warning('Personal Assistant advice could not be worded', [
                'reason'      => $e->reason,
                'personality' => $pa->getSlug(),
                'topic'       => $request->topic,
                'error'       => $e->getMessage(),
            ]);

            return self::unavailable($e->reason);
        }

        $message = trim((string) preg_replace('/\s+/u', ' ', $message));
        if ($message === '' || mb_strlen($message) > PaAdvicePromptBuilder::MAX_MESSAGE_CHARS) {
            $this->logger->warning('Personal Assistant advice reply was empty or too long', [
                'personality' => $pa->getSlug(),
                'length'      => mb_strlen($message),
            ]);

            return self::unavailable(PaAdviceUnavailableException::PROVIDER_ERROR);
        }

        $this->replyCache->set($cacheKey, $message);

        return ['status' => 'ok', 'personalityId' => $pa->getSlug(), 'message' => $message];
    }

    /** @return array{status: string, reason: string} */
    private static function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason];
    }
}
