<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\User;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class ClubInitializationService
{

    /** Maps the frontend country code to the nationality string used in the player pool. */
    private const COUNTRY_TO_NATIONALITY = [
        'EN' => 'English',
        'IT' => 'Italian',
        'DE' => 'German',
        'ES' => 'Spanish',
        'BR' => 'Brazilian',
        'AR' => 'Argentine',
        'NL' => 'Dutch',
        'FR' => 'French',
        'PT' => 'Portuguese',
        'NG' => 'Nigerian',
        'GH' => 'Ghanaian',
        'JP' => 'Japanese',
        'KR' => 'South Korean',
        'SE' => 'Swedish',
        'DK' => 'Danish',
        'IE' => 'Irish',
        'CI' => 'Ivorian',
        'SN' => 'Senegalese',
        'CN' => 'Chinese',
    ];

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
    ) {}

    /**
     * Enrich an existing club with starter entities from the market pool.
     *
     * Pulls entities from the pool. Throws \OverflowException if any pool is
     * insufficient — run app:generate-market-data to replenish before retrying.
     *
     * @throws \RuntimeException   if the user already has an initialized club (has players)
     * @throws \OverflowException  if any entity pool has too few entries
     */
    public function initializeClub(User $user, string $clubName, ?string $country = null, ?array $managerProfile = null): Club
    {
        if ($user->getClub() !== null) {
            throw new \RuntimeException('Club is already initialized.');
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
        if ($managerProfile !== null) {
            $club->setManagerProfile($managerProfile);
        }

        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    public static function countryToNationality(string $countryCode): ?string
    {
        return self::COUNTRY_TO_NATIONALITY[$countryCode] ?? null;
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
        $words = preg_split('/[\s\-]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        $words = array_values(array_filter($words, fn($w) => !in_array(strtolower($w), $strip)));

        if (empty($words)) {
            $words = preg_split('/[\s\-]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        }

        $abbr = match (count($words)) {
            1       => substr($words[0], 0, 5),
            2       => substr($words[0], 0, 3) . substr($words[1], 0, 2),
            default => implode('', array_map(fn($w) => $w[0], $words)),
        };

        return strtoupper(substr($abbr, 0, 5));
    }

    private function generatePaName(): string
    {
        $first = self::PA_FIRST_NAMES[array_rand(self::PA_FIRST_NAMES)];
        $last  = self::PA_LAST_NAMES[array_rand(self::PA_LAST_NAMES)];
        return "$first $last";
    }
}
