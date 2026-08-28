<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Recent uploads from the Build My Club YouTube channel.
 *
 * Uses the public RSS feed rather than the YouTube Data API: the feed needs no
 * API key, no Google Cloud project and has no quota, which is the entire reason
 * this is a handful of lines instead of a credential to rotate. The trade-off is
 * that it returns only the ~15 most recent uploads — fine, since the landing page
 * picks one at random to feature.
 *
 * The feed is not entirely clean: entries can carry an empty <media:title>
 * (the live channel has one today), so titles are filtered rather than trusted.
 */
class YouTubeFeedService
{
    public const TTL = 21600; // 6 hours

    private const CACHE_KEY = 'youtube_feed';

    private const FEED_URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=%s';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<array{id: string, title: string, publishedAt: string, thumbnail: string, url: string}>
     */
    public function getVideos(string $channelId): array
    {
        if (trim($channelId) === '') {
            return [];
        }

        return $this->cache->get(self::CACHE_KEY . '.' . $channelId, function (ItemInterface $item) use ($channelId): array {
            $item->expiresAfter(self::TTL);

            try {
                $xml = $this->httpClient
                    ->request('GET', sprintf(self::FEED_URL, $channelId), ['timeout' => 5])
                    ->getContent();
            } catch (\Throwable $e) {
                // A dead feed must never take the page down with it — the caller
                // hides the section when this is empty.
                $this->logger->warning('YouTube feed fetch failed', ['channelId' => $channelId, 'exception' => $e]);

                return [];
            }

            return $this->parse($xml);
        });
    }

    /**
     * @return list<array{id: string, title: string, publishedAt: string, thumbnail: string, url: string}>
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($feed === false) {
            $this->logger->warning('YouTube feed could not be parsed as XML');

            return [];
        }

        $videos = [];

        foreach ($feed->entry ?? [] as $entry) {
            $yt    = $entry->children('http://www.youtube.com/xml/schemas/2015');
            $media = $entry->children('http://search.yahoo.com/mrss/');

            $id    = trim((string) ($yt->videoId ?? ''));
            $title = trim((string) ($media->group->title ?? $entry->title ?? ''));

            // Skip malformed entries. An untitled video renders as a blank card,
            // which looks broken rather than minimal.
            if ($id === '' || $title === '') {
                continue;
            }

            $videos[] = [
                'id'          => $id,
                'title'       => $title,
                'publishedAt' => trim((string) ($entry->published ?? '')),
                'thumbnail'   => "https://i.ytimg.com/vi/{$id}/hqdefault.jpg",
                'url'         => "https://www.youtube.com/watch?v={$id}",
            ];
        }

        return $videos;
    }
}
