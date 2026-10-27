<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Club;
use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionEntrant;
use App\Entity\Competition\CompetitionEntrantSquad;
use App\Service\Competition\CompetitionRegistrationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds entrants the way CompetitionRegistrationService::register() does.
 *
 * Tests used to construct `new CompetitionEntrant($c, $club, $seed, $envelope)` with the
 * whole `{club, players, staff}` envelope. Since the split, that fourth argument is the
 * bounded club *display block* — so passing an envelope still type-checks and still
 * persists, but stores a shape production can never produce: `clubName`, `ovr` and
 * `playingStyle` end up nested under a stray `club` key where no reader looks.
 *
 * That fails silently. It surfaced as two admin-page assertions quietly losing a playing
 * style and a player name, while every test that happened not to assert on club display
 * data kept passing against fixtures that could not exist. Routing fixtures through the same
 * normaliser and squad write as the real registration path is what makes those tests mean
 * something again.
 */
trait EntrantFixtureTrait
{
    /**
     * Reads back the `{club, players, staff}` envelope for an entrant, for assertions that
     * care about the submitted squad rather than the display block.
     *
     * Deliberately explicit about loading a second row: the production read paths must never
     * do this, and a test helper that hid it would make the forbidden thing look free.
     *
     * @return array<string, mixed>
     */
    private function entrantEnvelope(EntityManagerInterface $em, CompetitionEntrant $entrant): array
    {
        return CompetitionRegistrationService::snapshotEnvelopeFrom(
            $entrant,
            $em->getRepository(CompetitionEntrantSquad::class)->findOneBy(['entrant' => $entrant]),
        );
    }

    /**
     * @param array<string, mixed> $envelope `{club, players, staff}`, exactly as a client POSTs it.
     */
    private function makeEntrantFromEnvelope(
        EntityManagerInterface $em,
        ActiveCompetition $competition,
        Club $club,
        int $seed,
        array $envelope,
    ): CompetitionEntrant {
        $clubData = is_array($envelope['club'] ?? null) ? $envelope['club'] : [];
        $players  = array_values(array_filter(is_array($envelope['players'] ?? null) ? $envelope['players'] : [], 'is_array'));
        $staff    = array_values(array_filter(is_array($envelope['staff'] ?? null) ? $envelope['staff'] : [], 'is_array'));

        $ovr = is_numeric($clubData['ovr'] ?? null)
            ? (int) round((float) $clubData['ovr'])
            : CompetitionRegistrationService::computeStartingXiOvr($envelope);

        $entrant = new CompetitionEntrant(
            $competition,
            $club,
            $seed,
            CompetitionRegistrationService::clubDisplayBlock($clubData, $club, (string) $club->getId(), $ovr),
            is_string($envelope['clientSnapshotAt'] ?? null) ? $envelope['clientSnapshotAt'] : null,
            count($players),
        );
        $em->persist($entrant);

        // Persisted even when empty. The engine treats a missing squad row as an empty side,
        // so omitting it would make "registered with no players" and "squad pruned after the
        // competition finished" indistinguishable in a test.
        $em->persist(new CompetitionEntrantSquad(
            $entrant,
            $players,
            $staff,
            CompetitionRegistrationService::clubEngineBlock($clubData),
        ));

        return $entrant;
    }
}
