<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Agent;
use App\Entity\Investor;
use App\Entity\Scout;
use App\Entity\Sponsor;
use App\Enum\CompanySize;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-market-data',
    description: 'Generate dummy data for Agent, Scout, Investor, and Sponsor entities',
)]
class GenerateMarketDataCommand extends Command
{
    // ---------------------------------------------------------------------------
    // Agent data
    // ---------------------------------------------------------------------------
    private const AGENT_NAMES = [
        'Jorge Mendes', 'Jonathan Barnett', 'Pini Zahavi', 'Kia Joorabchian',
        'Fernando Felicevich', 'Volker Struth', 'Pere Guardiola', 'Giuliano Bertolucci',
        'Frederic Massara', 'Txiki Begiristain', 'Giovanni Branchini', 'Carmine Raiola',
        'Atta Aneke', 'Mehmet Yorukoglu', 'Bahri Yilmaz', 'Stefano Castagna',
        'Nasser Larguet', 'John Shier', 'David Manasseh', 'Rob Segal',
        'Nick Arcuri', 'Marc Roger', 'Jean-Michel Mettioui', 'Christophe Henrotay',
        'Saif Rubie', 'Sky Andrew', 'Les Ferdinand Agency', 'Global Sports Agency',
        'Stellar Group', 'CAA Sports', 'Wasserman Media', 'Octagon Sports',
        'Gestifute Partners', 'Base Soccer Agency', 'ICM Stellar Sports',
    ];

    // ---------------------------------------------------------------------------
    // Scout first / last names (combined on the fly)
    // ---------------------------------------------------------------------------
    private const SCOUT_FIRST_NAMES = [
        'Carlos', 'Luis', 'Miguel', 'Antonio', 'Jose', 'Marco', 'Giovanni',
        'Lars', 'Hans', 'Pierre', 'Jacques', 'John', 'David', 'Mohammed',
        'Alex', 'Rudi', 'Viktor', 'Stefan', 'Tomasz', 'Rafael', 'Andre',
        'Claudio', 'Fabio', 'Diego', 'Sergio', 'Ivan', 'Nikola', 'Leon',
    ];

    private const SCOUT_LAST_NAMES = [
        'Silva', 'Santos', 'Rodriguez', 'Garcia', 'Rossi', 'Romano',
        'Müller', 'Schmidt', 'Dubois', 'Martin', 'Smith', 'Johnson',
        'Ferreira', 'Oliveira', 'Pereira', 'Costa', 'Alves', 'Gomes',
        'Fischer', 'Weber', 'Meyer', 'Wagner', 'Hoffmann', 'Koch',
        'Bernard', 'Moreau', 'Laurent', 'Thomas', 'Robert', 'Richard',
        'Kowalski', 'Nowak', 'Wiśniewski', 'Wójcik', 'Kowalczyk',
    ];

    // ---------------------------------------------------------------------------
    // Investor / Sponsor companies
    // ---------------------------------------------------------------------------
    private const INVESTOR_COMPANIES = [
        'RedBird Capital Partners', 'Oaktree Capital Management', 'Elliott Management',
        'Silver Lake Partners', 'CVC Capital Partners', 'Clearlake Capital Group',
        'KKR & Co', 'Blackstone Group', 'Apollo Global Management', 'Bain Capital',
        'TPG Capital', 'Warburg Pincus', 'General Atlantic', 'Advent International',
        'Permira Advisers', 'BC Partners', 'PAI Partners', 'Cinven Partners',
        'EQT Partners', 'Ardian Investment', 'Bridgepoint Group', 'Carlyle Group',
        'Vista Equity Partners', 'Francisco Partners', 'Thoma Bravo',
        'Searchlight Capital', 'MSD Partners', 'GAMCO Investors',
    ];

    private const SPONSOR_COMPANIES = [
        // Sportswear
        'Nike', 'Adidas', 'Puma', 'Under Armour', 'New Balance',
        'Macron', 'Kappa', 'Umbro', 'Hummel', 'Castore',
        // Airlines
        'Emirates', 'Etihad Airways', 'Qatar Airways', 'Turkish Airlines', 'Fly Dubai',
        // Beverages
        'Coca-Cola', 'Pepsi', 'Red Bull', 'Monster Energy', 'Heineken',
        'Budweiser', 'Carlsberg', 'Gatorade', 'Lucozade', 'Tiger Beer',
        // Tech / Gaming
        'EA Sports', 'Konami', 'Samsung', 'Sony', 'LG Electronics',
        'Sorare', 'Crypto.com', 'Binance', 'Bitpanda', 'Socios',
        // Automotive
        'BMW', 'Audi', 'Mercedes-Benz', 'Volkswagen', 'Hyundai',
        'Kia Motors', 'Chevrolet', 'Jeep', 'Renault',
        // Finance
        'Visa', 'Mastercard', 'American Express', 'PayPal', 'Wise',
        // Luxury / Watches
        'Rolex', 'TAG Heuer', 'Hublot', 'Breitling',
        // Other
        'Lay\'s', 'Doritos', 'McDonald\'s', 'KFC', 'Deliveroo',
    ];

    // ---------------------------------------------------------------------------
    // Shared nationalities
    // ---------------------------------------------------------------------------
    private const NATIONALITIES = [
        'Brazilian', 'Argentine', 'Spanish', 'Portuguese', 'Italian',
        'German', 'French', 'English', 'Dutch', 'Belgian',
        'Croatian', 'Uruguayan', 'Mexican', 'Colombian', 'American',
        'Canadian', 'Japanese', 'South Korean', 'Saudi', 'Emirati',
        'Turkish', 'Swiss', 'Austrian', 'Danish', 'Swedish', 'Norwegian',
    ];

    // ---------------------------------------------------------------------------
    // Judgement attribute keys per entity type
    // ---------------------------------------------------------------------------
    private const AGENT_JUDGEMENT_KEYS   = ['potential', 'current', 'personality', 'technical', 'physical'];
    private const SCOUT_JUDGEMENT_KEYS   = ['potential', 'mental', 'technical', 'physical', 'youth', 'senior', 'personality'];
    private const SCOUT_SPECIALIZATIONS  = [
        ['potential', 'mental', 'technical'],
        ['youth', 'physical'],
        ['senior', 'technical'],
        ['potential', 'youth'],
        ['mental', 'personality'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agents',    'a', InputOption::VALUE_OPTIONAL, 'Number of agents to generate',    25)
            ->addOption('scouts',    's', InputOption::VALUE_OPTIONAL, 'Number of scouts to generate',    30)
            ->addOption('investors', 'i', InputOption::VALUE_OPTIONAL, 'Number of investors to generate', 20)
            ->addOption('sponsors',  'p', InputOption::VALUE_OPTIONAL, 'Number of sponsors to generate',  40)
            ->addOption('clear',     'c', InputOption::VALUE_NONE,     'Delete existing market data before generating')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Wunderkind Factory - Market Data Generator');

        $agentCount    = (int) $input->getOption('agents');
        $scoutCount    = (int) $input->getOption('scouts');
        $investorCount = (int) $input->getOption('investors');
        $sponsorCount  = (int) $input->getOption('sponsors');
        $clear         = (bool) $input->getOption('clear');

        try {
            if ($clear) {
                $this->clearExistingData($io);
            }

            $this->generateAgents($io, $agentCount);
            $this->generateScouts($io, $scoutCount);
            $this->generateInvestors($io, $investorCount);
            $this->generateSponsors($io, $sponsorCount);
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $io->success('Market data generated successfully!');
        $io->definitionList(
            ['Agents'    => $agentCount],
            ['Scouts'    => $scoutCount],
            ['Investors' => $investorCount],
            ['Sponsors'  => $sponsorCount],
        );

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------------------
    // Clear
    // ---------------------------------------------------------------------------

    private function clearExistingData(SymfonyStyle $io): void
    {
        $io->text('Clearing existing market data...');

        $this->em->createQuery('DELETE FROM App\Entity\Agent a WHERE a.isUniversal = true')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Scout')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Investor')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Sponsor')->execute();

        $io->text('Done. Generating fresh data.');
        $io->newLine();
    }

    // ---------------------------------------------------------------------------
    // Agents
    // ---------------------------------------------------------------------------

    private function generateAgents(SymfonyStyle $io, int $count): void
    {
        $io->text(sprintf('Generating %d Agents...', $count));
        $progressBar = $io->createProgressBar($count);
        $progressBar->start();

        $names = $this->pickUnique(self::AGENT_NAMES, $count);

        for ($i = 0; $i < $count; $i++) {
            $reputation = random_int(30, 95);
            $rating     = max(1, min(100, $reputation + random_int(-15, 15)));

            // Commission scales loosely with reputation
            $commissionMin  = (int) (500 + ($reputation / 100) * 1000); // 500–1500 basis points
            $commissionRate = number_format(random_int($commissionMin, $commissionMin + 500) / 100, 2);

            $age        = random_int(35, 65);
            $experience = max(1, $reputation - random_int(10, 20));
            $dob        = $this->dobFromAge($age);

            $agent = new Agent($names[$i]);
            $agent->setReputation($reputation);
            $agent->setRating($rating);
            $agent->setCommissionRate($commissionRate);
            $agent->setExperience($experience);
            $agent->setDob($dob);
            $agent->setNationality($this->pick(self::NATIONALITIES));
            $agent->setJudgements($this->buildJudgements(self::AGENT_JUDGEMENT_KEYS, 40, 95));

            $this->em->persist($agent);

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Agent::class);
            }

            $progressBar->advance();
        }

        $this->em->flush();
        $progressBar->finish();
        $io->newLine(2);
    }

    // ---------------------------------------------------------------------------
    // Scouts
    // ---------------------------------------------------------------------------

    private function generateScouts(SymfonyStyle $io, int $count): void
    {
        $io->text(sprintf('Generating %d Scouts...', $count));
        $progressBar = $io->createProgressBar($count);
        $progressBar->start();

        for ($i = 0; $i < $count; $i++) {
            $name = $this->pick(self::SCOUT_FIRST_NAMES) . ' ' . $this->pick(self::SCOUT_LAST_NAMES);
            $age  = random_int(30, 70);

            // Experience = roughly (age - 30) * 2 ± noise
            $experience = max(0, ($age - 30) * 2 + random_int(-5, 15));

            $scout = new Scout($name);
            $scout->setDob($this->dobFromAge($age));
            $scout->setNationality($this->pick(self::NATIONALITIES));
            $scout->setExperience($experience);
            $scout->setJudgements($this->buildScoutJudgements($experience));

            $this->em->persist($scout);

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Scout::class);
            }

            $progressBar->advance();
        }

        $this->em->flush();
        $progressBar->finish();
        $io->newLine(2);
    }

    // ---------------------------------------------------------------------------
    // Investors
    // ---------------------------------------------------------------------------

    private function generateInvestors(SymfonyStyle $io, int $count): void
    {
        $io->text(sprintf('Generating %d Investors...', $count));
        $progressBar = $io->createProgressBar($count);
        $progressBar->start();

        $companies = $this->pickUnique(self::INVESTOR_COMPANIES, $count);

        for ($i = 0; $i < $count; $i++) {
            $investor = new Investor($companies[$i]);
            $investor->setNationality($this->pick(self::NATIONALITIES));
            $investor->setSize($this->investorSize());
            $investor->setIsActive(random_int(1, 100) <= 85);

            $this->em->persist($investor);

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Investor::class);
            }

            $progressBar->advance();
        }

        $this->em->flush();
        $progressBar->finish();
        $io->newLine(2);
    }

    // ---------------------------------------------------------------------------
    // Sponsors
    // ---------------------------------------------------------------------------

    private function generateSponsors(SymfonyStyle $io, int $count): void
    {
        $io->text(sprintf('Generating %d Sponsors...', $count));
        $progressBar = $io->createProgressBar($count);
        $progressBar->start();

        $companies = $this->pickUnique(self::SPONSOR_COMPANIES, $count);

        for ($i = 0; $i < $count; $i++) {
            $sponsor = new Sponsor($companies[$i]);
            $sponsor->setNationality($this->pick(self::NATIONALITIES));
            $sponsor->setSize($this->sponsorSize());
            $sponsor->setIsActive(random_int(1, 100) <= 90);

            $this->em->persist($sponsor);

            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Sponsor::class);
            }

            $progressBar->advance();
        }

        $this->em->flush();
        $progressBar->finish();
        $io->newLine(2);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /** Pick a random element from an array. */
    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /**
     * Return up to $n unique items from $pool.
     * If $n > pool size, items are reused with a suffix to avoid constraint violations.
     */
    private function pickUnique(array $pool, int $n): array
    {
        shuffle($pool);

        if ($n <= count($pool)) {
            return array_slice($pool, 0, $n);
        }

        // Cycle through pool and append index suffix for overflow
        $result = [];
        for ($i = 0; $i < $n; $i++) {
            $base     = $pool[$i % count($pool)];
            $result[] = $i < count($pool) ? $base : $base . ' ' . ($i + 1);
        }

        return $result;
    }

    /** Build a judgements array with uniform random values. */
    private function buildJudgements(array $keys, int $min, int $max): array
    {
        $judgements = [];
        foreach ($keys as $key) {
            $judgements[$key] = random_int($min, $max);
        }
        return $judgements;
    }

    /**
     * Build scout judgements with one specialization cluster boosted.
     * Specialised keys get +20–35; others get base 30–70; experience adds up to +20.
     */
    private function buildScoutJudgements(int $experience): array
    {
        $specialization = self::SCOUT_SPECIALIZATIONS[array_rand(self::SCOUT_SPECIALIZATIONS)];
        $expBonus       = (int) min(20, $experience / 2);
        $judgements     = [];

        foreach (self::SCOUT_JUDGEMENT_KEYS as $key) {
            $base = in_array($key, $specialization, true)
                ? random_int(60, 85) + random_int(0, 15)   // 60–100 specialized
                : random_int(30, 70);                        // 30–70 baseline

            $judgements[$key] = min(100, $base + $expBonus);
        }

        return $judgements;
    }

    /** Build a \DateTimeImmutable date-of-birth from an age. */
    private function dobFromAge(int $age): \DateTimeImmutable
    {
        $year  = (int) date('Y') - $age;
        $month = random_int(1, 12);
        $day   = random_int(1, 28); // Stays valid for all months

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /**
     * Investor size distribution (mapped from 5-tier to 3-tier):
     *   40% SMALL  (was Startup 15% + Small 25%)
     *   30% MEDIUM
     *   30% LARGE  (was Large 20% + Enterprise 10%)
     */
    private function investorSize(): CompanySize
    {
        $roll = random_int(1, 100);
        return match (true) {
            $roll <= 40 => CompanySize::SMALL,
            $roll <= 70 => CompanySize::MEDIUM,
            default     => CompanySize::LARGE,
        };
    }

    /**
     * Sponsor size distribution (skewed larger):
     *   20% SMALL  (was Startup 5% + Small 15%)
     *   25% MEDIUM
     *   55% LARGE  (was Large 30% + Enterprise 25%)
     */
    private function sponsorSize(): CompanySize
    {
        $roll = random_int(1, 100);
        return match (true) {
            $roll <= 20 => CompanySize::SMALL,
            $roll <= 45 => CompanySize::MEDIUM,
            default     => CompanySize::LARGE,
        };
    }
}
