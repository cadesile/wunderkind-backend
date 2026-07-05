<?php

namespace App\Tests\Service;

use App\Entity\SocialAccountConnection;
use App\Enum\SocialPlatform;
use App\Exception\SocialPostingException;
use App\Service\SocialPostingService;
use App\Service\TokenEncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RecordingTestLogger extends AbstractLogger
{
    /** @var array<int, array{level: mixed, message: string, context: array}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}

class SocialPostingServiceTest extends TestCase
{
    private function encryptionService(): TokenEncryptionService
    {
        return new TokenEncryptionService(base64_encode(sodium_crypto_secretbox_keygen()));
    }

    private function facebookConnection(TokenEncryptionService $enc, string $plainToken = 'fb-page-token'): SocialAccountConnection
    {
        return new SocialAccountConnection(SocialPlatform::FACEBOOK, 'Test Page', 'page-123', $enc->encrypt($plainToken));
    }

    private function twitterConnection(TokenEncryptionService $enc, string $plainToken = 'x-user-token'): SocialAccountConnection
    {
        return new SocialAccountConnection(SocialPlatform::TWITTER, '@testfc', 'user-456', $enc->encrypt($plainToken));
    }

    public function testPostToFacebookSuccess(): void
    {
        $enc = $this->encryptionService();
        $httpClient = new MockHttpClient([new MockResponse(json_encode(['id' => '123_456']))]);
        $logger = new RecordingTestLogger();
        $service = new SocialPostingService($enc, $httpClient, $logger);

        $service->post($this->facebookConnection($enc), 'Hello from the test suite!');

        $request = $httpClient->getRequestsCount();
        $this->assertSame(1, $request);
        $this->assertCount(0, $logger->records);
    }

    public function testPostToFacebookFailureThrowsAndLogsStatusAndBodyNotRawMessage(): void
    {
        $enc = $this->encryptionService();
        $httpClient = new MockHttpClient([new MockResponse('{"error":"invalid token"}', ['http_code' => 401])]);
        $logger = new RecordingTestLogger();
        $service = new SocialPostingService($enc, $httpClient, $logger);

        $this->expectException(SocialPostingException::class);

        try {
            $service->post($this->facebookConnection($enc), 'Hello!');
        } finally {
            $this->assertCount(1, $logger->records);
            $context = $logger->records[0]['context'];
            $this->assertSame(401, $context['status_code']);
            $this->assertStringContainsString('invalid token', $context['response_body']);
            $this->assertArrayNotHasKey('message', $context);
        }
    }

    public function testPostToTwitterSuccess(): void
    {
        $enc = $this->encryptionService();
        $httpClient = new MockHttpClient([new MockResponse(json_encode(['data' => ['id' => '999']]))]);
        $logger = new RecordingTestLogger();
        $service = new SocialPostingService($enc, $httpClient, $logger);

        $service->post($this->twitterConnection($enc), 'Hello from X!');

        $this->assertSame(1, $httpClient->getRequestsCount());
        $this->assertCount(0, $logger->records);
    }

    public function testPostToTwitterFailureThrowsAndLogsStatusAndBody(): void
    {
        $enc = $this->encryptionService();
        $httpClient = new MockHttpClient([new MockResponse('{"detail":"Forbidden"}', ['http_code' => 403])]);
        $logger = new RecordingTestLogger();
        $service = new SocialPostingService($enc, $httpClient, $logger);

        $this->expectException(SocialPostingException::class);

        try {
            $service->post($this->twitterConnection($enc), 'Hello!');
        } finally {
            $this->assertCount(1, $logger->records);
            $this->assertSame(403, $logger->records[0]['context']['status_code']);
        }
    }

    public function testTwitterTextOver280CharsIsRejectedWithoutCallingHttpClient(): void
    {
        $enc = $this->encryptionService();
        $httpClient = new MockHttpClient([]);
        $logger = new RecordingTestLogger();
        $service = new SocialPostingService($enc, $httpClient, $logger);

        $this->expectException(SocialPostingException::class);

        try {
            $service->post($this->twitterConnection($enc), str_repeat('a', 281));
        } finally {
            $this->assertSame(0, $httpClient->getRequestsCount(), 'Must not call the HTTP client for an over-length X post.');
        }
    }

    public function testDecryptFailureOnFacebookIsWrappedInSocialPostingException(): void
    {
        $httpClient = new MockHttpClient([]);
        $logger = new RecordingTestLogger();
        $service = new SocialPostingService($this->encryptionService(), $httpClient, $logger);

        // Create a connection with garbage ciphertext that will fail to decrypt.
        $badConnection = new SocialAccountConnection(SocialPlatform::FACEBOOK, 'Test Page', 'page-123', 'not-valid-base64-ciphertext');

        $this->expectException(SocialPostingException::class);

        try {
            $service->post($badConnection, 'some text');
        } finally {
            $this->assertSame(0, $httpClient->getRequestsCount(), 'Must not call HTTP client when decrypt fails.');
        }
    }

    public function testDecryptFailureOnTwitterIsWrappedInSocialPostingException(): void
    {
        $httpClient = new MockHttpClient([]);
        $logger = new RecordingTestLogger();
        $service = new SocialPostingService($this->encryptionService(), $httpClient, $logger);

        // Create a connection with garbage ciphertext that will fail to decrypt.
        $badConnection = new SocialAccountConnection(SocialPlatform::TWITTER, '@testfc', 'user-456', 'malformed!!!ciphertext');

        $this->expectException(SocialPostingException::class);

        try {
            $service->post($badConnection, 'some text');
        } finally {
            $this->assertSame(0, $httpClient->getRequestsCount(), 'Must not call HTTP client when decrypt fails.');
        }
    }
}
