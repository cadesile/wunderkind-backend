<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

/**
 * The validator's verdict on one country's naming content.
 *
 * THREE BUCKETS. `accepted` is what to store, `rejected` names a whole category that cannot be
 * stored and why, and `notes` records individual tokens that were dropped on the way — a blank
 * line, a repeat, something far too long. The split matters because the two need different
 * words on screen: a rejection is something the operator must fix, a note is something that
 * was quietly tidied and they may want to know about.
 */
final readonly class ValidatedNamingContent
{
    /**
     * @param array<string, list<string>> $accepted category => the list to store
     * @param array<string, string>       $rejected category => why nothing is being stored for it
     * @param array<string, list<string>> $notes    category => what was dropped from it, and why
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

    /** @return list<string> every note, flattened and prefixed with its category. */
    public function flatNotes(): array
    {
        $out = [];

        foreach ($this->notes as $category => $notes) {
            foreach ($notes as $note) {
                $out[] = sprintf('%s: %s', $category, $note);
            }
        }

        return $out;
    }
}
