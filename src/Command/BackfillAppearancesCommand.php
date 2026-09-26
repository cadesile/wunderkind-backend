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
 *
 * Both passes iterate via Doctrine's `toIterable()` and periodically clear the
 * EntityManager rather than loading the whole result set at once — this
 * command previously OOM'd production on ~36.5k rows via a single findBy()
 * and is deliberately excluded from the post-deploy sequence for that reason
 * (see docs/deploy/hetzner.md); run it manually.
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
            'force',
            null,
            InputOption::VALUE_NONE,
            'Also regenerate appearance on rows that already have one, overwriting it entirely. '
            . 'Use this once after a change to the Appearance shape, to migrate already-persisted '
            . 'rows onto the new fields.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        foreach ([Player::class, Staff::class, Scout::class, Agent::class] as $class) {
            $n = $this->iterate(
                $class,
                ['appearance' => null],
                fn (object $entity) => $this->filler->fill($entity),
            );
            $io->text(sprintf('%s: %d row(s) backfilled', $class, $n));

            if (!$force) {
                continue;
            }

            $changed = $this->iterate(
                $class,
                [],
                fn (object $entity) => $this->filler->regenerate($entity),
            );
            $io->text(sprintf('%s: %d row(s) regenerated', $class, $changed));
        }

        $io->success('Appearance backfill complete.');
        return Command::SUCCESS;
    }

    /**
     * Streams rows of $class matching $criteria via toIterable(), applying
     * $action to each and flushing/clearing every BATCH_SIZE rows so memory
     * stays flat regardless of table size. Every iterated row already matches
     * $criteria (a valid appearance-bearing subclass), so the returned count
     * is simply how many rows were processed.
     *
     * @param class-string           $class
     * @param array<string,mixed>    $criteria
     * @param callable(object):mixed $action
     */
    private function iterate(string $class, array $criteria, callable $action): int
    {
        $qb = $this->em->getRepository($class)->createQueryBuilder('e');
        foreach ($criteria as $field => $value) {
            if ($value === null) {
                $qb->andWhere("e.$field IS NULL");
            } else {
                $qb->andWhere("e.$field = :$field")->setParameter($field, $value);
            }
        }

        $n = 0;
        foreach ($qb->getQuery()->toIterable() as $entity) {
            $action($entity);
            if (++$n % self::BATCH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();
        $this->em->clear();

        return $n;
    }
}
