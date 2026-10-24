<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LiveTelemetryService;
use PHPUnit\Framework\TestCase;

/**
 * Guards LiveTelemetryService::PAYLOAD_KEYS against the class's own source.
 *
 * SyncRecordRepository::findValidPayloadsSince() projects the 24h window down to those
 * keys in SQL — everything else (notably `relationships[]`, ~60% of all payload bytes)
 * never reaches PHP. That projection is what keeps refresh() inside its memory budget,
 * but it fails SILENTLY in the one direction that matters: a build* method that starts
 * reading a key nobody added to the list sees it absent, so its `?? []` guard engages
 * and the feed category quietly produces nothing. No exception, no empty-data warning —
 * exactly the shape CLAUDE.md flags for Import/Export field coverage, and the reason
 * those services carry coverage tests too.
 *
 * So this reads the service's source rather than exercising behaviour: a behavioural
 * test can only cover the categories that exist today, whereas the risk is entirely
 * about the next one added.
 */
class LiveTelemetryPayloadProjectionTest extends TestCase
{
    public function testEveryPayloadKeyTheServiceReadsIsProjected(): void
    {
        $read = self::payloadKeysReadBySource();
        sort($read);

        $this->assertNotEmpty($read, 'the scan must still find this class\'s payload reads');

        $missing = array_diff($read, LiveTelemetryService::PAYLOAD_KEYS);

        $this->assertSame([], array_values($missing), sprintf(
            'LiveTelemetryService reads payload key(s) [%s] that findValidPayloadsSince() '
            . 'projects away — add them to LiveTelemetryService::PAYLOAD_KEYS or that feed '
            . 'category will silently produce nothing.',
            implode(', ', $missing),
        ));
    }

    /**
     * The other direction: a key left in the list after its last reader was removed costs
     * memory for nothing, which is the exact problem this projection exists to fix.
     */
    public function testNoProjectedKeyIsUnread(): void
    {
        $unread = array_diff(LiveTelemetryService::PAYLOAD_KEYS, self::payloadKeysReadBySource());

        $this->assertSame([], array_values($unread), sprintf(
            'PAYLOAD_KEYS carries [%s], which nothing in LiveTelemetryService reads any more — '
            . 'drop them so the window stops fetching and decoding them.',
            implode(', ', $unread),
        ));
    }

    /**
     * Top-level payload keys LiveTelemetryService actually reads, scanned off its source.
     *
     * Comments are stripped first (via token_get_all), because the class's own docblocks
     * discuss this mechanism using placeholder reads like $payload['x'] — matching those
     * would have the guard fail on its own documentation.
     *
     * Two access shapes exist in the class: $row['payload']['x'] on a repository row, and
     * $payload['x'] inside aggregate(), which is handed array_column(.., 'payload').
     * Both are literal, which is what makes a source scan viable — a read routed through
     * an intermediate variable or a computed key would slip past this guard, so keep new
     * payload reads in one of those two shapes rather than widening the regex.
     *
     * @return array<int, string>
     */
    private static function payloadKeysReadBySource(): array
    {
        $file = (string) (new \ReflectionClass(LiveTelemetryService::class))->getFileName();

        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        preg_match_all('/(?:\[\'payload\'\]|\$payload)\[\'([a-zA-Z0-9_]+)\'\]/', $code, $matches);

        return array_values(array_unique($matches[1]));
    }
}
