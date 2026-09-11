<?php

namespace App\Command;

use App\Enum\EventCategory;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'app:seed-player-events',
    description: 'Seeds player-state-driven event templates (skips existing slugs; pass --update to overwrite them).',
)]
class SeedPlayerEventsCommand extends AbstractSeedEventTemplatesCommand
{
    protected function templateLabel(): string
    {
        return 'player event';
    }

    /**
     * @return array<int, array{slug: string, category: EventCategory, weight: int, title: string, bodyTemplate: string, impacts: array, firingConditions?: array, severity?: string}>
     */
    protected function buildTemplates(): array
    {
        return [

            // ── PLAYER_REPUTATION ─────────────────────────────────────────────

            [
                'slug'             => 'reputation_breakthrough',
                'category'         => EventCategory::PLAYER_REPUTATION,
                'weight'           => 3,
                'title'            => 'Rising Star',
                'bodyTemplate'     => '{player_name} is attracting attention from across the country. Their performances have not gone unnoticed — scouts from bigger clubs are taking note.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 5],
                    ],
                ],
                'firingConditions' => [
                    'reputation_tier'      => 'Recognised',
                    'reputation_direction' => 'up',
                ],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'reputation_elite',
                'category'         => EventCategory::PLAYER_REPUTATION,
                'weight'           => 2,
                'title'            => 'Elite Status',
                'bodyTemplate'     => '{player_name} has established themselves as one of the finest players at this level. Their reputation speaks for itself.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 8],
                    ],
                ],
                'firingConditions' => [
                    'reputation_tier'      => 'Elite',
                    'reputation_direction' => 'up',
                ],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'reputation_fading',
                'category'         => EventCategory::PLAYER_REPUTATION,
                'weight'           => 2,
                'title'            => 'Fading Star',
                'bodyTemplate'     => '{player_name} seems to have lost some of their earlier lustre. Former admirers are looking elsewhere.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 4],
                    ],
                ],
                'firingConditions' => [
                    'reputation_tier'      => 'Promising',
                    'reputation_direction' => 'down',
                ],
                'severity'         => 'minor',
            ],

            // ── PLAYER_MILESTONE ──────────────────────────────────────────────

            [
                'slug'             => 'age_milestone_18',
                'category'         => EventCategory::PLAYER_MILESTONE,
                'weight'           => 5,
                'title'            => 'Coming of Age',
                'bodyTemplate'     => '{player_name} turns 18 this week — a pivotal moment in any young player\'s development. The hard work starts now.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.ambition', 'operator' => 'add', 'value' => 1],
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 3],
                    ],
                ],
                'firingConditions' => [
                    'age_milestone' => 18,
                ],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'age_milestone_21',
                'category'         => EventCategory::PLAYER_MILESTONE,
                'weight'           => 4,
                'title'            => 'Hitting Their Stride',
                'bodyTemplate'     => '{player_name} is 21 — the age when most players begin to realise their potential. The next few seasons will define their career.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.determination', 'operator' => 'add', 'value' => 1],
                    ],
                ],
                'firingConditions' => [
                    'age_milestone' => 21,
                ],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'age_milestone_30',
                'category'         => EventCategory::PLAYER_MILESTONE,
                'weight'           => 4,
                'title'            => 'Entering the Veteran Years',
                'bodyTemplate'     => '{player_name} has turned 30. Experience and leadership become their greatest assets now. The physical peak may be behind them, but the best decisions are ahead.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.consistency', 'operator' => 'add', 'value' => 1],
                        ['target' => 'player_1', 'field' => 'personality.professionalism', 'operator' => 'add', 'value' => 1],
                    ],
                ],
                'firingConditions' => [
                    'age_milestone' => 30,
                ],
                'severity'         => 'minor',
            ],

            // ── PLAYER_MORALE ─────────────────────────────────────────────────

            [
                'slug'             => 'morale_crisis',
                'category'         => EventCategory::PLAYER_MORALE,
                'weight'           => 3,
                'title'            => 'Unsettled',
                'bodyTemplate'     => '{player_name} is reported to be deeply unhappy at the club. Sources close to the player suggest they are considering their options.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.loyalty', 'operator' => 'subtract', 'value' => 1],
                    ],
                ],
                'firingConditions' => [
                    'morale_threshold' => 20,
                    'morale_direction' => 'below',
                ],
                'severity'         => 'major',
            ],
            [
                'slug'             => 'morale_surge',
                'category'         => EventCategory::PLAYER_MORALE,
                'weight'           => 3,
                'title'            => 'Rejuvenated',
                'bodyTemplate'     => '{player_name} looks like a new player. High spirits in the dressing room seem to be bringing out the best in them.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.consistency', 'operator' => 'add', 'value' => 1],
                    ],
                ],
                'firingConditions' => [
                    'morale_threshold' => 85,
                    'morale_direction' => 'above',
                ],
                'severity'         => 'minor',
            ],

            // ── PLAYER_FORM ───────────────────────────────────────────────────

            [
                'slug'             => 'form_hot_streak',
                'category'         => EventCategory::PLAYER_FORM,
                'weight'           => 3,
                'title'            => 'In the Zone',
                'bodyTemplate'     => '{player_name} is in the form of their life. {consecutive_good_games} consecutive strong performances — they look unstoppable right now.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 5],
                    ],
                ],
                'firingConditions' => [
                    'consecutive_good_games' => 4,
                ],
                'severity'         => 'minor',
            ],
            [
                'slug'             => 'form_cold_streak',
                'category'         => EventCategory::PLAYER_FORM,
                'weight'           => 3,
                'title'            => 'Loss of Form',
                'bodyTemplate'     => '{player_name} is going through a difficult patch. {consecutive_poor_games} games below par — confidence appears to be an issue.',
                'impacts'          => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 5],
                        ['target' => 'player_1', 'field' => 'personality.pressure', 'operator' => 'subtract', 'value' => 1],
                    ],
                ],
                'firingConditions' => [
                    'consecutive_poor_games' => 3,
                ],
                'severity'         => 'minor',
            ],
        ];
    }
}
