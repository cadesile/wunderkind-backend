<?php

namespace App\Service;

use App\Entity\SocialAccountConnection;
use App\Enum\SocialPlatform;
use App\Exception\SocialPostingException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SocialPostingService
{
    // Keep in sync with SocialAuthController::FB_GRAPH_VERSION.
    private const FB_GRAPH_VERSION = 'v19.0';
    private const TWITTER_MAX_CHARS = 280;

    public function __construct(
        private readonly TokenEncryptionService $tokenEncryption,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws SocialPostingException */
    public function post(SocialAccountConnection $connection, string $text): void
    {
        match ($connection->getPlatform()) {
            SocialPlatform::FACEBOOK => $this->postToFacebook($connection, $text),
            SocialPlatform::TWITTER  => $this->postToTwitter($connection, $text),
        };
    }

    private function postToFacebook(SocialAccountConnection $connection, string $text): void
    {
        $accessToken = $this->tokenEncryption->decrypt($connection->getAccessToken());
        $pageId      = $connection->getExternalAccountId();

        try {
            $response = $this->httpClient->request(
                'POST',
                'https://graph.facebook.com/' . self::FB_GRAPH_VERSION . "/{$pageId}/feed",
                ['body' => ['access_token' => $accessToken, 'message' => $text]],
            );
            $data = $response->toArray();

            if (!isset($data['id'])) {
                throw new SocialPostingException('Facebook did not return a post id.');
            }
        } catch (SocialPostingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logPostingError('Facebook', $connection, $e);
            throw new SocialPostingException('Failed to post to Facebook. See logs for details.', previous: $e);
        }
    }

    private function postToTwitter(SocialAccountConnection $connection, string $text): void
    {
        if (mb_strlen($text) > self::TWITTER_MAX_CHARS) {
            throw new SocialPostingException(sprintf(
                'X post is %d characters, exceeds the %d character limit.',
                mb_strlen($text),
                self::TWITTER_MAX_CHARS,
            ));
        }

        $accessToken = $this->tokenEncryption->decrypt($connection->getAccessToken());

        try {
            $response = $this->httpClient->request('POST', 'https://api.twitter.com/2/tweets', [
                'auth_bearer' => $accessToken,
                'json'        => ['text' => $text],
            ]);
            $data = $response->toArray();

            if (!isset($data['data']['id'])) {
                throw new SocialPostingException('X did not return a tweet id.');
            }
        } catch (SocialPostingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logPostingError('X', $connection, $e);
            throw new SocialPostingException('Failed to post to X. See logs for details.', previous: $e);
        }
    }

    /**
     * Mirrors SocialAuthController::logSocialAuthError() — log status code +
     * response body for HTTP failures, never the raw exception message
     * (which can embed secrets for GET-based calls elsewhere in this app;
     * kept consistent here even though these are POST requests).
     */
    private function logPostingError(string $platform, SocialAccountConnection $connection, \Throwable $e): void
    {
        $context = [
            'exception_class'  => $e::class,
            'connection_id'    => (string) $connection->getId(),
            'external_account' => $connection->getExternalAccountId(),
        ];

        if ($e instanceof HttpExceptionInterface) {
            $context['status_code'] = $e->getResponse()->getStatusCode();
            try {
                $context['response_body'] = $e->getResponse()->getContent(false);
            } catch (\Throwable) {
                // Transport-level failures may not have a readable body.
            }
        } else {
            $context['message'] = $e->getMessage();
        }

        $this->logger->error("{$platform} post failed", $context);
    }
}
