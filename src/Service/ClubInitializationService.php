<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Country;
use App\Entity\Club;
use App\Entity\Location\Location;
use App\Entity\Location\PlotClaim;
use App\Entity\User;
use App\Exception\ClubNameTakenException;
use App\Exception\InvalidCountryCodeException;
use App\Exception\Location\PlotUnavailableException;
use App\Repository\ClubRepository;
use App\Repository\Location\LocationRepository;
use App\Repository\Location\PlotClaimRepository;
use App\Repository\NpcClubRepository;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class ClubInitializationService
{

    private const PA_FIRST_NAMES = [
        'Marcus', 'Daniel', 'James', 'Ryan', 'Michael',
        'Jordan', 'Liam', 'Ethan', 'Nathan', 'Kyle',
        'Sophia', 'Emma', 'Olivia', 'Ava', 'Isabella',
        'Mia', 'Charlotte', 'Amelia', 'Harper', 'Evelyn',
    ];

    private const PA_LAST_NAMES = [
        'Richards', 'Thompson', 'Johnson', 'Clarke', 'Edwards',
        'Wilson', 'Hughes', 'Davies', 'Morris', 'Bennett',
        'Campbell', 'Foster', 'Patel', 'Khan', 'Okafor',
        'Mensah', 'Asante', 'Diallo', 'Nkosi', 'Osei',
    ];

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly StarterConfigRepository $starterConfigRepository,
        private readonly NpcClubRepository       $npcClubRepository,
        private readonly ClubRepository          $clubRepository,
        private readonly LocationRepository      $locationRepository,
        private readonly PlotClaimRepository     $plotClaimRepository,
    ) {}

    /**
     * Enrich an existing club with starter entities from the market pool.
     *
     * Pulls entities from the pool. Throws \OverflowException if any pool is
     * insufficient — run app:generate-market-data to replenish before retrying.
     *
     * FOUNDING ON A PLOT is opt-in via $locationId. Supplied, the caller must already hold an
     * active, club-less claim at that location — this method does not buy land, it only attaches
     * a club to land already bought, which is what keeps the money path in one place
     * (PlotClaimService) and out of club creation. Omitted, behaviour is byte-identical to
     * before and home_location_id stays NULL, so every existing client keeps working and the
     * requirement can be tightened to mandatory in one line once existing clubs are dealt with.
     *
     * NOTE WHAT IS NOT CHARGED HERE. club.balance is still set from StarterConfig and the land
     * is NOT debited from it: land comes out of the account's UserLedger credit at claim time.
     * club.balance is device-authoritative (SyncService assigns it absolutely), so a deduction
     * here would survive until the next sync and then silently vanish.
     *
     * @throws \OverflowException         if any entity pool has too few entries
     * @throws ClubNameTakenException     if the name is already taken, by an NPC club in the
     *                                    same country or by another club at this location
     * @throws PlotUnavailableException   if $locationId is not a location the caller owns, or
     *                                    their claim there already has a club
     */
    public function initializeClub(
        User $user,
        string $clubName,
        ?string $country = null,
        ?string $locationId = null,
    ): Club {
        // Normalize + validate before anything else: Club::$country must always be a real,
        // canonically-cased Country code from this point on. A mis-cased or garbled code (the
        // client's own country-picker, the X-Club-Id debug flows, anything) used to get
        // persisted verbatim, which silently broke both the duplicate-name check below (a
        // raw-cased country never matched NpcClub rows, which are always canonical) and
        // StarterPackService's nationality resolution later — see
        // InvalidCountryCodeException's own docblock.
        if ($country !== null && trim($country) !== '') {
            $country = strtoupper(trim($country));
            if (Country::tryFrom($country) === null) {
                throw new InvalidCountryCodeException($country);
            }
        } else {
            $country = null;
        }

        // The user picks their name from the same place/suffix pools the NPC
        // generator uses, so without this the pyramid can contain two clubs
        // with an identical name.
        if ($country !== null && $this->npcClubRepository->clubNameExists($clubName, $country)) {
            throw new ClubNameTakenException($clubName);
        }

        // Resolved BEFORE the club is persisted, so a bad location id leaves no orphan club
        // behind. Both checks below throw rather than returning null.
        $claim    = $locationId !== null && trim($locationId) !== ''
            ? $this->resolveClaimForFounding($user, trim($locationId))
            : null;
        $location = $claim?->getLocation();

        // The second half of the uniqueness rule, alongside the NPC check above. Same exception
        // and therefore the same existing 422 — no new error code for the client to learn.
        if ($location !== null && $this->clubRepository->nameExistsAtLocation($clubName, $location)) {
            throw new ClubNameTakenException($clubName);
        }

        $config  = $this->starterConfigRepository->getConfig();
        $club = new Club($clubName, $user);
        $club->setBalance($config->getStartingBalance());
        $club->setAbbreviation(self::generateAbbreviation($clubName));
        $club->setPaName($this->generatePaName());
        $club->setManagerTemperament(rand(40, 60));
        $club->setManagerDiscipline(rand(40, 60));
        $club->setManagerAmbition(rand(40, 60));

        if ($country !== null) {
            $club->setCountry($country);
        }

        if ($location !== null) {
            $club->setHomeLocation($location);

            // A location belongs to exactly one country, so founding on a plot settles the
            // club's country too. Only filled in where the client did not say — an explicit
            // country is the caller's business and is validated above.
            if ($club->getCountry() === null) {
                $club->setCountry($location->getCountryCode());
            }
        }

        $this->em->persist($club);

        // ONE FLUSH for the club and the claim's new club link. Both sides of the relationship
        // land together or neither does — a club with a home location whose claim does not point
        // back at it would read as owned by nobody, and uq_plot_claim_active_plot would then
        // block anyone from buying it again.
        if ($claim !== null) {
            $claim->attachClub($club);
        }

        $this->em->flush();

        return $club;
    }

    /**
     * The caller's active, club-less claim at this location.
     *
     * @throws PlotUnavailableException if there is no such claim, or it already has a club
     */
    private function resolveClaimForFounding(User $user, string $locationId): PlotClaim
    {
        $location = $this->locationRepository->find($locationId);

        if ($location === null) {
            // Same answer as "someone else owns it" — see locationNotOwned()'s docblock on why
            // this deliberately does not distinguish the cases.
            throw PlotUnavailableException::locationNotOwned();
        }

        foreach ($this->plotClaimRepository->findActiveForUser($user) as $claim) {
            if ((string) $claim->getLocation()->getId() !== (string) $location->getId()) {
                continue;
            }

            $existing = $claim->getClub();

            if ($existing !== null) {
                throw PlotUnavailableException::claimAlreadyHasClub($existing->getName());
            }

            return $claim;
        }

        throw PlotUnavailableException::locationNotOwned();
    }

    public static function countryToNationality(string $countryCode): ?string
    {
        return Country::tryFrom($countryCode)?->nationality();
    }

    public function getStarterBundle(): array
    {
        $config = $this->starterConfigRepository->getConfig();

        return [
            'startingBalance' => $config->getStartingBalance(),
            'players'         => $config->getStarterPlayerCount(),
            'coaches'         => $config->getStarterCoachCount(),
            'scouts'          => $config->getStarterScoutCount(),
        ];
    }

    /**
     * Derives a short abbreviation (up to 5 chars, uppercase) from a club name.
     *
     * Rules:
     *   - Strips non-distinctive football prefixes (FC, AFC, SC, CF, AC, BK, THE).
     *   - Single word  → first 3–5 chars.
     *   - Two words    → first 3 chars of word 1 + first 2 chars of word 2.
     *   - Three+ words → first letter of each word, up to 5.
     *
     * Examples: "Manchester City" → "MANCI", "Real Madrid" → "REAMA",
     *           "FC Barcelona" → "BARCE", "Paris Saint-Germain" → "PSG".
     */
    public static function generateAbbreviation(string $name): string
    {
        $strip = ['fc', 'afc', 'sc', 'cf', 'ac', 'bk', 'the'];
        $words = preg_split('/[\s\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        $words = array_values(array_filter($words, fn($w) => !in_array(mb_strtolower($w), $strip)));

        if (empty($words)) {
            $words = preg_split('/[\s\-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        }

        $abbr = match (count($words)) {
            1       => mb_substr($words[0], 0, 5),
            2       => mb_substr($words[0], 0, 3) . mb_substr($words[1], 0, 2),
            default => implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)),
        };

        return mb_strtoupper(mb_substr($abbr, 0, 5));
    }

    private function generatePaName(): string
    {
        $first = self::PA_FIRST_NAMES[array_rand(self::PA_FIRST_NAMES)];
        $last  = self::PA_LAST_NAMES[array_rand(self::PA_LAST_NAMES)];
        return "$first $last";
    }
}
