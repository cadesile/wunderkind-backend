<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlayerArchetype;
use App\Enum\ArchetypePolarity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-archetypes',
    description: 'Seeds the 20 curated player archetypes — 10 positive, 10 negative (truncates existing data first — safe to re-run).',
)]
class SeedArchetypesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Truncate via DQL to reset auto-increment and avoid stale IDs.
        $this->em->getConnection()->executeStatement('DELETE FROM player_archetype');
        $io->note('Cleared existing player_archetype rows.');

        $archetypes = $this->buildArchetypes();

        foreach ($archetypes as $data) {
            $archetype = new PlayerArchetype(
                $data['slug'],
                $data['name'],
                $data['description'],
                $data['polarity'],
                $data['traitWeights'],
            );
            $this->em->persist($archetype);
        }

        $this->em->flush();

        $io->success(sprintf('Seeded %d player archetypes.', count($archetypes)));

        return Command::SUCCESS;
    }

    /**
     * Builds a trait-weight formula from two signed drivers.
     *
     * Positive weight = "High trait", negative = "Low trait". Absolute values sum to 1.0.
     * Keys must be fields of PersonalityProfile — see the PlayerArchetype docblock.
     */
    private static function drivers(string $a, float $wa, string $b, float $wb, int $threshold = 65): array
    {
        return ['formula' => [$a => $wa, $b => $wb], 'threshold' => $threshold];
    }

    /**
     * @return array<int, array{slug: string, name: string, description: string, polarity: ArchetypePolarity, traitWeights: array}>
     */
    private function buildArchetypes(): array
    {
        $pos = ArchetypePolarity::POSITIVE;
        $neg = ArchetypePolarity::NEGATIVE;

        return [
            // ── Positive ────────────────────────────────────────────────────────
            [
                'slug'         => 'standard_bearer',
                'name'         => 'Standard Bearer',
                'description'  => 'Sets the training standard and XP floor for the whole squad; anchors dressing-room morale during losing runs.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('professionalism', 0.5, 'determination', 0.5),
            ],
            [
                'slug'         => 'big_game_specialist',
                'name'         => 'Big-Game Specialist',
                'description'  => 'Overperforms in promotion deciders, derby fixtures, and cup runs; immune to late-game choke events.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('pressure', 0.5, 'temperament', 0.5),
            ],
            [
                'slug'         => 'model_professional',
                'name'         => 'Model Professional',
                'description'  => 'Maintains optimal condition; settles immediately after transfers or tactical role shifts with low guardian friction.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('professionalism', 0.5, 'adaptability', 0.5),
            ],
            [
                'slug'         => 'iron_will',
                'name'         => 'Iron Will',
                'description'  => 'Accelerated recovery from bad form; match-engine events trigger late equalisers and defensive blocks.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('determination', 0.5, 'pressure', 0.5),
            ],
            [
                'slug'         => 'club_loyalist',
                'name'         => 'Club Loyalist',
                'description'  => 'Tolerates below-market wages and broken promotion targets; rejects poaching attempts from higher tiers.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('loyalty', 0.5, 'ambition', -0.5),
            ],
            [
                'slug'         => 'the_metronome',
                'name'         => 'The Metronome',
                'description'  => 'Delivers consistent 7.0+ match ratings weekly with minimal variance in training development.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('consistency', 0.5, 'professionalism', 0.5),
            ],
            [
                'slug'         => 'natural_leader',
                'name'         => 'Natural Leader',
                'description'  => 'Bridges dressing-room cliques and buffers the squad against fallout from broken chairman promises.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('pressure', 0.5, 'loyalty', 0.5),
            ],
            [
                'slug'         => 'chameleon',
                'name'         => 'Chameleon',
                'description'  => 'Zero morale penalty when played out of position; adapts to tactical shifts instantly.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('adaptability', 0.5, 'temperament', 0.5),
            ],
            [
                'slug'         => 'fierce_competitor',
                'name'         => 'Fierce Competitor',
                'description'  => 'Accelerates XP gains when competing for starting spots and drives up training intensity for peers.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('determination', 0.5, 'ambition', 0.5),
            ],
            [
                'slug'         => 'dressing_room_glue',
                'name'         => 'Dressing Room Glue',
                'description'  => 'Heals fractured squad social graphs and buffers team-wide morale drops after heavy defeats.',
                'polarity'     => $pos,
                'traitWeights' => self::drivers('temperament', 0.5, 'loyalty', 0.5),
            ],

            // ── Negative ────────────────────────────────────────────────────────
            [
                'slug'         => 'mercenary',
                'name'         => 'Mercenary',
                'description'  => 'Demands aggressive wage bumps after short runs of form; forces transfer requests if higher-tier clubs take interest.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('ambition', 0.5, 'loyalty', -0.5),
            ],
            [
                'slug'         => 'hothead',
                'name'         => 'Hothead',
                'description'  => 'Prone to reckless challenges, costly cards, and altercation cooldowns during high-stakes fixtures.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('temperament', -0.5, 'determination', 0.5),
            ],
            [
                'slug'         => 'choker',
                'name'         => 'Choker',
                'description'  => 'Suffers steep rating drops in playoff finals, derbies, or relegation battles.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('pressure', -0.5, 'consistency', -0.5),
            ],
            [
                'slug'         => 'dressing_room_cancer',
                'name'         => 'Dressing Room Cancer',
                'description'  => 'Drags down youth morale and forms toxic dressing-room cliques if benched or overlooked.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('professionalism', -0.5, 'temperament', -0.5),
            ],
            [
                'slug'         => 'glass_ego',
                'name'         => 'Glass Ego',
                'description'  => 'Morale collapses rapidly when criticised or dropped for a single matchweek.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('pressure', -0.5, 'ambition', 0.5),
            ],
            [
                'slug'         => 'unsettled_soul',
                'name'         => 'Unsettled Soul',
                'description'  => 'Suffers prolonged attribute penalties upon arrival and frequently generates homesick inbox events.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('adaptability', -0.5, 'loyalty', -0.5),
            ],
            [
                'slug'         => 'complacent_passenger',
                'name'         => 'Complacent Passenger',
                'description'  => 'Development stalls and training XP drops sharply once handed a long-term or high-wage contract.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('ambition', -0.5, 'determination', -0.5),
            ],
            [
                'slug'         => 'maverick',
                'name'         => 'Maverick',
                'description'  => 'Prone to off-pitch discipline breaches, missed training sessions, and drifting from tactical instructions.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('professionalism', -0.5, 'temperament', 0.5),
            ],
            [
                'slug'         => 'envious_rival',
                'name'         => 'Envious Rival',
                'description'  => 'Morale drops and social clashes occur whenever positional peers receive wage bumps or star roles.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('ambition', 0.5, 'temperament', -0.5),
            ],
            [
                'slug'         => 'flat_track_bully',
                'name'         => 'Flat-Track Bully',
                'description'  => 'Dominates lower-table opposition but goes missing against top-half rivals.',
                'polarity'     => $neg,
                'traitWeights' => self::drivers('ambition', 0.5, 'pressure', -0.5),
            ],
        ];
    }
}
