<?php

namespace App\Command;

use App\Enum\EventCategory;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'app:seed-game-events',
    description: 'Seeds initial game event templates (skips existing slugs; pass --update to overwrite them).',
)]
class SeedGameEventsCommand extends AbstractSeedEventTemplatesCommand
{
    protected function templateLabel(): string
    {
        return 'event';
    }

    /**
     * @return array<int, array{slug: string, category: EventCategory, weight: int, title: string, bodyTemplate: string, impacts: array}>
     */
    protected function buildTemplates(): array
    {
        return [
            [
                'slug'         => 'player_homesick',
                'category'     => EventCategory::PLAYER,
                'weight'       => 3,
                'title'        => 'Homesickness',
                'bodyTemplate' => '{player} has been struggling to settle and is showing signs of homesickness. Their morale has taken a hit.',
                'impacts'      => [
                    ['target' => 'player_1.morale', 'delta' => -8],
                    ['target' => 'player_1.personality.loyalty', 'delta' => -3],
                ],
            ],
            [
                'slug'         => 'training_argument',
                'category'     => EventCategory::PLAYER,
                'weight'       => 2,
                'title'        => 'Training Ground Dispute',
                // {staff} is not substituted by any client engine and would render literally,
                // and a staff.morale impact on a PLAYER-category template is dropped — only
                // the staff path (subject: "staff") applies it.
                'bodyTemplate' => '{player_1} and a member of the coaching staff clashed on the training pitch today. Team cohesion has suffered slightly.',
                'impacts'      => [
                    ['target' => 'player_1.morale', 'delta' => -5],
                    ['target' => 'player_1.personality.adaptability', 'delta' => -4],
                ],
            ],
            [
                'slug'         => 'minor_injury',
                'category'     => EventCategory::PLAYER,
                'weight'       => 4,
                'title'        => 'Minor Injury',
                'bodyTemplate' => '{player} picked up a minor knock during training and will miss the next session.',
                'impacts'      => [
                    ['target' => 'player_1.injuredWeeks', 'delta' => 1],
                    ['target' => 'player_1.morale', 'delta' => -4],
                ],
            ],
            [
                'slug'         => 'injury_recovery',
                'category'     => EventCategory::PLAYER,
                'weight'       => 0,
                'title'        => 'Injury Recovery',
                'bodyTemplate' => '{player} has made a full recovery and is ready to return to training.',
                'impacts'      => [
                    ['target' => 'player_1.injuredWeeks', 'delta' => 0],
                    ['target' => 'player_1.morale', 'delta' => 5],
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
                    ['target' => 'club.reputation', 'delta' => -1],
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
                    ['target' => 'player_1.personality.adaptability',     'delta' => -4],
                    ['target' => 'player_2.personality.adaptability',     'delta' => -4],
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
                    ['target' => 'player_1.personality.ambition',      'delta' => 2],
                    ['target' => 'pair.relationship',             'delta' => -7],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'maxSquadMorale'      => 55,
                    'maxPairRelationship' => 30,
                    'actorTraitRequirements' => [
                        ['trait' => 'ambition', 'min' => 12],
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
                    ['target' => 'player_1.personality.determination', 'delta' => 2],
                    ['target' => 'player_2.morale',                 'delta' => 5],
                    ['target' => 'player_2.personality.pressure', 'delta' => 3],
                    ['target' => 'pair.relationship',               'delta' => 6],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minPairRelationship' => 30,
                    'actorTraitRequirements' => [
                        ['trait' => 'determination', 'min' => 12],
                    ],
                    'subjectTraitRequirements' => [
                        ['trait' => 'professionalism', 'max' => 10],
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
                    ['target' => 'player_1.personality.pressure',      'delta' => 2],
                    ['target' => 'player_2.personality.pressure',      'delta' => 2],
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
                    ['target' => 'player_1.personality.adaptability',     'delta' => -3],
                    ['target' => 'pair.relationship',                 'delta' => -5],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'maxSquadMorale' => 45,
                    'actorTraitRequirements' => [
                        ['trait' => 'pressure', 'max' => 9],
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
                    ['target' => 'player_1.personality.adaptability',     'delta' => 2],
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
                    ['target' => 'player_1.personality.professionalism',    'delta' => -3],
                    ['target' => 'player_2.morale',                  'delta' => -3],
                    ['target' => 'pair.relationship',                'delta' => -6],
                ],
                'severity'         => 'major',
                'firingConditions' => [
                    'actorTraitRequirements' => [
                        ['trait' => 'professionalism', 'max' => 9],
                        ['trait' => 'ambition', 'min' => 11],
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
                    ['target' => 'player_1.personality.pressure',   'delta' => 3],
                    ['target' => 'player_2.personality.pressure',   'delta' => 3],
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
                    ['target' => 'player_1.personality.ambition',      'delta' => 3],
                    ['target' => 'player_2.personality.ambition',      'delta' => 3],
                    ['target' => 'pair.relationship',             'delta' => -8],
                ],
                'severity'         => 'major',
                'firingConditions' => [
                    'actorTraitRequirements' => [
                        ['trait' => 'ambition', 'min' => 13],
                    ],
                    'subjectTraitRequirements' => [
                        ['trait' => 'ambition', 'min' => 13],
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
                    ['target' => 'player_1.personality.determination', 'delta' => 3],
                    ['target' => 'player_1.personality.adaptability',   'delta' => 2],
                    ['target' => 'player_2.morale',                 'delta' => 5],
                    ['target' => 'player_2.personality.professionalism',   'delta' => 2],
                    ['target' => 'pair.relationship',               'delta' => 7],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minPairRelationship' => 15,
                    'actorTraitRequirements' => [
                        ['trait' => 'determination', 'min' => 11],
                        ['trait' => 'ambition', 'max' => 10],
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
                    ['target' => 'player_1.personality.pressure', 'delta' => 2],
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
                    ['target' => 'player_1.personality.determination',  'delta' => 2],
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
                    ['target' => 'player_1.personality.determination', 'delta' => 2],
                    ['target' => 'player_2.morale',                 'delta' => 8],
                    ['target' => 'player_2.personality.pressure', 'delta' => 3],
                    ['target' => 'pair.relationship',               'delta' => 6],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'actorTraitRequirements' => [
                        ['trait' => 'professionalism', 'min' => 11],
                        ['trait' => 'adaptability', 'min' => 10],
                    ],
                    'subjectTraitRequirements' => [
                        ['trait' => 'pressure', 'max' => 10],
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
                    ['target' => 'player_1.personality.adaptability', 'delta' => 2],
                    ['target' => 'pair.relationship',             'delta' => 5],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale' => 45,
                    'actorTraitRequirements' => [
                        ['trait' => 'adaptability', 'min' => 11],
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
                    ['target' => 'player_1.personality.pressure', 'delta' => 2],
                    ['target' => 'pair.relationship',               'delta' => 6],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minSquadMorale' => 40,
                ],
            ],
            // ── Guardian Events ───────────────────────────────────────────────

            // Background noise — weight 3
            [
                'slug'         => 'guardian_request_financial_gift',
                'category'     => EventCategory::GUARDIAN,
                'weight'       => 3,
                'title'        => 'Guardian Gift Request',
                'bodyTemplate' => '{guardian_name} has been in touch. They feel {player_name} deserves a little recognition for their hard work and are asking the club to show some goodwill — a small financial gesture would go a long way.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'guardian_request_travel_upgrade',
                'category'     => EventCategory::GUARDIAN,
                'weight'       => 3,
                'title'        => 'Travel Arrangement Request',
                'bodyTemplate' => '{guardian_name} has contacted us regarding travel arrangements for {player_name}. They feel the current setup isn\'t appropriate for a player of their child\'s standing and are requesting an upgrade.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'guardian_request_playing_time',
                'category'     => EventCategory::GUARDIAN,
                'weight'       => 3,
                'title'        => 'Playing Time Concern',
                'bodyTemplate' => '{guardian_name} has raised concerns about {player_name}\'s development pathway. They believe he isn\'t receiving enough on-pitch time to reach his potential and want a formal response.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'guardian_complaint_coach',
                'category'     => EventCategory::GUARDIAN,
                'weight'       => 3,
                'title'        => 'Coach Complaint',
                'bodyTemplate' => '{guardian_name} has filed a complaint regarding the coaching {player_name} is receiving. They feel the current approach isn\'t suited to their child\'s personality and are requesting a meeting.',
                'impacts'      => [],
            ],

            // Event-driven — weight 1
            [
                'slug'         => 'guardian_threat_withdrawal',
                'category'     => EventCategory::GUARDIAN,
                'weight'       => 1,
                'title'        => 'Withdrawal Threat',
                'bodyTemplate' => '{guardian_name} has contacted the club in a formal capacity. They are seriously considering withdrawing {player_name} from the programme if the situation does not improve immediately.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'guardian_low_morale_concern',
                'category'     => EventCategory::GUARDIAN,
                'weight'       => 1,
                'title'        => 'Guardian Morale Concern',
                'bodyTemplate' => '{guardian_name} reached out after noticing a change in {player_name}\'s mood at home. They want to know what\'s going on and whether the club is doing enough to support him.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'guardian_agent_approach_concern',
                'category'     => EventCategory::GUARDIAN,
                'weight'       => 1,
                'title'        => 'Agent Approach Concern',
                'bodyTemplate' => '{guardian_name} has informed us that {player_name} has been approached by an agent. They want to understand what this means for his future at the club before making any decisions.',
                'impacts'      => [],
            ],

            // ── Fan Engagement Events ─────────────────────────────────────────
            // weight: 0 — all triggered programmatically, never randomly selected.

            [
                'slug'         => 'fan_manager_sacking_demand',
                'category'     => EventCategory::FINANCE,
                'weight'       => 0,
                'title'        => 'Fans Demand Change',
                'bodyTemplate' => 'Fans are calling for the manager to be sacked. With only a {win_rate}% win rate, supporters are running out of patience.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'fan_manager_sacking_resolved',
                'category'     => EventCategory::FINANCE,
                'weight'       => 0,
                'title'        => 'Fan Pressure Eased',
                'bodyTemplate' => 'Fans have calmed down following improved results.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'fan_shirt_sales_income',
                'category'     => EventCategory::FINANCE,
                'weight'       => 0,
                'title'        => 'Season Shirt Sales Revenue',
                'bodyTemplate' => 'Season shirt sales revenue of {amount} has been credited to your balance.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'fan_promotion_boost',
                'category'     => EventCategory::FINANCE,
                'weight'       => 0,
                'title'        => 'Fanbase Energised by Promotion',
                'bodyTemplate' => 'Promotion has sent the fanbase into a frenzy! Fan numbers are up.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'fan_relegation_drop',
                'category'     => EventCategory::FINANCE,
                'weight'       => 0,
                'title'        => 'Fanbase Hit by Relegation',
                'bodyTemplate' => 'Relegation has hit the fanbase hard. Supporters are disheartened.',
                'impacts'      => [],
            ],
            [
                'slug'         => 'fan_morale_critical',
                'category'     => EventCategory::FINANCE,
                'weight'       => 0,
                'title'        => 'Fan Morale Critical',
                'bodyTemplate' => 'Fan morale has reached a critical low. Something must change.',
                'impacts'      => [],
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
                    ['target' => 'player_1.personality.ambition',      'delta' => -1],
                ],
                'severity'         => 'minor',
                'firingConditions' => [
                    'minPairRelationship' => 5,
                ],
            ],

            // ── Match Events ──────────────────────────────────────────────────
            [
                'slug'         => 'match_goal_1',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Goal!',
                'bodyTemplate' => '{player} gets on the scoresheet!',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 5],
                    ],
                ],
            ],
            [
                'slug'         => 'match_goal_2',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Goal!',
                'bodyTemplate' => '{player} finds the net!',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 5],
                    ],
                ],
            ],
            [
                'slug'         => 'match_goal_3',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Goal!',
                'bodyTemplate' => '{player} with a composed finish!',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 5],
                    ],
                ],
            ],
            [
                'slug'         => 'match_goal_4',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Goal!',
                'bodyTemplate' => '{player} breaks the deadlock!',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 5],
                    ],
                ],
            ],
            [
                'slug'         => 'match_assist_1',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Assist',
                'bodyTemplate' => '{player} with the perfect ball!',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 3],
                    ],
                ],
            ],
            [
                'slug'         => 'match_assist_2',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Assist',
                'bodyTemplate' => '{player} sets it up on a plate.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 3],
                    ],
                ],
            ],
            [
                'slug'         => 'match_assist_3',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Assist',
                'bodyTemplate' => '{player} with a brilliant assist!',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 3],
                    ],
                ],
            ],
            [
                'slug'         => 'match_assist_4',
                'category'     => EventCategory::MATCH,
                'weight'       => 10,
                'title'        => 'Assist',
                'bodyTemplate' => '{player} picks out the run perfectly.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 3],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_high_1',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Star Performance',
                'bodyTemplate' => '{player} is having a superb game.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 4],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_high_2',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Star Performance',
                'bodyTemplate' => '{player} is unplayable today.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 4],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_high_3',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Star Performance',
                'bodyTemplate' => '{player} dominates their position.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 4],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_high_4',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Star Performance',
                'bodyTemplate' => 'A commanding display from {player}.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 4],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_low_1',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Off Day',
                'bodyTemplate' => '{player} struggling to make an impact.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 4],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_low_2',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Off Day',
                'bodyTemplate' => '{player} having a difficult afternoon.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 4],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_low_3',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Off Day',
                'bodyTemplate' => '{player} is not at the races today.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 4],
                    ],
                ],
            ],
            [
                'slug'         => 'match_performance_low_4',
                'category'     => EventCategory::MATCH,
                'weight'       => 8,
                'title'        => 'Off Day',
                'bodyTemplate' => '{player} can\'t get into the game.',
                'impacts'      => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 4],
                    ],
                ],
            ],

            // ── Press Events ──────────────────────────────────────────────────
            // Narrative-only inbox items triggered by form/league-position conditions.
            // impacts is always empty — no stat mutations; the client handles display only.

            [
                'slug'             => 'press_bad_form_3',
                'category'         => EventCategory::PRESS,
                'weight'           => 1,
                'title'            => 'Under Pressure',
                'bodyTemplate'     => 'A three-game losing streak has brought unwanted attention to {clubName}\'s dugout. Speaking to reporters ahead of the weekend fixture, {managerName} was composed but the questions came thick and fast. Three consecutive defeats and no goals in the last two — the press pack wants answers.',
                'impacts'          => [],
                'firingConditions' => ['type' => 'consecutiveLosses', 'threshold' => 3],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'press_bad_form_5',
                'category'         => EventCategory::PRESS,
                'weight'           => 1,
                'title'            => 'Calls for Sacking',
                'bodyTemplate'     => 'Five consecutive defeats have left {clubName} in freefall, and the calls for {managerName}\'s dismissal are growing louder. After {streak} games without a win, sections of the fanbase have turned, and the board will be watching developments closely. Whether {managerName} can arrest the slide before it becomes irreversible remains to be seen.',
                'impacts'          => [],
                'firingConditions' => ['type' => 'consecutiveLosses', 'threshold' => 5],
                'severity'         => 'major',
            ],
            [
                'slug'             => 'press_good_form_5',
                'category'         => EventCategory::PRESS,
                'weight'           => 1,
                'title'            => 'On Fire',
                'bodyTemplate'     => '{clubName} are the talk of the division. A {streak}-game winning streak has turned heads across the league, with {managerName}\'s side playing with a confidence and fluency that looked impossible just weeks ago. The current position of {position} flatters no one — this team has earned every point.',
                'impacts'          => [],
                'firingConditions' => ['type' => 'consecutiveWins', 'threshold' => 5],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'press_unbeaten_10',
                'category'         => EventCategory::PRESS,
                'weight'           => 1,
                'title'            => 'Unbeaten Run Continues',
                'bodyTemplate'     => 'Ten games unbeaten and counting. {clubName}\'s remarkable run continues to attract attention from across the football world. {managerName} has been measured in their assessment, insisting the squad is focused only on the next game, but a {streak}-match unbeaten run tells its own story. At {position} in the table, this is no fluke.',
                'impacts'          => [],
                'firingConditions' => ['type' => 'unbeatenRun', 'threshold' => 10],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'press_top_of_table',
                'category'         => EventCategory::PRESS,
                'weight'           => 1,
                'title'            => 'Top of the Table',
                'bodyTemplate'     => 'There is no more prestigious place to sit, and right now {clubName} are at the summit. {managerName}\'s side occupy {position} in the table following another positive week, and the momentum behind this club feels genuine. The question is whether they can sustain it when the pressure really begins to mount.',
                'impacts'          => [],
                'firingConditions' => ['type' => 'leaguePosition', 'threshold' => 1],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'press_relegation_zone',
                'category'         => EventCategory::PRESS,
                'weight'           => 1,
                'title'            => 'Relegation Fears',
                'bodyTemplate'     => 'The word "relegation" is being said openly now, and {clubName} cannot afford to ignore it. Sitting in {position} in the table with the season approaching a critical juncture, {managerName} faces the biggest test of their tenure. The players insist belief remains in the dressing room, but results have to come — and soon.',
                'impacts'          => [],
                'firingConditions' => ['type' => 'relegationZone', 'threshold' => 1],
                'severity'         => 'major',
            ],
            [
                'slug'             => 'press_heavy_defeat',
                'category'         => EventCategory::PRESS,
                'weight'           => 1,
                'title'            => 'Heavy Defeat Fallout',
                'bodyTemplate'     => 'There are no easy words after a result like that. {clubName} were beaten heavily, conceding multiple goals in a performance that raised serious questions about the squad\'s resilience. {managerName} faced a tough room in the post-match press conference, with journalists pressing for explanations that were hard to come by.',
                'impacts'          => [],
                'firingConditions' => ['type' => 'goalDifference', 'threshold' => -4],
                'severity'         => 'minor',
            ],

            // ── Dressing Room Cohesion narrative events (interactive, AMP-only) ──
            // These are the first templates to use `impacts.choices` (EventChoice[])
            // instead of the legacy flat impacts array — each choice's stat_changes
            // is applied client-side by SimulationService.applyStatChanges only once
            // the manager taps a response (major severity skips auto-apply). See
            // the Impacts help table on the event-template admin page for the item shape.
            [
                'slug'             => 'dressing-room-rogue-press-leak',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Dressing Room Leak',
                'bodyTemplate'     => '{player_1} has leaked frustration about being overlooked to a local tabloid, naming {player_2} as a sympathetic ear in the dressing room. The story is already circulating.',
                'severity'         => 'major',
                'impacts'          => [
                    'choices' => [
                        [
                            'emoji'         => '📋',
                            'label'         => 'Back the manager — fine and drop the player',
                            'stat_changes'  => [
                                ['target' => 'player_1', 'field' => 'morale',       'operator' => 'subtract', 'value' => 10],
                                ['target' => 'pair',     'field' => 'relationship', 'operator' => 'subtract', 'value' => 15],
                            ],
                            'manager_shift' => ['temperament' => 0, 'discipline' => 5, 'ambition' => 0],
                        ],
                        [
                            'emoji'         => '🛡️',
                            'label'         => 'Publicly back the player over the manager',
                            'stat_changes'  => [
                                ['target' => 'player_1',   'field' => 'morale', 'operator' => 'add',      'value' => 6],
                                ['target' => 'squad_wide', 'field' => 'morale', 'operator' => 'subtract', 'value' => 8],
                            ],
                            'manager_shift' => ['temperament' => 0, 'discipline' => -6, 'ambition' => 0],
                        ],
                    ],
                ],
                'firingConditions' => [
                    'minPairRelationship'    => 40,
                    'actorTraitRequirements' => [
                        ['trait' => 'professionalism', 'max' => 8],
                        ['trait' => 'ambition',        'min' => 14],
                    ],
                ],
            ],
            [
                'slug'             => 'dressing-room-unsanctioned-night-out',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Unsanctioned Night Out',
                'bodyTemplate'     => '{player_1} and {player_2} organised an unauthorised night out with several senior players ahead of a crucial fixture. Word has reached the manager.',
                'severity'         => 'major',
                'impacts'          => [
                    'choices' => [
                        [
                            'emoji'         => '🍻',
                            'label'         => 'Turn a blind eye — let them bond',
                            'stat_changes'  => [
                                ['target' => 'pair',     'field' => 'relationship', 'operator' => 'add',      'value' => 15],
                                ['target' => 'player_1', 'field' => 'condition',    'operator' => 'subtract', 'value' => 15],
                                ['target' => 'player_2', 'field' => 'condition',    'operator' => 'subtract', 'value' => 15],
                            ],
                            'manager_shift' => ['temperament' => 0, 'discipline' => 0, 'ambition' => 0],
                        ],
                        [
                            'emoji'         => '🚫',
                            'label'         => 'Issue club fines',
                            'stat_changes'  => [
                                ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 4],
                                ['target' => 'player_2', 'field' => 'morale', 'operator' => 'subtract', 'value' => 4],
                            ],
                            'manager_shift' => ['temperament' => 0, 'discipline' => 3, 'ambition' => 0],
                        ],
                    ],
                ],
                'firingConditions' => [
                    'minPairRelationship' => 30,
                    'minSquadMorale'      => 40,
                ],
            ],
            [
                'slug'             => 'dressing-room-wonderkid-head-turn',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Transfer Speculation',
                'bodyTemplate'     => '{player_1} is being courted by a bigger club, and the transfer talk is dividing the dressing room — {player_2} has taken it personally.',
                'severity'         => 'major',
                'impacts'          => [
                    'choices' => [
                        [
                            'emoji'         => '🚫',
                            'label'         => 'Reject the bid outright, no promises',
                            'stat_changes'  => [
                                ['target' => 'player_1', 'field' => 'morale',                  'operator' => 'subtract', 'value' => 8],
                                ['target' => 'player_1', 'field' => 'personality.loyalty',      'operator' => 'subtract', 'value' => 1],
                                ['target' => 'pair',     'field' => 'relationship',             'operator' => 'subtract', 'value' => 10],
                            ],
                            'manager_shift' => ['temperament' => 0, 'discipline' => 0, 'ambition' => 0],
                        ],
                        [
                            'emoji'         => '🤝',
                            'label'         => 'Reject the bid, but promise a clear pathway',
                            'stat_changes'  => [
                                ['target' => 'player_1', 'field' => 'morale',      'operator' => 'add', 'value' => 4],
                                ['target' => 'pair',     'field' => 'relationship', 'operator' => 'add', 'value' => 8],
                            ],
                            'manager_shift' => ['temperament' => 0, 'discipline' => 0, 'ambition' => 2],
                        ],
                    ],
                ],
                'firingConditions' => [
                    'minPairRelationship'    => 30,
                    'actorTraitRequirements' => [
                        ['trait' => 'loyalty',  'max' => 8],
                        ['trait' => 'ambition', 'min' => 14],
                    ],
                ],
            ],
            [
                'slug'             => 'dressing-room-veteran-intervention',
                'category'         => EventCategory::NPC_INTERACTION,
                'weight'           => 2,
                'title'            => 'Players-Only Meeting',
                'bodyTemplate'     => '{player_1} has called a players-only meeting to clear the air after a poor run of form, with {player_2} among those pulled aside for a one-to-one.',
                'severity'         => 'major',
                'impacts'          => [
                    'choices' => [
                        [
                            'emoji'         => '🤝',
                            'label'         => 'Let the squad handle it internally',
                            'stat_changes'  => [
                                ['target' => 'pair',       'field' => 'relationship', 'operator' => 'add', 'value' => 20],
                                ['target' => 'squad_wide', 'field' => 'morale',       'operator' => 'add', 'value' => 8],
                            ],
                            'manager_shift' => ['temperament' => 0, 'discipline' => 0, 'ambition' => 0],
                        ],
                        [
                            'emoji'         => '👔',
                            'label'         => 'Intervene directly as chairman',
                            'stat_changes'  => [
                                ['target' => 'player_1',   'field' => 'morale', 'operator' => 'subtract', 'value' => 5],
                                ['target' => 'squad_wide', 'field' => 'morale', 'operator' => 'add',      'value' => 3],
                            ],
                            'manager_shift' => ['temperament' => -2, 'discipline' => 0, 'ambition' => 0],
                        ],
                    ],
                ],
                'firingConditions' => [
                    'maxSquadMorale'          => 45,
                    'actorTraitRequirements' => [
                        ['trait' => 'determination', 'min' => 14],
                    ],
                ],
            ],
        ];
    }
}
