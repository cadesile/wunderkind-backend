<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Excursion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-excursions',
    description: 'Seeds the excursion catalogue (upserts by slug - safe to re-run; preserves uploaded images).',
)]
class SeedExcursionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $repo = $this->em->getRepository(Excursion::class);
        $created = 0;
        $updated = 0;

        foreach ($this->buildExcursions() as $data) {
            // Upsert rather than truncate: an admin may have uploaded artwork
            // against these slugs, and a delete-then-insert would orphan it.
            $excursion = $repo->findOneBy(['slug' => $data['slug']]);
            if ($excursion === null) {
                $excursion = new Excursion($data['slug'], $data['title'], $data['body']);
                $this->em->persist($excursion);
                $created++;
            } else {
                $excursion->setTitle($data['title']);
                $excursion->setBody($data['body']);
                $updated++;
            }

            $excursion->setCostPerPersonPence($data['costPerPersonPence']);
            $excursion->setEffectValue($data['effectValue']);
            $excursion->setNegativeFrequency($data['negativeFrequency']);
            $excursion->setTargetAudience($data['targetAudience']);
            $excursion->setPostSeasonOnly($data['postSeasonOnly']);
            $excursion->setCooldownWeeks($data['cooldownWeeks']);
            $excursion->setActive(true);
        }

        $this->em->flush();
        $io->success(sprintf('Excursions seeded — %d created, %d updated.', $created, $updated));

        return Command::SUCCESS;
    }

    /**
     * The catalogue. Mirrors DEFAULT_EXCURSIONS in the app
     * (src/constants/excursions.ts) - keep the two in step, since that array is
     * the offline fallback shown whenever this API is unreachable.
     *
     * Costs are PER ATTENDEE in pence and are multiplied by headcount in-app.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildExcursions(): array
    {
        return [
            // Tier 1: Zero / Micro Cost
            [
                'slug'               => 'local-community-initiative',
                'title'              => 'Local Community Initiative',
                'body'               => 'Organise a low-cost, grassroots community activity to ground the team and build public goodwill. Keeps everyone humble, though high-profile members may feel out of their element.',
                'costPerPersonPence' => 0,
                'effectValue'        => 15,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 2,
            ],
            [
                'slug'               => 'casual-group-meal',
                'title'              => 'Casual Group Meal',
                'body'               => 'A quick, low-budget informal meal together after work. Great for an immediate, cheap morale boost, even if nutritionists or high-performance staff disapprove.',
                'costPerPersonPence' => 50000,
                'effectValue'        => 22,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 2,
            ],

            // Tier 2: Low-Budget Socials
            [
                'slug'               => 'local-social-night',
                'title'              => 'Local Social Night',
                'body'               => 'A relaxed evening out at a local venue for light entertainment and socialising. Tensions can rise occasionally during overly competitive games.',
                'costPerPersonPence' => 150000,
                'effectValue'        => 32,
                'negativeFrequency'  => 3,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 3,
            ],
            [
                'slug'               => 'low-intensity-group-activity',
                'title'              => 'Low-Intensity Group Activity',
                'body'               => 'An easy-going, accessible outing focused on rest and distraction rather than physical effort. Highly safe and calm, though less impactful for intense bond-building.',
                'costPerPersonPence' => 250000,
                'effectValue'        => 35,
                'negativeFrequency'  => 1,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 3,
            ],

            // Tier 3: Mid-Range Team Building
            [
                'slug'               => 'collaborative-problem-solving',
                'title'              => 'Group Problem-Solving Challenge',
                'body'               => 'Put small teams into structured, high-pressure puzzle environments. Highlights natural leaders and tests communication, though headstrong personalities can easily clash.',
                'costPerPersonPence' => 500000,
                'effectValue'        => 50,
                'negativeFrequency'  => 5,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 4,
            ],
            [
                'slug'               => 'competitive-action-outing',
                'title'              => 'Competitive Action Outing',
                'body'               => 'A fast-paced, high-energy activity designed to spark competitive spirit and adrenaline. High team-bonding upside, but carries a slight risk of minor friction or injuries.',
                'costPerPersonPence' => 1000000,
                'effectValue'        => 65,
                'negativeFrequency'  => 6,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 6,
            ],

            // Tier 4: High-End Premium Experiences
            [
                'slug'               => 'overnight-outdoor-expedition',
                'title'              => 'Outdoor Survival & Endurance Expedition',
                'body'               => 'Take the squad into challenging wild terrain for physical tests and group survival tasks. Builds exceptional resilience and unity, though it physically exhausts participants.',
                'costPerPersonPence' => 2500000,
                'effectValue'        => 75,
                'negativeFrequency'  => 5,
                'targetAudience'     => 'players',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 8,
            ],
            [
                'slug'               => 'exclusive-private-hospitality',
                'title'              => 'VIP Private Entertainment Experience',
                'body'               => 'Book out an exclusive venue with top-tier catering and privacy. Breaks down sub-groups and cliques effectively, but high-alcohol environments risk off-field indiscretions.',
                'costPerPersonPence' => 5000000,
                'effectValue'        => 82,
                'negativeFrequency'  => 7,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => false,
                'cooldownWeeks'      => 8,
            ],

            // Tier 5: Major Capital & Luxury Trips
            [
                'slug'               => 'short-haul-warm-weather-camp',
                'title'              => 'Short-Haul Warm Weather Training Camp',
                'body'               => 'Fly the squad out to a nearby international resort for intensive training, warm weather, and bonding. Ideal for post-season recovery or pre-season preparation.',
                'costPerPersonPence' => 25000000,
                'effectValue'        => 88,
                'negativeFrequency'  => 4,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => true,
                'cooldownWeeks'      => 24,
            ],
            [
                'slug'               => 'ultra-luxury-global-retreat',
                'title'              => 'Ultra-Luxury International Retreat',
                'body'               => 'An expense-spared, five-star international getaway offering private transport, world-class amenities, and complete relaxation. Guarantees maximum satisfaction and squad harmony.',
                'costPerPersonPence' => 1000000000,
                'effectValue'        => 98,
                'negativeFrequency'  => 2,
                'targetAudience'     => 'both',
                'postSeasonOnly'     => true,
                'cooldownWeeks'      => 52,
            ],
        ];
    }
}
