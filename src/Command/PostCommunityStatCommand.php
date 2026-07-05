<?php

namespace App\Command;

use App\Enum\StatCategory;
use App\Exception\SocialPostingException;
use App\Repository\GameConfigRepository;
use App\Repository\SocialAccountConnectionRepository;
use App\Repository\SocialPostTemplateRepository;
use App\Service\SocialPostRenderer;
use App\Service\SocialPostingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:post-community-stat',
    description: 'Posts the next stat category (round-robin) to all active social connections.',
)]
class PostCommunityStatCommand extends Command
{
    public function __construct(
        private readonly GameConfigRepository $gameConfigRepository,
        private readonly SocialPostTemplateRepository $templateRepository,
        private readonly SocialAccountConnectionRepository $connectionRepository,
        private readonly SocialPostRenderer $renderer,
        private readonly SocialPostingService $postingService,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config   = $this->gameConfigRepository->getConfig(flush: true);
        $category = $this->nextCategory($config->getLastPostedStatCategory());
        $io->section("Selected category: {$category->value}");

        $connections = $this->connectionRepository->findAllActive();
        if (empty($connections)) {
            $io->warning('No active social account connections — nothing to post. Rotation state NOT advanced.');
            return Command::SUCCESS;
        }

        $anySuccess = false;
        $anyFailure = false;

        foreach ($connections as $connection) {
            $template = $this->templateRepository->findActiveByCategoryAndPlatform($category, $connection->getPlatform());
            if ($template === null) {
                $io->warning("No active template for {$category->value}/{$connection->getPlatform()->value} — skipping connection {$connection->getDisplayName()}.");
                continue;
            }

            $text = $this->renderer->render($template);
            if ($text === null) {
                $io->note("No leaderboard data for {$category->value} — nothing to post this run.");
                // Not a failure — an empty leaderboard is valid. Counts as
                // "handled" so rotation still advances; otherwise a
                // persistently-empty category would starve the other 3.
                $anySuccess = true;
                continue;
            }

            try {
                $this->postingService->post($connection, $text);
                $io->writeln("Posted to {$connection->getPlatform()->value}/{$connection->getDisplayName()}.");
                $anySuccess = true;
            } catch (SocialPostingException $e) {
                $io->error("Failed to post to {$connection->getPlatform()->value}/{$connection->getDisplayName()}: {$e->getMessage()}");
                $anyFailure = true;
            }
        }

        $config->setLastPostedStatCategory($category);
        $this->em->flush();

        if ($anyFailure && !$anySuccess) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function nextCategory(?StatCategory $last): StatCategory
    {
        $cases = StatCategory::cases();
        $lastIndex = $last !== null ? array_search($last, $cases, true) : null;
        $nextIndex = $lastIndex === null ? 0 : ($lastIndex + 1) % count($cases);
        return $cases[$nextIndex];
    }
}
