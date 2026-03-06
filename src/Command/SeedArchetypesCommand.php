<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlayerArchetype;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-archetypes',
    description: 'Seeds 30 player archetype definitions (truncates existing data first — safe to re-run).',
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
                $data['name'],
                $data['description'],
                $data['traitMapping'],
            );
            $this->em->persist($archetype);
        }

        $this->em->flush();

        $io->success(sprintf('Seeded %d player archetypes.', count($archetypes)));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, description: string, traitMapping: array}>
     */
    private function buildArchetypes(): array
    {
        return [
            // ── Over-Achievers ──────────────────────────────────────────────────

            [
                'name'        => 'The Captain-in-Waiting',
                'description' => 'This kid was born to wear the armband. His teammates gravitate towards him naturally and he leads by example in every session. Coaches rarely need to ask twice — he has already sorted it.',
                'traitMapping' => ['formula' => ['confidence' => 0.40, 'consistency' => 0.35, 'loyalty' => 0.25], 'threshold' => 72],
            ],
            [
                'name'        => 'The Vocal Leader',
                'description' => 'Never short of an opinion and never afraid to voice it. He drives standards on the training pitch with relentless communication and genuine ambition to reach the very top. Polarising, but effective.',
                'traitMapping' => ['formula' => ['confidence' => 0.45, 'ambition' => 0.30, 'bravery' => 0.25], 'threshold' => 70],
            ],
            [
                'name'        => 'The Perfectionist',
                'description' => 'Still out on the pitch long after the session has ended, working on the same routine until it is flawless. His standards are almost unrealistically high — for himself and everyone around him.',
                'traitMapping' => ['formula' => ['professionalism' => 0.50, 'consistency' => 0.30, 'pressure' => 0.20], 'threshold' => 74],
            ],
            [
                'name'        => 'The Chess Master',
                'description' => 'Reads the game three moves ahead. His tactical awareness is exceptional for his age and his ambition to prove himself at the highest level gives him a focused, purposeful edge in everything he does.',
                'traitMapping' => ['formula' => ['ambition' => 0.40, 'professionalism' => 0.35, 'pressure' => 0.25], 'threshold' => 71],
            ],
            [
                'name'        => 'The Mentor Figure',
                'description' => 'An unusual level of emotional maturity for a youth player. Younger lads seek him out instinctively and the loyalty he shows to those around him earns him enormous respect in the dressing room.',
                'traitMapping' => ['formula' => ['loyalty' => 0.40, 'professionalism' => 0.35, 'consistency' => 0.25], 'threshold' => 70],
            ],
            [
                'name'        => 'The Future Coach',
                'description' => 'Already dissecting formations, challenging the coaching staff with intelligent questions, and keeping detailed mental notes on teammates. You sense his playing career is a stepping stone to a dugout.',
                'traitMapping' => ['formula' => ['professionalism' => 0.45, 'pressure' => 0.30, 'ambition' => 0.25], 'threshold' => 72],
            ],

            // ── Technical Artists ───────────────────────────────────────────────

            [
                'name'        => 'The Street Footballer',
                'description' => 'Learned the game in cages and car parks before anyone put a kit on him. The raw courage and inventiveness he brings to tight spaces cannot be coached — it has to be lived. Defenders hate him.',
                'traitMapping' => ['formula' => ['bravery' => 0.40, 'confidence' => 0.35, 'ego' => 0.25], 'threshold' => 68],
            ],
            [
                'name'        => 'The Futsal Specialist',
                'description' => 'Five-a-side has sharpened his first touch to a razor edge. He finds pockets of space instinctively and has the confidence to attempt audacious moves that most players would not consider.',
                'traitMapping' => ['formula' => ['confidence' => 0.40, 'bravery' => 0.35, 'ambition' => 0.25], 'threshold' => 67],
            ],
            [
                'name'        => 'The Showman',
                'description' => 'Every training session is a performance. He plays better when there is an audience and absolutely thrives on the occasion. Whether that translates to a professional career remains the question.',
                'traitMapping' => ['formula' => ['ego' => 0.45, 'confidence' => 0.35, 'bravery' => 0.20], 'threshold' => 70],
            ],
            [
                'name'        => 'The Flair Player',
                'description' => 'A natural entertainer with an instinct for the unexpected. His ambition pushes him to attempt the spectacular when the simple pass would do — infuriating and mesmerising in equal measure.',
                'traitMapping' => ['formula' => ['confidence' => 0.40, 'ego' => 0.35, 'ambition' => 0.25], 'threshold' => 68],
            ],
            [
                'name'        => 'The Trickster',
                'description' => 'Has a repertoire of skill moves that would embarrass professionals twice his age. Courageous enough to try them under pressure and with enough ego to dust himself off when they go wrong.',
                'traitMapping' => ['formula' => ['bravery' => 0.45, 'ego' => 0.30, 'confidence' => 0.25], 'threshold' => 67],
            ],
            [
                'name'        => 'The YouTube Highlight Reel',
                'description' => 'Compiles his best moments and knows exactly what goes viral. The ego is enormous — bigger than his discipline at times — but when the talent fires, you can see why the scouts keep coming back.',
                'traitMapping' => ['formula' => ['ego' => 0.50, 'bravery' => 0.30, 'confidence' => 0.20], 'threshold' => 72],
            ],

            // ── Reliable Backbone ───────────────────────────────────────────────

            [
                'name'        => 'The Safe Pair of Hands',
                'description' => 'Quietly delivers week in, week out without drama or fanfare. His consistency is the backbone of any squad he plays in and his professionalism sets a standard younger players instinctively follow.',
                'traitMapping' => ['formula' => ['consistency' => 0.45, 'professionalism' => 0.35, 'loyalty' => 0.20], 'threshold' => 72],
            ],
            [
                'name'        => 'The Unsung Hero',
                'description' => 'Never on the scoresheet, always on the winning team. He covers more ground than anyone, makes fewer mistakes than anyone, and never once asks for the credit he quietly deserves.',
                'traitMapping' => ['formula' => ['consistency' => 0.40, 'loyalty' => 0.35, 'professionalism' => 0.25], 'threshold' => 70],
            ],
            [
                'name'        => 'The Club Pillar',
                'description' => 'Turned down three bigger academies to stay here. Loyalty runs through him like stitching in a club scarf. He will not win you individual trophies, but he will help you build something lasting.',
                'traitMapping' => ['formula' => ['loyalty' => 0.50, 'consistency' => 0.30, 'professionalism' => 0.20], 'threshold' => 73],
            ],
            [
                'name'        => 'The Model Professional',
                'description' => 'Immaculate timekeeping, immaculate diet, immaculate attitude. His professionalism is a daily reminder of what it means to take the craft seriously. Every academy needs one of him.',
                'traitMapping' => ['formula' => ['professionalism' => 0.50, 'consistency' => 0.30, 'loyalty' => 0.20], 'threshold' => 74],
            ],
            [
                'name'        => 'The Metronome',
                'description' => 'Rhythmic and relentless. He performs at a consistent tempo that rarely wavers whether the match is meaningless or the stakes are at their highest. A manager\'s dream: always available, always reliable.',
                'traitMapping' => ['formula' => ['consistency' => 0.50, 'pressure' => 0.30, 'professionalism' => 0.20], 'threshold' => 72],
            ],
            [
                'name'        => 'The Academy Graduate',
                'description' => 'Every touch carries the weight of years in the system. He knows every corner of this club and the ambition to repay the faith shown in him burns quietly but permanently.',
                'traitMapping' => ['formula' => ['loyalty' => 0.45, 'professionalism' => 0.30, 'ambition' => 0.25], 'threshold' => 70],
            ],

            // ── Wildcards ───────────────────────────────────────────────────────

            [
                'name'        => 'The Volatile Maverick',
                'description' => 'His ambition and ego make him a handful in any dressing room. Three moments of individual brilliance per game, one moment of rank stupidity. Managing him is the hardest and most rewarding job in football.',
                'traitMapping' => ['formula' => ['ego' => 0.45, 'bravery' => 0.35, 'ambition' => 0.20], 'threshold' => 68],
            ],
            [
                'name'        => 'The Social Media Darling',
                'description' => 'More followers than most professional clubs at his age. The ego and confidence are stratospheric, and he plays as if every session is content. Whether that helps or hinders development is the constant debate.',
                'traitMapping' => ['formula' => ['ego' => 0.50, 'confidence' => 0.30, 'ambition' => 0.20], 'threshold' => 70],
            ],
            [
                'name'        => 'The Big-Game Hunter',
                'description' => 'Disappears in training, comes alive under the floodlights. His ambition to perform in the defining moments is extraordinary — and in those moments, he almost always delivers the goods.',
                'traitMapping' => ['formula' => ['ambition' => 0.50, 'bravery' => 0.30, 'confidence' => 0.20], 'threshold' => 72],
            ],
            [
                'name'        => 'The Mercenary',
                'description' => 'Loyal to the badge for precisely as long as it suits him. His ambition is matched only by his ego, and both will be redirected without a second thought the moment a better offer materialises.',
                'traitMapping' => ['formula' => ['ambition' => 0.50, 'ego' => 0.30, 'bravery' => 0.20], 'threshold' => 70],
            ],
            [
                'name'        => 'The Hot Prospect',
                'description' => 'Every scout in the region has his name on a clipboard. The confidence is sky-high, the ambition is unquestionable — the only risk is whether the ego lets the talent breathe.',
                'traitMapping' => ['formula' => ['confidence' => 0.40, 'ambition' => 0.40, 'ego' => 0.20], 'threshold' => 68],
            ],
            [
                'name'        => 'The Controversy Magnet',
                'description' => 'Follows him everywhere he goes. The ego and bravery combine in ways that produce moments of genius and moments of madness in roughly equal proportions. Captivating and exhausting in the same breath.',
                'traitMapping' => ['formula' => ['ego' => 0.45, 'bravery' => 0.30, 'ambition' => 0.25], 'threshold' => 71],
            ],

            // ── Project Players ─────────────────────────────────────────────────

            [
                'name'        => 'The Late Starter',
                'description' => 'Arrived at the academy later than most but with a work ethic that leaves the early starters behind. His professionalism and consistency suggest the ceiling is higher than his current level implies.',
                'traitMapping' => ['formula' => ['professionalism' => 0.45, 'consistency' => 0.30, 'loyalty' => 0.25], 'threshold' => 62],
            ],
            [
                'name'        => 'The Shy Prodigy',
                'description' => 'The talent is unmistakeable, but it takes patience to draw it out. He is loyal to those who persist and extraordinarily professional for his age — the confidence simply needs cultivating.',
                'traitMapping' => ['formula' => ['loyalty' => 0.40, 'professionalism' => 0.40, 'consistency' => 0.20], 'threshold' => 60],
            ],
            [
                'name'        => 'The Fragile Talent',
                'description' => 'On a good day, he is the best player on the pitch by some distance. On a bad day, the bravery evaporates entirely. The task is helping him find the consistency to show the good-day version every week.',
                'traitMapping' => ['formula' => ['ambition' => 0.40, 'consistency' => 0.35, 'professionalism' => 0.25], 'threshold' => 63],
            ],
            [
                'name'        => 'The Diamond in the Rough',
                'description' => 'Nobody else spotted him. The bravery to take on players twice his size and the ambition to keep trying after failure mark him out as someone worth investing serious time in.',
                'traitMapping' => ['formula' => ['bravery' => 0.40, 'ambition' => 0.35, 'consistency' => 0.25], 'threshold' => 62],
            ],
            [
                'name'        => 'The Confidence Project',
                'description' => 'The technical quality is already there. What is missing is the belief that it is good enough. He responds enormously well to encouragement and his consistency in training gives us reason to persevere.',
                'traitMapping' => ['formula' => ['consistency' => 0.45, 'loyalty' => 0.35, 'professionalism' => 0.20], 'threshold' => 61],
            ],
            [
                'name'        => 'The Quiet Worker',
                'description' => 'Never the loudest voice, never the first name on any teamsheet — not yet. But his professionalism, loyalty, and willingness to improve quietly suggest the rewards will come for both player and academy.',
                'traitMapping' => ['formula' => ['professionalism' => 0.45, 'loyalty' => 0.35, 'bravery' => 0.20], 'threshold' => 65],
            ],
        ];
    }
}
