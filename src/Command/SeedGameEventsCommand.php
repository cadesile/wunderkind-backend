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
                'category'     => EventCategory::STAFF,
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
        ];
    }
}
