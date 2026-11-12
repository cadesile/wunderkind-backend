<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

/**
 * The validator's verdict on one country's name pool.
 *
 * Same three buckets as ValidatedNamingContent, and for the same reason: a rejection is
 * something the operator must fix, a note is something that was quietly tidied and they may
 * want to know about. The two need different words on screen.
 */
final readonly class ValidatedNamePool
{
    /**
     * @param array<string, list<string>> $accepted list name => the names to store
     * @param array<string, string>       $rejected list name => why nothing is being stored
     * @param array<string, list<string>> $notes    list name => what was dropped, and why
     */
    public function __construct(
        public array $accepted = [],
        public array $rejected = [],
        public array $notes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->accepted === [];
    }

    public function hasRejections(): bool
    {
        return $this->rejected !== [];
    }

    /** @return list<string> every note, flattened and prefixed with its list. */
    public function flatNotes(): array
    {
        $out = [];

        foreach ($this->notes as $list => $notes) {
            foreach ($notes as $note) {
                $out[] = sprintf('%s: %s', $list, $note);
            }
        }

        return $out;
    }
}
