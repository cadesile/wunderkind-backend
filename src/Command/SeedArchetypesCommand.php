<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PlayerArchetype;
use App\Repository\PlayerArchetypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-archetypes',
    description: 'Seeds initial player archetype definitions (idempotent — skips existing names).',
)]
class SeedArchetypesCommand extends Command
{
    public function __construct(
        private readonly PlayerArchetypeRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $archetypes = $this->buildArchetypes();
        $created    = 0;
        $skipped    = 0;

        foreach ($archetypes as $data) {
            if ($this->repository->findByName($data['name']) !== null) {
                $io->note("Skipping existing archetype: {$data['name']}");
                $skipped++;
                continue;
            }

            $archetype = new PlayerArchetype(
                $data['name'],
                $data['description'],
                $data['traitMapping'],
            );

            $this->em->persist($archetype);
            $created++;
        }

        $this->em->flush();

        $io->success("Seeded {$created} archetype(s). Skipped {$skipped} existing.");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{name: string, description: string, traitMapping: array}>
     */
    private function buildArchetypes(): array
    {
        return [
            [
                'name'        => 'The Captain',
                'description' => 'A natural leader who rallies the squad and commands respect on and off the pitch. Coaches trust him with the armband.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'leadership',  'min' => 70],
                        ['trait' => 'teamwork',    'min' => 65],
                        ['trait' => 'confidence',  'min' => 60],
                    ],
                ],
            ],
            [
                'name'        => 'The Maverick',
                'description' => 'Unpredictable and infuriating — but capable of moments of pure genius. Wins games on his own, loses them the same way.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'ego',      'min' => 70],
                        ['trait' => 'teamwork', 'max' => 40],
                        ['trait' => 'bravery',  'min' => 65],
                    ],
                ],
            ],
            [
                'name'        => 'The Grinder',
                'description' => 'No talent? No problem. Outworks everyone, never complains, and earns every yard through sheer determination.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'maturity', 'min' => 70],
                        ['trait' => 'bravery',  'min' => 65],
                        ['trait' => 'ego',      'max' => 40],
                    ],
                ],
            ],
            [
                'name'        => 'The Mercenary',
                'description' => 'Talent for sale to the highest bidder. Performs brilliantly — right up until a better offer lands in his agent\'s inbox.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'greed',   'min' => 70],
                        ['trait' => 'loyalty', 'max' => 35],
                    ],
                ],
            ],
            [
                'name'        => 'The Homebody',
                'description' => 'Devoted to the academy that gave him a chance. Turns down bigger clubs, takes pay cuts, becomes a legend.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'loyalty',    'min' => 75],
                        ['trait' => 'greed',      'max' => 35],
                        ['trait' => 'ego',        'max' => 40],
                    ],
                ],
            ],
            [
                'name'        => 'The Prodigy',
                'description' => 'Everyone knows it. He knows it most of all. The sky\'s the limit — if the ego doesn\'t get in the way first.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'confidence', 'min' => 75],
                        ['trait' => 'ego',        'min' => 65],
                    ],
                ],
            ],
            [
                'name'        => 'The Team Player',
                'description' => 'Makes everyone around him better. Rarely the star, always essential. The glue that holds a title-winning squad together.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'teamwork', 'min' => 75],
                        ['trait' => 'ego',      'max' => 35],
                        ['trait' => 'loyalty',  'min' => 60],
                    ],
                ],
            ],
            [
                'name'        => 'The Entertainer',
                'description' => 'Showboating, flair, and a hunger for the big moment. Fans love him. Defenders hate him. Results are... mixed.',
                'traitMapping' => [
                    'threshold' => 'all',
                    'rules'     => [
                        ['trait' => 'confidence', 'min' => 70],
                        ['trait' => 'ego',        'min' => 60],
                        ['trait' => 'bravery',    'min' => 65],
                    ],
                ],
            ],
        ];
    }
}
