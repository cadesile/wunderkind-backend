<?php
namespace App\Command;

use App\Entity\Agent;
use App\Entity\Player;
use App\Entity\Scout;
use App\Entity\Staff;
use App\EventSubscriber\AppearanceLifecycleSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills `appearance` for existing Player/Staff/Scout/Agent pool rows
 * created before AppearanceLifecycleSubscriber existed. Reuses the exact
 * same fill() logic as the prePersist subscriber so backfilled rows are
 * generated identically to freshly created ones.
 */
#[AsCommand(name: 'app:backfill-appearances', description: 'Generate appearance for existing pool rows that lack one')]
final class BackfillAppearancesCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppearanceLifecycleSubscriber $filler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'regenerate-skin-tone',
            null,
            InputOption::VALUE_NONE,
            'Also recompute skinTone on rows that already have an appearance, applying the '
            . 'region-weighted distribution. Leaves the other nine appearance fields untouched.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $regenerate   = (bool) $input->getOption('regenerate-skin-tone');

        foreach ([Player::class, Staff::class, Scout::class, Agent::class] as $class) {
            $rows = $this->em->getRepository($class)->findBy(['appearance' => null]);
            $io->text(sprintf('%s: %d row(s) to backfill', $class, count($rows)));
            $n = 0;
            foreach ($rows as $entity) {
                $this->filler->fill($entity);
                if (++$n % self::BATCH_SIZE === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();

            if (!$regenerate) {
                continue;
            }

            // Re-fetch: the rows just filled above are already correct, but the
            // pre-existing ones still carry a uniformly-picked tone.
            $existing = $this->em->getRepository($class)->findAll();
            $changed  = 0;
            $n        = 0;
            foreach ($existing as $entity) {
                if ($this->filler->refreshSkinTone($entity)) {
                    $changed++;
                }
                if (++$n % self::BATCH_SIZE === 0) {
                    $this->em->flush();
                }
            }
            $this->em->flush();
            $io->text(sprintf('%s: %d skin tone(s) regenerated', $class, $changed));
        }

        $io->success('Appearance backfill complete.');
        return Command::SUCCESS;
    }
}
