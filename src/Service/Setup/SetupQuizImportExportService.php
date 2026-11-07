<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\Setup\SetupQuizQuestion;
use App\Repository\Setup\SetupQuizQuestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Moving the onboarding quiz between environments, and authoring it from a file.
 *
 * TWO JOBS, AND THE SECOND IS THE COMMON ONE. A quiz question is authored prose with a right
 * answer; writing twenty of them through a CRUD form is slow and reviewing them that way is
 * worse. So the import accepts a HAND-WRITTEN file as readily as a round-tripped export: `id`
 * is optional, and a file that is just a list of prompts and options works.
 *
 * MATCHED ON ID, WHICH IS THE OPPOSITE OF LocationImportExportService — and the contrast is
 * the point. Locations are SEEDED independently in every environment from a registry, so their
 * uuids genuinely diverge and matching on them would mean a file that only ever imports into
 * the database it came from; that service matches (countryCode, slug) for exactly that reason.
 * Quiz questions are AUTHORED once and distributed, so the id is the only stable identity they
 * have — there is no slug, no unique constraint, and the prompt is editable text. Matching on
 * the prompt would mean that correcting a typo and re-importing produced a duplicate rather
 * than an edit, which is the opposite of what an author wants.
 *
 * So ids are preserved on insert, deliberately: it is what makes two environments converge on
 * the same identities instead of drifting apart with every import.
 *
 * IT NEVER DELETES, and here that is sharper than usual. `setup_quiz_answer.question_id` is
 * ON DELETE **CASCADE**, so deleting a question does not fail loudly — it silently takes every
 * player's answer to it with it. That would destroy the "one attempt per account, ever"
 * enforcement those rows ARE (see SetupQuizAnswer), letting an account retake the quiz and be
 * paid a second bonus. The entity says deactivating is the only safe retirement; this agrees,
 * and `deactivateMissing` is the strongest withdrawal on offer.
 *
 * THE FILE CONTAINS THE ANSWER KEY. `correctIndex` is server-only and never serialised to a
 * client — SetupQuizQuestion::toClientArray() exists so no controller can leak it — but an
 * export is a server-to-server artefact and must carry it, or the imported quiz would pay
 * everyone for question one. Unlike the config export, this file is therefore NOT safe to
 * commit to a public repository or hand to a player: the quiz multiplies a player's opening
 * credit by how much they got right.
 *
 * WHAT IS DELIBERATELY NOT EXPORTED: setup_quiz_answer. It is per-account player data keyed to
 * a user, uniquely constrained per (user, question), and cascade-deleted with the account.
 * Carrying it between environments would be carrying somebody's game history — and importing
 * it would fabricate attempts that were never made, against users who may not exist.
 */
final class SetupQuizImportExportService
{
    private const EXPORT_VERSION = 1;

    /**
     * Fewest options a question may offer.
     *
     * Two. A question with one option has a single answer everyone gets right, which is a
     * payout with no quiz attached.
     */
    private const MIN_OPTIONS = 2;

    public function __construct(
        private readonly SetupQuizQuestionRepository $questionRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    // ── Export ──────────────────────────────────────────────────────────────

    /**
     * Every question, active and withdrawn alike.
     *
     * WITHDRAWN ONES TOO, on purpose. `isActive` is authoring state, and a withdrawn question
     * is a decision somebody made; dropping it from the file would mean importing the file
     * elsewhere silently re-activated nothing but also preserved nothing, and a later export
     * from that environment would have quietly lost the record.
     *
     * @return array{version: int, exportedAt: string, questions: list<array<string, mixed>>}
     */
    public function export(): array
    {
        $questions = $this->questionRepository->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        return [
            'version'    => self::EXPORT_VERSION,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'questions'  => array_map(static fn (SetupQuizQuestion $q): array => [
                'id'           => (string) $q->getId(),
                'prompt'       => $q->getPrompt(),
                'options'      => $q->getOptions(),
                'correctIndex' => $q->getCorrectIndex(),
                'sortOrder'    => $q->getSortOrder(),
                'isActive'     => $q->isActive(),
                'createdAt'    => $q->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $questions),
        ];
    }

    // ── Import ──────────────────────────────────────────────────────────────

    /**
     * Upsert the questions in $data.
     *
     * VALIDATED PER ROW, AND A BAD ROW IS REFUSED RATHER THAN REPAIRED. A question whose
     * correctIndex points past its options can never be answered correctly — the entity calls
     * that failing closed, and findServable() keeps it out of what is served — so importing one
     * would create a question that is invisible in the API and inert in the admin, which is
     * worse than being told the file is wrong. Valid rows still apply; the errors name the
     * ones that did not.
     *
     * @param array<string, mixed> $data
     *
     * @return array{
     *     created: int, updated: int, unchanged: int, deactivated: int, skipped: int,
     *     errors: list<string>, dryRun: bool
     * }
     */
    public function import(array $data, bool $dryRun = false, bool $deactivateMissing = false): array
    {
        $result = [
            'created'     => 0,
            'updated'     => 0,
            'unchanged'   => 0,
            'deactivated' => 0,
            'skipped'     => 0,
            'errors'      => [],
            'dryRun'      => $dryRun,
        ];

        $version = $data['version'] ?? null;

        if ($version !== null && $version !== self::EXPORT_VERSION) {
            $result['errors'][] = \sprintf(
                'File version %s is not supported (expected %d).',
                \is_scalar($version) ? (string) $version : 'unknown',
                self::EXPORT_VERSION,
            );

            return $result;
        }

        $rows = $data['questions'] ?? null;

        if (!\is_array($rows)) {
            $result['errors'][] = 'No "questions" list in the file.';

            return $result;
        }

        /** @var array<string, SetupQuizQuestion> $existing */
        $existing = [];

        foreach ($this->questionRepository->findAll() as $question) {
            $existing[(string) $question->getId()] = $question;
        }

        $seenIds = [];

        foreach (array_values($rows) as $position => $row) {
            $label = \sprintf('question %d', $position + 1);

            if (!\is_array($row)) {
                $result['errors'][] = \sprintf('%s: not an object.', $label);
                ++$result['skipped'];
                continue;
            }

            $parsed = $this->parseRow($row, $label);

            if (isset($parsed['error'])) {
                $result['errors'][] = $parsed['error'];
                ++$result['skipped'];
                continue;
            }

            $id       = $parsed['id'];
            $question = $id !== null ? ($existing[$id] ?? null) : null;

            if ($question === null) {
                // Insert, PRESERVING the file's id when it carried one — that is what makes two
                // environments converge on the same identities rather than drift with every
                // import. A hand-written file with no id simply gets a fresh one.
                $question = $dryRun
                    ? new SetupQuizQuestion()
                    : $this->insert($id, $parsed);

                ++$result['created'];

                if ($id !== null) {
                    $seenIds[$id] = true;
                }

                continue;
            }

            $seenIds[$id] = true;

            if ($this->isUnchanged($question, $parsed)) {
                ++$result['unchanged'];
                continue;
            }

            if (!$dryRun) {
                $question
                    ->setPrompt($parsed['prompt'])
                    ->setOptions($parsed['options'])
                    ->setCorrectIndex($parsed['correctIndex'])
                    ->setSortOrder($parsed['sortOrder'])
                    ->setIsActive($parsed['isActive']);
            }

            ++$result['updated'];
        }

        if ($deactivateMissing) {
            foreach ($existing as $id => $question) {
                if (isset($seenIds[$id]) || !$question->isActive()) {
                    continue;
                }

                if (!$dryRun) {
                    $question->setIsActive(false);
                }

                ++$result['deactivated'];
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return $result;
    }

    /**
     * Read one row into scalars, or say why it cannot be used.
     *
     * @param  array<string, mixed> $row
     * @return array{id: ?string, prompt: string, options: list<string>, correctIndex: int, sortOrder: int, isActive: bool, error?: string}
     */
    private function parseRow(array $row, string $label): array
    {
        $out = [
            'id'           => null,
            'prompt'       => '',
            'options'      => [],
            'correctIndex' => 0,
            'sortOrder'    => 0,
            'isActive'     => true,
        ];

        $rawId = $row['id'] ?? null;

        if ($rawId !== null && $rawId !== '') {
            if (!\is_string($rawId) || !Uuid::isValid($rawId)) {
                return $out + ['error' => \sprintf('%s: "id" is not a valid UUID.', $label)];
            }

            $out['id'] = $rawId;
        }

        $prompt = $row['prompt'] ?? null;

        if (!\is_string($prompt) || trim($prompt) === '') {
            return $out + ['error' => \sprintf('%s: "prompt" is required.', $label)];
        }

        $out['prompt'] = trim($prompt);

        $options = $row['options'] ?? null;

        if (!\is_array($options)) {
            return $out + ['error' => \sprintf('%s: "options" must be a list.', $label)];
        }

        $clean = [];

        foreach ($options as $option) {
            if (!\is_string($option) || trim($option) === '') {
                return $out + ['error' => \sprintf('%s: every option must be a non-empty string.', $label)];
            }

            $clean[] = trim($option);
        }

        if (\count($clean) < self::MIN_OPTIONS) {
            return $out + ['error' => \sprintf(
                '%s: needs at least %d options, got %d.',
                $label,
                self::MIN_OPTIONS,
                \count($clean),
            )];
        }

        $out['options'] = $clean;

        $correctIndex = $row['correctIndex'] ?? null;

        if (!\is_int($correctIndex) || !isset($clean[$correctIndex])) {
            // The check the entity's isAnswerable() makes, enforced at the door rather than
            // discovered later as a question nobody can get right.
            return $out + ['error' => \sprintf(
                '%s: "correctIndex" must be an index into options (0-%d).',
                $label,
                \count($clean) - 1,
            )];
        }

        $out['correctIndex'] = $correctIndex;

        $sortOrder = $row['sortOrder'] ?? 0;

        if (\is_int($sortOrder)) {
            $out['sortOrder'] = $sortOrder;
        }

        $isActive = $row['isActive'] ?? true;

        if (\is_bool($isActive)) {
            $out['isActive'] = $isActive;
        }

        return $out;
    }

    /**
     * @param array{prompt: string, options: list<string>, correctIndex: int, sortOrder: int, isActive: bool} $parsed
     */
    private function insert(?string $id, array $parsed): SetupQuizQuestion
    {
        $question = new SetupQuizQuestion($parsed['prompt'], $parsed['options'], $parsed['correctIndex']);
        $question->setSortOrder($parsed['sortOrder']);
        $question->setIsActive($parsed['isActive']);

        if ($id !== null) {
            // The id is set in the constructor and has no setter — by design, since nothing in
            // the application should reassign one. Reflection here rather than a setter so that
            // stays true everywhere except this one deliberate place.
            $property = new \ReflectionProperty(SetupQuizQuestion::class, 'id');
            $property->setValue($question, UuidV7::fromString($id));
        }

        $this->em->persist($question);

        return $question;
    }

    /**
     * @param array{prompt: string, options: list<string>, correctIndex: int, sortOrder: int, isActive: bool} $parsed
     */
    private function isUnchanged(SetupQuizQuestion $question, array $parsed): bool
    {
        return $question->getPrompt() === $parsed['prompt']
            && $question->getOptions() === $parsed['options']
            && $question->getCorrectIndex() === $parsed['correctIndex']
            && $question->getSortOrder() === $parsed['sortOrder']
            && $question->isActive() === $parsed['isActive'];
    }
}
