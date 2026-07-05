<?php

namespace App\Command;

use App\Entity\SocialPostTemplate;
use App\Enum\SocialPlatform;
use App\Enum\StatCategory;
use App\Enum\StatsPeriod;
use App\Repository\SocialPostTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-social-post-templates',
    description: 'Seeds default social post templates for each stat category x platform (idempotent).',
)]
class SeedSocialPostTemplatesCommand extends Command
{
    public function __construct(
        private readonly SocialPostTemplateRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;

        foreach ($this->defaults() as [$category, $platform, $period, $body]) {
            if ($this->repository->findByCategoryAndPlatform($category, $platform) !== null) {
                continue;
            }

            $this->em->persist(new SocialPostTemplate($category, $platform, $period, $body));
            $created++;
        }

        $this->em->flush();
        $io->success("Seeded {$created} new social post template(s).");

        return Command::SUCCESS;
    }

    /** @return array<int, array{0: StatCategory, 1: SocialPlatform, 2: StatsPeriod, 3: string}> */
    private function defaults(): array
    {
        return [
            [StatCategory::MOST_TRANSFERS, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                '🔄 {{clubName}} leads the Wunderkind community in transfer activity this {{period}} with {{value}} transfers! Who\'s building the biggest squad?'],
            [StatCategory::MOST_TRANSFERS, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                '🔄 {{clubName}} tops the transfer charts this {{period}} — {{value}} deals done! #Wunderkind'],
            [StatCategory::MOST_DEVELOPMENT, SocialPlatform::FACEBOOK, StatsPeriod::WEEK,
                '📈 {{clubName}} has been the most improved side in the community this {{period}}, gaining {{value}} development points across the squad!'],
            [StatCategory::MOST_DEVELOPMENT, SocialPlatform::TWITTER, StatsPeriod::WEEK,
                '📈 {{clubName}} gained {{value}} development points this {{period}} — most improved side in the community! #Wunderkind'],
            [StatCategory::MOST_SEASONS, SocialPlatform::FACEBOOK, StatsPeriod::ALL,
                '🏟️ {{clubName}} has now completed {{value}} seasons — the longest-running club in the Wunderkind community!'],
            [StatCategory::MOST_SEASONS, SocialPlatform::TWITTER, StatsPeriod::ALL,
                '🏟️ {{clubName}} has completed {{value}} seasons — longest-running club in the community! #Wunderkind'],
            [StatCategory::MOST_TROPHIES, SocialPlatform::FACEBOOK, StatsPeriod::ALL,
                '🏆 {{clubName}} has won {{value}} league titles — the most decorated club in the Wunderkind community!'],
            [StatCategory::MOST_TROPHIES, SocialPlatform::TWITTER, StatsPeriod::ALL,
                '🏆 {{clubName}} has won {{value}} titles — most decorated club in the community! #Wunderkind'],
        ];
    }
}
