<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

/**
 * Decides whether an operator-authored or model-suggested name pool is safe to store.
 *
 * Per list, never per country: first names and surnames are two independent decisions, and a
 * bad paste into one should not throw away a good one in the other.
 *
 * IT IS A SIBLING OF NamingContentValidator, NOT A REUSE OF IT, and the differences are the
 * point. Club suffixes cap at forty because the longest authored list is twenty; these target
 * a hundred and fifty. The sprintf %s rules are meaningless here. And the dedupe is different
 * in a way that matters more than either — see below.
 */
final class NamePoolValidator
{
    /**
     * What a top-up aims for, and what the form suggests.
     *
     * English and Spanish, the two oldest and largest shipped pools, are 150/150 — about
     * 22,500 distinct full names. The median shipped pool is 30/30, which is 900 combinations
     * against the five hundred players a single warm generates per country, and that
     * repetition is the whole reason this feature exists.
     */
    public const TARGET_PER_LIST = 150;

    /**
     * Below this it is not a pool.
     *
     * Ten names means every club in a country draws from the same handful, which reads worse
     * than the English fallback it replaced. The smallest shipped pool is twenty-five.
     */
    public const MIN_PER_LIST = 10;

    /** Generous headroom over the target, so a deliberate larger pool is not refused. */
    public const MAX_PER_LIST = 400;

    /**
     * The longest shipped name is 'Northumberland' at fourteen characters.
     *
     * Forty leaves room for the long real ones — 'Al Dawsari', 'van der Berg' — while still
     * catching a paste that was never a name.
     */
    public const NAME_MAX_LENGTH = 40;

    /**
     * @param array<string, list<string>> $input keyed 'firstNames' / 'lastNames'
     */
    public function validate(array $input): ValidatedNamePool
    {
        $accepted = [];
        $rejected = [];
        $notes    = [];

        foreach (['firstNames', 'lastNames'] as $list) {
            $raw = $input[$list] ?? [];

            if (!\is_array($raw)) {
                $rejected[$list] = 'this is not a list of lines';

                continue;
            }

            $listNotes = [];
            $kept      = $this->clean($raw, $listNotes);

            // Nothing supplied is not the same as supplied badly: it leaves whatever is
            // stored alone, which is what lets one list be saved without the other.
            if ($kept === [] && $raw === []) {
                $notes[$list] = $listNotes;

                continue;
            }

            if (\count($kept) < self::MIN_PER_LIST) {
                $rejected[$list] = sprintf(
                    'only %d usable name(s) — at least %d are needed, or every generated person '
                    . 'in the country draws from the same handful',
                    \count($kept),
                    self::MIN_PER_LIST,
                );
                $notes[$list] = $listNotes;

                continue;
            }

            $accepted[$list] = $kept;
            $notes[$list]    = $listNotes;
        }

        return new ValidatedNamePool($accepted, $rejected, array_filter($notes));
    }

    /**
     * Trim, drop the unusable, dedupe, cap.
     *
     * DEDUPED ON mb_strtolower(trim()) AND DELIBERATELY NOT THROUGH ClubNameNormalizer.
     * That normaliser strips diacritics — 'NFD; [:Nonspacing Mark:] Remove; NFC' — which is
     * exactly right for club names, where 'Bréscia' and 'Brescia' are the same club and ought
     * to collide. For people it is destructive: the shipped Turkish pool holds Güneş, Özkan
     * and Yıldırım, and normalising would fold them into Gunes, Ozkan and Yildirim — as it
     * would Müller into Muller, Håland into Haland and Łukasz into Lukasz, across every
     * European pool. Those are different names and all of them are real.
     *
     * @param  list<mixed>  $raw
     * @param  list<string> $notes
     * @return list<string>
     */
    private function clean(array $raw, array &$notes): array
    {
        $kept = [];
        $seen = [];

        foreach ($raw as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $name = trim($value);

            if ($name === '') {
                continue;
            }

            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $notes[] = sprintf("'%s…' is longer than %d characters and was dropped", mb_substr($name, 0, 20), self::NAME_MAX_LENGTH);

                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                $notes[] = sprintf("'%s' repeats '%s' and was dropped", $name, $seen[$key]);

                continue;
            }

            $seen[$key] = $name;
            $kept[]     = $name;
        }

        if (\count($kept) > self::MAX_PER_LIST) {
            $notes[] = sprintf('kept the first %d of %d', self::MAX_PER_LIST, \count($kept));
            $kept    = \array_slice($kept, 0, self::MAX_PER_LIST);
        }

        return $kept;
    }
}
