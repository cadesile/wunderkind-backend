<?php

namespace App\Command;

use App\Entity\GameEventTemplate;
use App\Enum\EventCategory;
use App\Repository\GameEventTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-game-events',
    description: 'Seeds initial game event templates (idempotent — skips existing slugs).',
)]
class SeedGameEventsCommand extends Command
{
    public function __construct(
        private readonly GameEventTemplateRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $templates = $this->buildTemplates();

        $created = 0;
        $skipped = 0;

        foreach ($templates as $data) {
            if ($this->repository->findBySlug($data['slug']) !== null) {
                $io->note("Skipping existing slug: {$data['slug']}");
                $skipped++;
                continue;
            }

            $template = new GameEventTemplate(
                $data['slug'],
                $data['category'],
                $data['title'],
                $data['bodyTemplate'],
                $data['impacts'],
                $data['weight'],
            );

            if (isset($data['firingConditions'])) {
                $template->setFiringConditions($data['firingConditions']);
            }

            if (isset($data['severity'])) {
                $template->setSeverity($data['severity']);
            }

            $this->em->persist($template);
            $created++;
        }

        $this->em->flush();

        $io->success("Seeded {$created} event template(s). Skipped {$skipped} existing.");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{slug: string, category: EventCategory, weight: int, title: string, bodyTemplate: string, impacts: array}>
     */
    private function buildTemplates(): array
    {
        return [
            [
                'slug'         => 'player_homesick',
                'category'     => EventCategory::PLAYER,
                'weight'       => 3,
                'title'        => 'Homesickness',
                'bodyTemplate' => '{player} has been struggling to settle and is showing signs of homesickness. Their morale has taken a hit.',
                'impacts'      => [
                    ['target' => 'player.morale', 'delta' => -8],
                    ['target' => 'player.personality.loyalty', 'delta' => -3],
                ],
            ],
            [
                'slug'         => 'training_argument',
                'category'     => EventCategory::PLAYER,
                'weight'       => 2,
                'title'        => 'Training Ground Dispute',
                'bodyTemplate' => '{staff} and {player} clashed on the training pitch today. Team cohesion has suffered slightly.',
                'impacts'      => [
                    ['target' => 'player.morale', 'delta' => -5],
                    ['target' => 'player.personality.teamwork', 'delta' => -4],
                    ['target' => 'staff.morale', 'delta' => -3],
                ],
            ],
            [
                'slug'         => 'minor_injury',
                'category'     => EventCategory::PLAYER,
                'weight'       => 4,
                'title'        => 'Minor Injury',
                'bodyTemplate' => '{player} picked up a minor knock during training and will miss the next session.',
                'impacts'      => [
                    ['target' => 'player.injuredWeeks', 'delta' => 1],
                    ['target' => 'player.morale', 'delta' => -4],
                ],
            ],
            [
                'slug'         => 'injury_recovery',
                'category'     => EventCategory::PLAYER,
                'weight'       => 0,
                'title'        => 'Injury Recovery',
                'bodyTemplate' => '{player} has made a full recovery and is ready to return to training.',
                'impacts'      => [
                    ['target' => 'player.injuredWeeks', 'delta' => 0],
                    ['target' => 'player.morale', 'delta' => 5],
                ],
            ],
            [
                'slug'         => 'facility_complaint',
                'category'     => EventCategory::FACILITY,
                'weight'       => 2,
                'title'        => 'Facility Complaint',
                'bodyTemplate' => 'Players have voiced concerns about the state of the {facility}. Squad morale has dipped.',
                'impacts'      => [
                    ['target' => 'squad.morale', 'delta' => -6],
                    ['target' => 'academy.reputation', 'delta' => -1],
                ],
            ],
            [
                'slug'         => 'coach_weekly_report',
                'category'     => EventCategory::STAFF,
                'weight'       => 0, // Triggered programmatically, not randomly selected
                'title'        => '{coachName} Weekly Report',
                'bodyTemplate' => '{reportSummary}',
                'impacts'      => [],
            ],

            // ── NPC Training Incidents ────────────────────────────────────────
            [
                'slug'             => 'npc-training-altercation-aggression',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 4,
                'title'            => 'Training Altercation',
                'bodyTemplate'     => '{player_1} and {player_2} clashed on the training pitch. The argument turned physical and the session had to be paused.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                   'delta' => -6],
                    ['target' => 'player_2.morale',                   'delta' => -6],
                    ['target' => 'player_1.personality.teamwork',     'delta' => -4],
                    ['target' => 'player_2.personality.teamwork',     'delta' => -4],
                    ['target' => 'pair.relationship',                 'delta' => -10],
                ],
                'severity'         => 'major',
                'firingConditions' => [
                    'maxPairRelationship' => 20,
                ],
            ],
            [
                'slug'             => 'npc-verbal-confrontation-low-morale',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Verbal Confrontation',
                'bodyTemplate'     => 'Tensions boiled over as {player_1} directed sharp words at {player_2} during a drill. The atmosphere soured for the rest of the day.',
                'impacts'          => [
                    ['target' => 'player_1.morale',               'delta' => -4],
                    ['target' => 'player_2.morale',               'delta' => -5],
                    ['target' => 'player_1.personality.ego',      'delta' => 2],
                    ['target' => 'pair.relationship',             'delta' => -7],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'maxSquadMorale'      => 55,
                    'maxPairRelationship' => 30,
                    'actorTraitRequirements' => [
                        ['trait' => 'ego', 'min' => 60],
                    ],
                ],
            ],
            [
                'slug'             => 'npc-player-mentoring-leadership',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Experienced Hand',
                'bodyTemplate'     => '{player_1} took {player_2} under their wing after training, sharing advice on positioning and focus. {player_2} responded well.',
                'impacts'          => [
                    ['target' => 'player_1.personality.leadership', 'delta' => 2],
                    ['target' => 'player_2.morale',                 'delta' => 5],
                    ['target' => 'player_2.personality.confidence', 'delta' => 3],
                    ['target' => 'pair.relationship',               'delta' => 6],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minPairRelationship' => 30,
                    'actorTraitRequirements' => [
                        ['trait' => 'leadership', 'min' => 60],
                    ],
                    'subjectTraitRequirements' => [
                        ['trait' => 'maturity', 'max' => 50],
                    ],
                ],
            ],
            [
                'slug'             => 'npc-rivalry-raises-standards',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Healthy Rivalry',
                'bodyTemplate'     => 'A fiercely competitive exchange between {player_1} and {player_2} during drills pushed both players to new heights. The squad took notice.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                   'delta' => 4],
                    ['target' => 'player_2.morale',                   'delta' => 4],
                    ['target' => 'player_1.personality.bravery',      'delta' => 2],
                    ['target' => 'player_2.personality.bravery',      'delta' => 2],
                    ['target' => 'pair.relationship',                 'delta' => 3],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale'      => 55,
                    'minPairRelationship' => -10,
                    'maxPairRelationship' => 20,
                    'requiresCoLocation'  => true,
                ],
            ],
            [
                'slug'             => 'npc-player-withdrawal-low-morale',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Withdrawn Attitude',
                'bodyTemplate'     => '{player_1} has been distancing themselves from {player_2} and the rest of the group. The disconnect is becoming visible on the pitch.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                   'delta' => -5],
                    ['target' => 'player_1.personality.teamwork',     'delta' => -3],
                    ['target' => 'pair.relationship',                 'delta' => -5],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'maxSquadMorale' => 45,
                    'actorTraitRequirements' => [
                        ['trait' => 'confidence', 'max' => 40],
                    ],
                ],
            ],
            [
                'slug'             => 'npc-dressing-room-positive-atmosphere',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Positive Dressing Room',
                'bodyTemplate'     => '{player_1} and {player_2} were seen lifting spirits in the changing room. The energy around the squad has noticeably improved.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                   'delta' => 3],
                    ['target' => 'player_2.morale',                   'delta' => 3],
                    ['target' => 'player_1.personality.teamwork',     'delta' => 2],
                    ['target' => 'pair.relationship',                 'delta' => 5],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale'      => 65,
                    'minPairRelationship' => 25,
                ],
            ],
            [
                'slug'             => 'npc-professionalism-incident',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Professionalism Issue',
                'bodyTemplate'     => '{player_1} showed up late and poorly prepared, frustrating {player_2} who had been waiting to partner in drills. The coaching staff had to intervene.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                  'delta' => -4],
                    ['target' => 'player_1.personality.maturity',    'delta' => -3],
                    ['target' => 'player_2.morale',                  'delta' => -3],
                    ['target' => 'pair.relationship',                'delta' => -6],
                ],
                'severity'         => 'major',
                'firingConditions' => [
                    'actorTraitRequirements' => [
                        ['trait' => 'maturity', 'max' => 40],
                        ['trait' => 'ego',      'min' => 55],
                    ],
                    'requiresCoLocation' => true,
                ],
            ],
            [
                'slug'             => 'npc-coach-player-breakthrough',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Training Breakthrough',
                'bodyTemplate'     => 'Something clicked between {player_1} and {player_2} on the training pitch today. An unexpected moment of understanding has strengthened their bond.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                   'delta' => 6],
                    ['target' => 'player_2.morale',                   'delta' => 6],
                    ['target' => 'player_1.personality.confidence',   'delta' => 3],
                    ['target' => 'player_2.personality.confidence',   'delta' => 3],
                    ['target' => 'pair.relationship',                 'delta' => 8],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale'      => 50,
                    'minPairRelationship' => 10,
                    'requiresCoLocation'  => true,
                ],
            ],
            [
                'slug'             => 'npc-ego-clash-two-players',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Ego Clash',
                'bodyTemplate'     => 'Both {player_1} and {player_2} want to lead — neither is willing to give ground. Their battle for dominance is starting to disrupt the group.',
                'impacts'          => [
                    ['target' => 'player_1.morale',               'delta' => -4],
                    ['target' => 'player_2.morale',               'delta' => -4],
                    ['target' => 'player_1.personality.ego',      'delta' => 3],
                    ['target' => 'player_2.personality.ego',      'delta' => 3],
                    ['target' => 'pair.relationship',             'delta' => -8],
                ],
                'severity'         => 'major',
                'firingConditions' => [
                    'actorTraitRequirements' => [
                        ['trait' => 'ego', 'min' => 65],
                    ],
                    'subjectTraitRequirements' => [
                        ['trait' => 'ego', 'min' => 65],
                    ],
                ],
            ],
            [
                'slug'             => 'npc-quiet-leader-emerges',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Quiet Leader',
                'bodyTemplate'     => '{player_1} quietly guided {player_2} through a difficult session, saying little but meaning everything. A natural leader in the making.',
                'impacts'          => [
                    ['target' => 'player_1.personality.leadership', 'delta' => 3],
                    ['target' => 'player_1.personality.teamwork',   'delta' => 2],
                    ['target' => 'player_2.morale',                 'delta' => 5],
                    ['target' => 'player_2.personality.maturity',   'delta' => 2],
                    ['target' => 'pair.relationship',               'delta' => 7],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minPairRelationship' => 15,
                    'actorTraitRequirements' => [
                        ['trait' => 'leadership', 'min' => 55],
                        ['trait' => 'ego',        'max' => 45],
                    ],
                ],
            ],

            // ── Additional positive NPC events ────────────────────────────────

            [
                'slug'             => 'npc-training-high-five',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 4,
                'title'            => 'Great Moment in Training',
                'bodyTemplate'     => '{player_1} pulled off something special in training and {player_2} was first to celebrate. The good energy spread quickly through the group.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                 'delta' => 5],
                    ['target' => 'player_2.morale',                 'delta' => 4],
                    ['target' => 'player_1.personality.confidence', 'delta' => 2],
                    ['target' => 'pair.relationship',               'delta' => 5],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale' => 40,
                ],
            ],
            [
                'slug'             => 'npc-shared-laughter',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 4,
                'title'            => 'Good Spirits',
                'bodyTemplate'     => '{player_1} had {player_2} in stitches between drills today. It was a small moment, but laughter goes a long way in a squad.',
                'impacts'          => [
                    ['target' => 'player_1.morale',               'delta' => 4],
                    ['target' => 'player_2.morale',               'delta' => 4],
                    ['target' => 'pair.relationship',             'delta' => 4],
                ],
                'severity'         => 'minor',
                'firingConditions' => [],
            ],
            [
                'slug'             => 'npc-standing-up-for-teammate',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Stood Up for a Teammate',
                'bodyTemplate'     => 'When criticism came {player_2}\'s way, {player_1} stepped in without hesitation. It was a show of character that didn\'t go unnoticed.',
                'impacts'          => [
                    ['target' => 'player_1.personality.loyalty',    'delta' => 3],
                    ['target' => 'player_1.personality.leadership',  'delta' => 2],
                    ['target' => 'player_2.morale',                  'delta' => 7],
                    ['target' => 'player_2.personality.loyalty',     'delta' => 2],
                    ['target' => 'pair.relationship',                'delta' => 9],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'actorTraitRequirements' => [
                        ['trait' => 'loyalty', 'min' => 55],
                    ],
                ],
            ],
            [
                'slug'             => 'npc-cultural-exchange',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Bridging the Gap',
                'bodyTemplate'     => '{player_1} and {player_2} spent time after training swapping stories about where they\'re from. A connection formed that\'s hard to put into words.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                   'delta' => 4],
                    ['target' => 'player_2.morale',                   'delta' => 4],
                    ['target' => 'player_1.personality.adaptability', 'delta' => 2],
                    ['target' => 'player_2.personality.adaptability', 'delta' => 2],
                    ['target' => 'pair.relationship',                 'delta' => 6],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'maxPairRelationship' => 30,
                ],
            ],
            [
                'slug'             => 'npc-veteran-reassurance',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Words of Reassurance',
                'bodyTemplate'     => '{player_1} pulled {player_2} aside after a rough session and reminded them why they\'re here. Sometimes that\'s all it takes.',
                'impacts'          => [
                    ['target' => 'player_1.personality.leadership', 'delta' => 2],
                    ['target' => 'player_2.morale',                 'delta' => 8],
                    ['target' => 'player_2.personality.confidence', 'delta' => 3],
                    ['target' => 'pair.relationship',               'delta' => 6],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'actorTraitRequirements' => [
                        ['trait' => 'maturity',  'min' => 55],
                        ['trait' => 'teamwork',  'min' => 50],
                    ],
                    'subjectTraitRequirements' => [
                        ['trait' => 'confidence', 'max' => 45],
                    ],
                ],
            ],
            [
                'slug'             => 'npc-spontaneous-kickabout',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'After-Training Kickabout',
                'bodyTemplate'     => '{player_1} organised an impromptu game after the session ended. {player_2} was first to join. The whole squad ended the day with smiles on their faces.',
                'impacts'          => [
                    ['target' => 'player_1.morale',               'delta' => 5],
                    ['target' => 'player_2.morale',               'delta' => 5],
                    ['target' => 'player_1.personality.teamwork', 'delta' => 2],
                    ['target' => 'pair.relationship',             'delta' => 5],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale' => 45,
                    'actorTraitRequirements' => [
                        ['trait' => 'teamwork', 'min' => 55],
                    ],
                ],
            ],
            [
                'slug'             => 'npc-goal-celebration-chemistry',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 4,
                'title'            => 'Instant Chemistry',
                'bodyTemplate'     => '{player_1} scored a peach and ran straight to {player_2}. No words — just the kind of instinctive bond that makes a team click.',
                'impacts'          => [
                    ['target' => 'player_1.morale',                 'delta' => 6],
                    ['target' => 'player_2.morale',                 'delta' => 5],
                    ['target' => 'player_1.personality.confidence', 'delta' => 2],
                    ['target' => 'pair.relationship',               'delta' => 6],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale' => 40,
                ],
            ],
            [
                'slug'             => 'npc-squad-banter',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 3,
                'title'            => 'Dressing Room Banter',
                'bodyTemplate'     => 'The ribbing between {player_1} and {player_2} had the whole room in stitches. Good-natured banter like this is the glue of a happy dressing room.',
                'impacts'          => [
                    ['target' => 'player_1.morale',               'delta' => 4],
                    ['target' => 'player_2.morale',               'delta' => 4],
                    ['target' => 'pair.relationship',             'delta' => 4],
                    ['target' => 'player_1.personality.ego',      'delta' => -1],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minPairRelationship' => 5,
                ],
            ],
        ];
    }
}
