<?php

declare(strict_types=1);

namespace App\Service\PersonalAssistant;

/**
 * Turns the device's JSON into a PaAdviceRequest, or refuses it.
 *
 * THE DEVICE SENDS FACTS, NOT TEXT (Courtney, 2026-11-27). The app computes coded findings with
 * their numbers; the AI only puts them in the PA's voice. So nothing in `findings` may carry
 * free text:
 *   - `code` is one of FINDING_CODES (the app's nine AssistantMemoType codes plus one summary
 *     per topic). Unknown codes are dropped, not refused, so a newer app talking to an older
 *     server degrades to fewer findings rather than an error.
 *   - `facts` keys are camelCase identifiers; values are numbers, booleans, ISO dates or short
 *     lowercase tokens (`[a-z0-9_-]{1,24}`). Anything else is dropped.
 * The facts are validated by SHAPE rather than against a per-code key list on purpose: the app
 * owns which facts each finding carries, and a token of 24 lowercase characters cannot carry an
 * instruction worth worrying about. The one free-text field is `context.clubName`, which the
 * owner typed themselves; it is length-capped and stripped of control characters, and like
 * everything else it reaches the model inside a block marked as data.
 */
final class PaAdviceRequestParser
{
    public const MAX_BODY_BYTES    = 8192;
    public const MAX_FINDINGS      = 6;
    public const MAX_FACTS         = 10;
    public const SUPPORTED_LOCALES = ['en'];

    /**
     * `invest` is the fifth ADVICE chip (2026-11-27). `sponsor_pitch` is HIDDEN: no chip shows it;
     * only the app's sham-sponsor pitch dialog sends it, and PaAdviceService refuses it while
     * `GameConfig::paCheatsEnabled` is off.
     */
    public const TOPICS = ['finances', 'squad', 'transfers', 'general', 'invest', 'sponsor_pitch'];

    /** Topics that only exist while the PA cheat switch is on. */
    public const CHEAT_TOPICS = ['sponsor_pitch'];

    public const SEVERITIES = ['low', 'medium', 'high'];

    public const FINDING_CODES = [
        // The app's AssistantMemoType codes (src/types/assistant.ts)
        'SUPPORTER_CRISIS',
        'FINANCIAL_BLEED',
        '5_STAR_UNDERPERFORMANCE',
        'DRESSING_ROOM_SCHISM',
        'STAFF_MORALE_SLUMP',
        'PHYSICAL_BURNOUT',
        'ACADEMY_OVER_SATURATION',
        'GAFFER_IDEOLOGY_DEADLOCK',
        'SCOUTING_PRIMER',
        // One summary per topic
        'FINANCE_SUMMARY',
        'SQUAD_SUMMARY',
        'TRANSFER_SUMMARY',
        'GENERAL_SUMMARY',
        'INVEST_SUMMARY',
        // The sham-sponsor pitch dialog (topic sponsor_pitch)
        'SPONSOR_PITCH_BLEED',
        'SPONSOR_PITCH_TABLE',
        'SPONSOR_PITCH_ACCEPTED',
        'SPONSOR_PITCH_DECLINED',
    ];

    private const SEVERITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2];

    /** @throws PaAdviceValidationException */
    public function parse(string $rawBody): PaAdviceRequest
    {
        if (\strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new PaAdviceValidationException('payload_too_large', \sprintf('The request body is over %d bytes.', self::MAX_BODY_BYTES));
        }

        $data = json_decode($rawBody, true);
        if (!\is_array($data) || array_is_list($data) && $data !== []) {
            throw new PaAdviceValidationException('invalid_json', 'The request body must be a JSON object.');
        }

        $personalityId = $data['personalityId'] ?? null;
        if (!\is_string($personalityId) || preg_match('/^[a-z0-9-]{1,60}$/', $personalityId) !== 1) {
            throw new PaAdviceValidationException('invalid_personality', 'personalityId must be a PA slug.');
        }

        $topic = $data['topic'] ?? null;
        if (!\is_string($topic) || !\in_array($topic, self::TOPICS, true)) {
            throw new PaAdviceValidationException('invalid_topic', 'topic must be one of: ' . implode(', ', self::TOPICS) . '.');
        }

        $locale = $data['locale'] ?? 'en';
        if (!\is_string($locale) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) !== 1) {
            throw new PaAdviceValidationException('invalid_locale', 'locale must be a language code such as "en".');
        }

        return new PaAdviceRequest(
            personalityId: $personalityId,
            topic: $topic,
            locale: $locale,
            context: self::context($data['context'] ?? []),
            findings: self::findings($data['findings'] ?? []),
        );
    }

    /** @return array<string, scalar> */
    private static function context(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];

        if (\is_string($raw['clubName'] ?? null)) {
            $name = trim((string) preg_replace('/[\p{C}<>{}\[\]`]+/u', ' ', $raw['clubName']));
            $name = preg_replace('/\s+/u', ' ', $name) ?? '';
            if ($name !== '') {
                $out['clubName'] = mb_substr($name, 0, 60);
            }
        }
        if (\is_int($raw['tier'] ?? null) && $raw['tier'] >= 1 && $raw['tier'] <= 20) {
            $out['tier'] = $raw['tier'];
        }
        if (\is_string($raw['gameDate'] ?? null) && self::isIsoDate($raw['gameDate'])) {
            $out['gameDate'] = $raw['gameDate'];
        }
        if (\is_string($raw['currency'] ?? null) && preg_match('/^[A-Z]{3}$/', $raw['currency']) === 1) {
            $out['currency'] = $raw['currency'];
        }

        return $out;
    }

    /** @return list<array{code: string, severity: string, facts: array<string, scalar>}> */
    private static function findings(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $code = $item['code'] ?? null;
            if (!\is_string($code) || !\in_array($code, self::FINDING_CODES, true)) {
                continue;
            }
            $severity = $item['severity'] ?? 'medium';
            if (!\is_string($severity) || !\in_array($severity, self::SEVERITIES, true)) {
                $severity = 'medium';
            }

            $out[] = ['code' => $code, 'severity' => $severity, 'facts' => self::facts($item['facts'] ?? [])];
        }

        // Highest severity first, then the device's own order, so the cap drops the least urgent.
        $indexed = array_map(null, array_keys($out), $out);
        usort($indexed, static fn (array $a, array $b) => [self::SEVERITY_RANK[$a[1]['severity']], $a[0]] <=> [self::SEVERITY_RANK[$b[1]['severity']], $b[0]]);

        return \array_slice(array_map(static fn (array $pair) => $pair[1], $indexed), 0, self::MAX_FINDINGS);
    }

    /** @return array<string, scalar> */
    private static function facts(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (\count($out) >= self::MAX_FACTS) {
                break;
            }
            if (!\is_string($key) || preg_match('/^[a-z][A-Za-z0-9]{0,39}$/', $key) !== 1) {
                continue;
            }
            if (\is_int($value) || \is_bool($value)) {
                $out[$key] = $value;
            } elseif (\is_float($value) && is_finite($value)) {
                $out[$key] = round($value, 4);
            } elseif (\is_string($value) && (preg_match('/^[a-z0-9_-]{1,24}$/', $value) === 1 || self::isIsoDate($value))) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function isIsoDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
