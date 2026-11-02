<?php

namespace App\Service;

/**
 * Mirrors the client's setup-tutorial drop rules so a bad payload is VISIBLE here.
 *
 * `StarterConfig::$setupTutorialConfig` is served verbatim as `GET /api/starter-config`'s
 * `tutorial`, and the app's `parseTutorial`
 * (`wunderkind-app/src/components/new-game-setup/tutorial.ts`) **cannot throw** — new-game
 * setup is the one route a new account cannot skip, so a malformed block must degrade to "no
 * tutorial" rather than block it. The consequence is that every mistake is SILENT: an item
 * anchored to a step that does not exist, or naming a copy key nothing can resolve, simply
 * does not appear. No error, no log, nothing in the UI.
 *
 * So this is not defensive validation of untrusted input — the input is admin-authored. It
 * exists because the consumer deliberately reports nothing, and someone has to.
 *
 * Pure and static on purpose: no DB, no container, so the admin screen, the CLI check and a
 * unit test can all call it. Translation-row coverage is NOT checked here — it needs the
 * database, and a missing `TranslationKey` row does not cause a drop anyway (the app's
 * bundled catalogue carries all 34 `ngs.tut.*` keys, so English renders regardless); it only
 * stops the copy being reworded without an app release. `app:check-setup-tutorial` reports
 * that half.
 *
 * Kept in step with `tutorial.ts` by hand. If that file's rules change, change these.
 */
final class SetupTutorialValidator
{
    /** Step kinds an anchor may name. Mirrors tutorial.ts's STEP_KINDS. */
    private const STEP_KINDS = [
        'welcome', 'avatar', 'owner', 'country', 'club',
        'badge', 'kits', 'stadium', 'hire', 'role', 'overview',
    ];

    /** A role suffix is legal only on `hire` and `role`. Mirrors tutorial.ts's ROLES. */
    private const ROLES = ['chairman', 'dof', 'manager', 'facilities'];

    private const MODES = ['first', 'newClub', 'profile'];

    /**
     * Every reason the client would drop something, in the order the payload is read.
     *
     * An empty list means the client will accept every item — NOT that the copy is right or
     * the anchors are where the author intended. A wrong-but-valid anchor is undetectable
     * from here.
     *
     * @param  array<mixed> $payload
     * @return list<string>
     */
    public static function problems(array $payload): array
    {
        $problems = [];

        if ($payload === []) {
            return ['empty — the client will show no tutorial at all. That is a valid "off" state.'];
        }

        $version = $payload['version'] ?? null;
        if (!is_int($version) && !is_float($version)) {
            $problems[] = sprintf(
                'version is %s, not a number — the client floors it to 0, which replays nothing. '
                . 'Note "1" as a STRING hits this.',
                get_debug_type($version),
            );
        } elseif (!is_finite((float) $version)) {
            $problems[] = 'version is not finite — the client floors it to 0.';
        }

        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            return array_merge($problems, [
                sprintf('items is %s, not an array — the whole tutorial is dropped.', get_debug_type($items)),
            ]);
        }
        if ($items === []) {
            return array_merge($problems, ['items is empty — the client will show no tutorial at all.']);
        }

        $seen  = [];
        $kept  = 0;
        foreach (array_values($items) as $i => $item) {
            $at = sprintf('item %d', $i + 1);

            if (!is_array($item)) {
                $problems[] = sprintf('%s is %s, not an object — dropped.', $at, get_debug_type($item));
                continue;
            }

            $id = $item['id'] ?? null;
            if (!is_string($id) || $id === '') {
                $problems[] = sprintf('%s has no usable id — dropped.', $at);
                continue;
            }
            $at = sprintf('item %d (%s)', $i + 1, $id);

            if (isset($seen[$id])) {
                $problems[] = sprintf('%s repeats an earlier id — the FIRST one wins, this is dropped.', $at);
                continue;
            }
            $seen[$id] = true;

            $kind = $item['kind'] ?? null;
            if ($kind !== 'note' && $kind !== 'briefing') {
                $problems[] = sprintf(
                    '%s has kind %s — only "note" and "briefing" are understood, so it is dropped.',
                    $at,
                    is_scalar($kind) ? '"' . $kind . '"' : get_debug_type($kind),
                );
                continue;
            }

            $anchor    = is_array($item['anchor'] ?? null) ? $item['anchor'] : [];
            $anchorKey = $kind === 'note' ? 'on' : 'before';
            $anchorVal = $anchor[$anchorKey] ?? null;

            if ($problem = self::anchorProblem($anchorVal, $anchorKey)) {
                $problems[] = sprintf('%s: %s', $at, $problem);
                continue;
            }

            $required = $kind === 'note' ? ['bodyKey'] : ['titleKey', 'bodyKey'];
            $missing  = [];
            foreach ($required as $field) {
                $v = $item[$field] ?? null;
                if (!is_string($v) || $v === '') {
                    $missing[] = $field;
                }
            }
            if ($missing !== []) {
                $problems[] = sprintf('%s is missing %s — dropped.', $at, implode(' and ', $missing));
                continue;
            }

            foreach (self::copyKeys($item) as $key) {
                if (!str_starts_with($key, 'ngs.tut.')) {
                    $problems[] = sprintf(
                        '%s names copy key "%s", which is not under `ngs.tut.`. The keys were renamed '
                        . 'from a bare `tut.` prefix at integration; the old spelling resolves to nothing '
                        . 'and the item is dropped.',
                        $at,
                        $key,
                    );
                }
            }

            if (array_key_exists('modes', $item)) {
                $modes = $item['modes'];
                if (!is_array($modes)) {
                    $problems[] = sprintf('%s: modes is %s, not an array — treated as ALL modes.', $at, get_debug_type($modes));
                } else {
                    $unknown = array_diff(array_filter($modes, 'is_string'), self::MODES);
                    if ($unknown !== []) {
                        $problems[] = sprintf(
                            '%s: unknown mode(s) %s. Unknown values are filtered out, and if NOTHING valid '
                            . 'remains the item shows in EVERY mode — a typo here widens rather than narrows.',
                            $at,
                            implode(', ', array_map(static fn ($m) => '"' . $m . '"', $unknown)),
                        );
                    }
                }
            }

            if ($kind === 'note' && array_key_exists('dismissible', $item) && !is_bool($item['dismissible'])) {
                $problems[] = sprintf(
                    '%s: dismissible is %s, not a bool. Only an explicit `false` makes a note permanent; '
                    . 'anything else reads as dismissible.',
                    $at,
                    get_debug_type($item['dismissible']),
                );
            }

            ++$kept;
        }

        if ($kept === 0) {
            $problems[] = 'NOTHING survives — the client treats this as no tutorial at all.';
        }

        return $problems;
    }

    /** @return list<string> every copy key an item names */
    private static function copyKeys(array $item): array
    {
        $keys = [];
        foreach (['titleKey', 'bodyKey', 'ctaKey'] as $field) {
            $v = $item[$field] ?? null;
            if (is_string($v) && $v !== '') {
                $keys[] = $v;
            }
        }
        foreach (is_array($item['pointKeys'] ?? null) ? $item['pointKeys'] : [] as $k) {
            if (is_string($k) && $k !== '') {
                $keys[] = $k;
            }
        }

        return $keys;
    }

    private static function anchorProblem(mixed $value, string $field): ?string
    {
        if (!is_string($value) || $value === '') {
            return sprintf('anchor.%s is %s, not a step name — dropped.', $field, get_debug_type($value));
        }

        $parts = explode(':', $value);
        if (count($parts) > 2) {
            return sprintf('anchor.%s "%s" has more than one colon — dropped.', $field, $value);
        }

        [$kind, $role] = [$parts[0], $parts[1] ?? null];

        if (!in_array($kind, self::STEP_KINDS, true)) {
            return sprintf(
                'anchor.%s "%s" names no step that exists — dropped. Valid: %s.',
                $field,
                $value,
                implode(', ', self::STEP_KINDS),
            );
        }

        if ($role === null) {
            return null;
        }

        if ($kind !== 'hire' && $kind !== 'role') {
            return sprintf(
                'anchor.%s "%s" puts a role on "%s", which has none — only hire and role take one. Dropped.',
                $field,
                $value,
                $kind,
            );
        }

        if (!in_array($role, self::ROLES, true)) {
            return sprintf(
                'anchor.%s "%s" names no role that exists — dropped. Valid: %s.',
                $field,
                $value,
                implode(', ', self::ROLES),
            );
        }

        return null;
    }
}
