<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Agent;
use App\Entity\Player;
use App\Enum\PlayerPosition;
use App\Enum\PlayerStatus;
use App\Enum\RecruitmentSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the scout prospect pool — players with RecruitmentSource::SCOUTING_NETWORK
 * and no academy assignment. These are surfaced exclusively by the in-game scouting
 * system and must never appear in the open market or new-academy starter bundles.
 *
 * Usage:
 *   php bin/console app:seed-prospect-pool            # 200 prospects (default)
 *   php bin/console app:seed-prospect-pool -n 500     # custom count
 *   php bin/console app:seed-prospect-pool --clear    # wipe existing prospects first
 */
#[AsCommand(
    name: 'app:seed-prospect-pool',
    description: 'Seed the scout prospect pool (SCOUTING_NETWORK players with no academy)',
)]
class SeedProspectPoolCommand extends Command
{
    // ---------------------------------------------------------------------------
    // Name pools (distinct from market players to feel like different cohorts)
    // ---------------------------------------------------------------------------

    private const FIRST_NAMES = [
        // African
        'Amara', 'Boubacar', 'Cheick', 'Demba', 'Elijah', 'Fousseni', 'Gaoussou',
        'Hamidou', 'Issouf', 'Joachim', 'Kalidou', 'Lamine', 'Mamadou', 'Naby',
        'Ousmane', 'Papa', 'Quincy', 'Romaric', 'Seydou', 'Tidiane',
        // South American
        'Aldair', 'Benitez', 'Caio', 'Danilo', 'Esteban', 'Franco', 'Giovani',
        'Heitor', 'Ignacio', 'Joaquin', 'Kevin', 'Lorenzo', 'Matias', 'Nicolás',
        'Olavo', 'Pablo', 'Raul', 'Santiago', 'Tomas', 'Valentín',
        // European
        'Alessio', 'Baptiste', 'Cedric', 'Dylan', 'Edin', 'Florian', 'Gregor',
        'Henrik', 'Igor', 'Jakub', 'Kenan', 'Levin', 'Malte', 'Nico',
        'Oskar', 'Patrik', 'Quentin', 'Rasmus', 'Sander', 'Tobias',
        // Asian / Other
        'Daichi', 'Eiji', 'Firas', 'Hwang', 'Issa', 'Jin', 'Kaoru',
        'Lior', 'Mehmet', 'Naim', 'Omar', 'Phuong', 'Rashid', 'Sami',
    ];

    private const LAST_NAMES = [
        'Abdi', 'Baldé', 'Camara', 'Diarra', 'Eto', 'Fofana', 'Gueye',
        'Haidara', 'Iheanacho', 'Jallow', 'Keïta', 'Laryea', 'Mendy', 'Ndiaye',
        'Onana', 'Pépé', 'Quaison', 'Sané', 'Traoré', 'Uche',
        'Varela', 'Wanderson', 'Xavier', 'Yeboah', 'Zambo',
        'Alcácer', 'Bellingham', 'Camavinga', 'Dembélé', 'Endrick',
        'Frimpong', 'Gavi', 'Hernández', 'Ilaix', 'Júnior',
        'Kiwior', 'Lukić', 'Maatsen', 'Nkunku', 'Odriozola',
        'Pau', 'Quirini', 'Ramos', 'Scamacca', 'Theo',
        'Upamecano', 'Vinicius', 'Wirtz', 'Xavi', 'Yamal', 'Zirkzee',
        'Adeyemi', 'Bynoe-Gittens', 'Cherki', 'Duranville', 'Emegha',
    ];

    private const NATIONALITIES = [
        'Brazilian', 'Argentine', 'Spanish', 'Portuguese', 'Italian',
        'German', 'French', 'English', 'Dutch', 'Belgian',
        'Senegalese', 'Ivorian', 'Malian', 'Ghanaian', 'Nigerian',
        'Cameroonian', 'Guinean', 'Burkina Fasan', 'Congolese', 'Moroccan',
        'Croatian', 'Serbian', 'Bosnian', 'Turkish', 'Austrian',
        'Danish', 'Swedish', 'Norwegian', 'American', 'Mexican',
        'Colombian', 'Uruguayan', 'Chilean', 'Japanese', 'South Korean',
    ];

    // ---------------------------------------------------------------------------

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'n', InputOption::VALUE_OPTIONAL, 'Number of prospects to generate', 200)
            ->addOption('clear', 'c', InputOption::VALUE_NONE,     'Delete existing prospect pool before seeding')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $count = (int) $input->getOption('count');
        $clear = (bool) $input->getOption('clear');

        $io->title('Wunderkind Factory — Prospect Pool Seeder');

        if ($clear) {
            $deleted = $this->em->createQuery(
                'DELETE FROM App\Entity\Player p
                 WHERE p.academy IS NULL
                   AND p.recruitmentSource = :source'
            )
                ->setParameter('source', RecruitmentSource::SCOUTING_NETWORK)
                ->execute();

            $io->text(sprintf('Cleared %d existing prospects.', $deleted));
            $io->newLine();
        }

        // Load agents for optional assignment (same 40% rule as market players)
        $agents = $this->em->getRepository(Agent::class)->findAll();

        $io->text(sprintf('Generating %d scout prospects...', $count));
        $progressBar = $io->createProgressBar($count);
        $progressBar->start();

        for ($i = 0; $i < $count; $i++) {
            // Prospects skew higher quality than typical market players — they are
            // gems that scouts uncover, so ability floor is raised.
            $potential      = random_int(60, 99);
            $currentAbility = max(45, $potential - random_int(5, 20));
            $age            = random_int(14, 20); // Youth focus

            $contractValue = $currentAbility * random_int(10, 30);

            $player = new Player(
                firstName:         $this->pickFrom(self::FIRST_NAMES),
                lastName:          $this->pickFrom(self::LAST_NAMES),
                dateOfBirth:       $this->dobFromAge($age),
                nationality:       $this->pickFrom(self::NATIONALITIES),
                position:          $this->weightedPosition(),
                recruitmentSource: RecruitmentSource::SCOUTING_NETWORK,
                potential:         $potential,
                currentAbility:    $currentAbility,
                academy:           null, // Deliberately unassigned — never on open market
            );

            $player->setStatus(PlayerStatus::ACTIVE);
            $player->setContractValue($contractValue);

            // 35% chance of having an agent
            if (!empty($agents) && random_int(1, 100) <= 35) {
                $player->setAgent($agents[array_rand($agents)]);
            }

            // Personality matrix
            $p = $player->getPersonality();
            $p->setConfidence(random_int(30, 95));
            $p->setMaturity(random_int(25, 85));
            $p->setTeamwork(random_int(30, 95));
            $p->setLeadership(random_int(20, 80));
            $p->setEgo(random_int(15, 75));
            $p->setBravery(random_int(35, 95));
            $p->setGreed(random_int(15, 75));
            $p->setLoyalty(random_int(30, 95));

            $this->em->persist($player);

            // Flush in batches to control memory
            if ($i > 0 && $i % 50 === 0) {
                $this->em->flush();
                $this->em->clear(Player::class);
                // Re-fetch agents after clear so references remain valid
                $agents = $this->em->getRepository(Agent::class)->findAll();
            }

            $progressBar->advance();
        }

        $this->em->flush();
        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf('Seeded %d scout prospects (SCOUTING_NETWORK, no academy).', $count));

        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function pickFrom(array $items): string
    {
        return $items[array_rand($items)];
    }

    /** GK 6 % / DEF 28 % / MID 40 % / ATT 26 % */
    private function weightedPosition(): PlayerPosition
    {
        $roll = random_int(1, 100);
        return match (true) {
            $roll <= 6  => PlayerPosition::GOALKEEPER,
            $roll <= 34 => PlayerPosition::DEFENDER,
            $roll <= 74 => PlayerPosition::MIDFIELDER,
            default     => PlayerPosition::ATTACKER,
        };
    }

    private function dobFromAge(int $age): \DateTimeImmutable
    {
        $year  = (int) date('Y') - $age;
        $month = random_int(1, 12);
        $day   = random_int(1, 28);

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
