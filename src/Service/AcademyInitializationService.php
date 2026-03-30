<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Academy;
use App\Entity\User;
use App\Repository\StarterConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class AcademyInitializationService
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
     * Enrich an existing academy with starter entities from the market pool.
     *
     * Pulls entities from the pool. Throws \OverflowException if any pool is
     * insufficient — run app:generate-market-data to replenish before retrying.
     *
     * @throws \RuntimeException   if the user already has an initialized academy (has players)
     * @throws \OverflowException  if any entity pool has too few entries
     */
    public function initializeAcademy(User $user, string $academyName, ?string $country = null, ?array $managerProfile = null): Academy
    {
        if ($user->getAcademy() !== null) {
            throw new \RuntimeException('Academy is already initialized.');
        }

        $config  = $this->starterConfigRepository->getConfig();
        $academy = new Academy($academyName, $user);
        $academy->setBalance($config->getStartingBalance());
        $academy->setPaName($this->generatePaName());
        $academy->setManagerTemperament(rand(40, 60));
        $academy->setManagerDiscipline(rand(40, 60));
        $academy->setManagerAmbition(rand(40, 60));

        if ($country !== null) {
            $academy->setCountry($country);
        }
        if ($managerProfile !== null) {
            $academy->setManagerProfile($managerProfile);
        }

        $this->em->persist($academy);
        $this->em->flush();

        return $academy;
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

    private function generatePaName(): string
    {
        $first = self::PA_FIRST_NAMES[array_rand(self::PA_FIRST_NAMES)];
        $last  = self::PA_LAST_NAMES[array_rand(self::PA_LAST_NAMES)];
        return "$first $last";
    }
}
