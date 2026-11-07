<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Location\CountryProfile;
use App\Enum\Location\CountryProfileStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Promoting a country to LIVE, and refusing to when it is not ready.
 *
 * EXTRACTED RATHER THAN COPIED. CountryProfileCrudController has enforced this rule since the
 * readiness service existed — refuse LIVE on an unready country, stamp the timestamps when it
 * passes — but it did so in a private method, so the locations dashboard's GO LIVE button
 * could not reach it. Two copies of "what makes a country ready to open" is exactly the kind
 * of thing that drifts, and the half that drifts is the one nobody is looking at.
 *
 * CountryReadinessService STAYS READ-ONLY. It answers whether a country is ready and has no
 * entity manager; the writing half lives here.
 *
 * NOT A UI CONCERN. The button being enabled is not the check — an operator with a stale page
 * can click a GO LIVE that was valid when it rendered, so goLive() re-evaluates before it
 * writes anything.
 */
final class CountryGoLiveService
{
    public function __construct(
        private readonly CountryReadinessService $readinessService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Why a country cannot go live yet, as one line.
     *
     * Moved verbatim out of CountryProfileCrudController::guardLiveTransition() so both
     * callers refuse in the same words.
     *
     * @param array{checks: list<array{key: string, ok: bool, detail: string}>, ...} $verdict
     */
    public function blockingMessage(array $verdict): string
    {
        $detail = [];

        foreach ($verdict['checks'] as $check) {
            if (!$check['ok']) {
                $detail[] = "{$check['key']}: {$check['detail']}";
            }
        }

        return implode('; ', $detail);
    }

    /**
     * Stamp the readiness timestamps, without flushing.
     *
     * `?? $now` on both: these record when the country FIRST reached each state, so a second
     * promotion — after a spell back in DRAFT, say — must not rewrite the original date.
     * No flush, because EasyAdmin owns it on the CRUD path.
     */
    public function stampLive(CountryProfile $profile, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable();

        $profile->setTierStructureReadyAt($profile->getTierStructureReadyAt() ?? $now);
        $profile->setLocationsLiveAt($profile->getLocationsLiveAt() ?? $now);
    }

    /**
     * Evaluate, then promote and stamp — or refuse.
     *
     * @return array{ok: bool, message: string, verdict: array<string, mixed>}
     */
    public function goLive(CountryProfile $profile): array
    {
        if ($profile->getStatus() === CountryProfileStatus::LIVE) {
            return [
                'ok'      => true,
                'message' => sprintf('%s is already live.', $profile->getCode()),
                'verdict' => $this->readinessService->evaluate($profile->getCode()),
            ];
        }

        $verdict = $this->readinessService->evaluate($profile->getCode());

        if (!$verdict['ready']) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    '%s cannot go live yet — %s.',
                    $profile->getCode(),
                    $this->blockingMessage($verdict),
                ),
                'verdict' => $verdict,
            ];
        }

        $profile->setStatus(CountryProfileStatus::LIVE);
        $this->stampLive($profile);
        $this->em->flush();

        return [
            'ok'      => true,
            'message' => sprintf('%s is live.', $profile->getCode()),
            'verdict' => $verdict,
        ];
    }
}
