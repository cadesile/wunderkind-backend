<?php

declare(strict_types=1);

namespace App\Repository\Setup;

use App\Entity\Setup\SetupQuizAnswer;
use App\Entity\Setup\SetupQuizQuestion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SetupQuizAnswer>
 */
class SetupQuizAnswerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SetupQuizAnswer::class);
    }

    /**
     * Every answer this account has given, keyed by question id.
     *
     * One query for the whole set rather than a lookup per question: GET /api/setup-quiz
     * renders every question with its answered state, and a per-question check would be an
     * N+1 on the one screen a brand-new account cannot skip.
     *
     * @return array<string, SetupQuizAnswer>
     */
    public function findForUserKeyedByQuestion(User $user): array
    {
        /** @var list<SetupQuizAnswer> $rows */
        $rows = $this->createQueryBuilder('a')
            ->addSelect('q')
            ->join('a.question', 'q')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->getQuestion()->getId()] = $row;
        }

        return $out;
    }

    public function findOneForUserAndQuestion(User $user, SetupQuizQuestion $question): ?SetupQuizAnswer
    {
        return $this->findOneBy(['user' => $user, 'question' => $question]);
    }
}
