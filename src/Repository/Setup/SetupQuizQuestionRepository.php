<?php

declare(strict_types=1);

namespace App\Repository\Setup;

use App\Entity\Setup\SetupQuizQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SetupQuizQuestion>
 */
class SetupQuizQuestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SetupQuizQuestion::class);
    }

    /**
     * The questions a client may be served, in display order.
     *
     * FILTERS OUT UNANSWERABLE ROWS IN PHP, not SQL: "correctIndex points at an option that
     * exists" is a fact about a json array's length and is not expressible as a portable index
     * predicate. The set is a handful of rows, so the cost is nothing and the guarantee is
     * worth more — a misconfigured question served to a player is one nobody can answer
     * correctly, which silently lowers every bonus paid while it is live.
     *
     * ORDERED DETERMINISTICALLY, id as the final tie-break. The score is a fraction of this
     * set's size, so two readers disagreeing on the set would disagree on the payout; an
     * unordered read with equal sortOrder values is exactly the tie-break bug the location
     * classification work had to fix.
     *
     * @return list<SetupQuizQuestion>
     */
    public function findServable(): array
    {
        /** @var list<SetupQuizQuestion> $rows */
        $rows = $this->createQueryBuilder('q')
            ->where('q.isActive = true')
            ->orderBy('q.sortOrder', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($rows, static fn (SetupQuizQuestion $q): bool => $q->isAnswerable()));
    }
}
