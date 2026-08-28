<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\YouTubeFeedService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

class YouTubeFeedServiceTest extends TestCase
{
    private function service(): YouTubeFeedService
    {
        return new YouTubeFeedService(new MockHttpClient(), new ArrayAdapter(), new NullLogger());
    }

    private function feed(string $entries): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns:yt="http://www.youtube.com/xml/schemas/2015"
              xmlns:media="http://search.yahoo.com/mrss/"
              xmlns="http://www.w3.org/2005/Atom">
          <title>BuildMyClub</title>
          {$entries}
        </feed>
        XML;
    }

    private function entry(string $id, string $title, string $published = '2026-08-25T08:58:00+00:00'): string
    {
        return <<<XML
        <entry>
          <yt:videoId>{$id}</yt:videoId>
          <title>{$title}</title>
          <published>{$published}</published>
          <media:group><media:title>{$title}</media:title></media:group>
        </entry>
        XML;
    }

    public function testParsesVideosFromTheFeed(): void
    {
        $videos = $this->service()->parse($this->feed(
            $this->entry('0zrgmm1fGKI', 'Build My Club | Player Relationships Update')
        ));

        self::assertCount(1, $videos);
        self::assertSame('0zrgmm1fGKI', $videos[0]['id']);
        self::assertSame('Build My Club | Player Relationships Update', $videos[0]['title']);
        self::assertSame('2026-08-25T08:58:00+00:00', $videos[0]['publishedAt']);
        self::assertSame('https://i.ytimg.com/vi/0zrgmm1fGKI/hqdefault.jpg', $videos[0]['thumbnail']);
        self::assertSame('https://www.youtube.com/watch?v=0zrgmm1fGKI', $videos[0]['url']);
    }

    /**
     * The live channel feed contains an entry with an empty title. Rendering it
     * would produce a blank card that reads as broken, so it must be dropped.
     */
    public function testDropsEntriesWithAnEmptyTitle(): void
    {
        $videos = $this->service()->parse($this->feed(
            $this->entry('goodVideo01', 'A real upload')
            . '<entry><yt:videoId>emptyTitle1</yt:videoId><title></title>'
            . '<published>2026-08-12T08:55:14+00:00</published>'
            . '<media:group><media:title></media:title></media:group></entry>'
            . $this->entry('goodVideo02', 'Another real upload')
        ));

        self::assertCount(2, $videos);
        self::assertSame(['goodVideo01', 'goodVideo02'], array_column($videos, 'id'));
    }

    public function testDropsEntriesWithNoVideoId(): void
    {
        $videos = $this->service()->parse($this->feed(
            '<entry><title>Orphan</title><media:group><media:title>Orphan</media:title></media:group></entry>'
        ));

        self::assertSame([], $videos);
    }

    /** A malformed feed must return empty, never throw — the section just hides. */
    public function testMalformedXmlReturnsEmptyRatherThanThrowing(): void
    {
        self::assertSame([], $this->service()->parse('<feed><entry>truncated'));
        self::assertSame([], $this->service()->parse(''));
    }

    public function testBlankChannelIdSkipsTheFetchEntirely(): void
    {
        // MockHttpClient with no responses queued would throw if a request were made.
        self::assertSame([], $this->service()->getVideos('   '));
    }
}
