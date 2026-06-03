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
    name: 'app:seed:morale-events',
    description: 'Seeds morale-based event templates (idempotent — skips existing slugs).',
)]
class SeedMoraleEventsCommand extends Command
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

        $io->success("Seeded {$created} morale event template(s). Skipped {$skipped} existing.");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{slug: string, category: EventCategory, weight: int, title: string, bodyTemplate: string, impacts: array, firingConditions?: array, severity?: string}>
     */
    private function buildTemplates(): array
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
                    ['target' => 'player.personality.loyalty', 'delta' => -2],
                    ['target' => 'player.morale',              'delta' => -3],
                ],
                'severity' => 'high',
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
                    ['target' => 'player.personality.loyalty', 'delta' => 1],
                    ['target' => 'player.morale',              'delta' => 2],
                ],
                'severity' => 'low',
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
                    ['target' => 'player.personality.consistency', 'delta' => 1],
                ],
                'severity' => 'low',
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
                    ['target' => 'squad.morale', 'delta' => -2],
                ],
                'severity' => 'medium',
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
                    ['target' => 'staff.morale', 'delta' => -2],
                ],
                'severity' => 'medium',
            ],
        ];
    }
}
