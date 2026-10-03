<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Language;
use App\Repository\LanguageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bootstraps the `language` table with a single enabled, default `en` row.
 *
 * Deliberately NOT an upsert-by-code like app:seed-excursions: this only acts when the
 * table is completely empty. An upsert-on-every-deploy model would permanently stomp an
 * admin's later choice of a different default language — so once any Language row exists,
 * this command is a permanent no-op.
 */
#[AsCommand(
    name: 'app:seed-default-language',
    description: 'Creates the initial enabled/default "en" Language row. No-op once any Language row exists.',
)]
class SeedDefaultLanguageCommand extends Command
{
    public function __construct(
        private readonly LanguageRepository     $languageRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->languageRepository->count([]) > 0) {
            $io->note('Languages already configured, skipping.');
            return Command::SUCCESS;
        }

        $language = new Language('en', 'English');
        $language->setIsEnabled(true);
        $language->setIsDefault(true);
        $this->em->persist($language);
        $this->em->flush();

        $io->success('Created the default "en" language.');

        return Command::SUCCESS;
    }
}
