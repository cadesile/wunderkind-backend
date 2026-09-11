<?php

namespace App\Command;

use App\Enum\EventCategory;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'app:seed:morale-events',
    description: 'Seeds morale-based event templates (skips existing slugs; pass --update to overwrite them).',
)]
class SeedMoraleEventsCommand extends AbstractSeedEventTemplatesCommand
{
    protected function templateLabel(): string
    {
        return 'morale event';
    }

    /**
     * @return array<int, array{slug: string, category: EventCategory, weight: int, title: string, bodyTemplate: string, impacts: array, firingConditions?: array, severity?: string}>
     */
    protected function buildTemplates(): array
    {
        return [

            // ── PLAYER_MORALE — individual player conditions ──────────────────

            [
                'slug'             => 'player_low_morale_seeks_exit',
                'category'         => EventCategory::PLAYER_MORALE,
                'weight'           => 2,
                'title'            => 'Player Seeking Exit',
                'bodyTemplate'     => '{player_name} is deeply unhappy at the club. Sources suggest they are actively seeking a move away.',
                'firingConditions' => [
                    'subject'     => 'player',
                    'morale_below' => 25,
                ],
                'impacts'  => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.loyalty', 'operator' => 'subtract', 'value' => 2],
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'subtract', 'value' => 3],
                    ],
                ],
                'severity' => 'major',
            ],

            [
                'slug'             => 'player_high_morale_extension',
                'category'         => EventCategory::PLAYER_MORALE,
                'weight'           => 3,
                'title'            => 'Player Keen to Stay',
                'bodyTemplate'     => '{player_name} has expressed a strong desire to extend their time at the club. Their happiness is evident in training.',
                'firingConditions' => [
                    'subject'      => 'player',
                    'morale_above' => 82,
                ],
                'impacts'  => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.loyalty', 'operator' => 'add', 'value' => 1],
                        ['target' => 'player_1', 'field' => 'morale', 'operator' => 'add', 'value' => 2],
                    ],
                ],
                'severity' => 'minor',
            ],

            [
                'slug'             => 'player_morale_range_consistent',
                'category'         => EventCategory::PLAYER_MORALE,
                'weight'           => 4,
                'title'            => 'Settled and Focused',
                'bodyTemplate'     => '{player_name} appears settled and focused. They are getting on with their work without complaint.',
                'firingConditions' => [
                    'subject'      => 'player',
                    'morale_above' => 55,
                    'morale_below' => 80,
                ],
                'impacts'  => [
                    'stat_changes' => [
                        ['target' => 'player_1', 'field' => 'personality.consistency', 'operator' => 'add', 'value' => 1],
                    ],
                ],
                'severity' => 'minor',
            ],

            // ── PLAYER_MORALE — staff morale conditions ───────────────────────

            [
                'slug'             => 'coach_low_morale_performance',
                'category'         => EventCategory::PLAYER_MORALE,
                'weight'           => 2,
                'title'            => 'Staff Unhappiness Affecting Training',
                'bodyTemplate'     => 'Low morale among coaching staff is beginning to affect the quality of sessions. Players are noticing the tension.',
                'firingConditions' => [
                    'subject'      => 'staff',
                    'staff_role'   => 'coach',
                    'morale_below' => 30,
                ],
                'impacts'  => [
                    'stat_changes' => [
                        ['target' => 'squad.morale', 'field' => 'morale', 'operator' => 'subtract', 'value' => 2],
                    ],
                ],
                'severity' => 'minor',
            ],

            [
                'slug'             => 'dof_low_morale_signings',
                'category'         => EventCategory::PLAYER_MORALE,
                'weight'           => 2,
                'title'            => 'DOF Dissatisfied',
                'bodyTemplate'     => '{staff_name} appears disengaged. Their recruitment focus may be suffering as a result.',
                'firingConditions' => [
                    'subject'      => 'staff',
                    'staff_role'   => 'director_of_football',
                    'morale_below' => 35,
                ],
                'impacts'  => [
                    'stat_changes' => [
                        ['target' => 'staff.morale', 'field' => 'morale', 'operator' => 'subtract', 'value' => 2],
                    ],
                ],
                'severity' => 'minor',
            ],
        ];
    }
}
