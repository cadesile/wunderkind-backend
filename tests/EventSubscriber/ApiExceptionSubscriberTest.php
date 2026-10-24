<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Every error on an `/api/*` route must be machine-readable.
 *
 * Sentry `155644075`: a `#[MapRequestPayload]` validation failure on
 * POST /api/owner-avatar answered 422 with Symfony's "An Error Occurred:
 * Unprocessable Content" **HTML** page, because the app sends no
 * `Accept: application/json`. The client could not tell the player which field was
 * wrong, and the Sentry event could not tell us either — it was a 422 with nothing in it.
 */
class ApiExceptionSubscriberTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private mixed $userId = null;

    /** @var object[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $entity) {
            $managed = $this->em->find($entity::class, $entity->getId());
            if ($managed !== null) {
                $this->em->remove($managed);
            }
        }
        $this->cleanup = [];
        $this->em->flush();
        parent::tearDown();
    }

    /**
     * The exact failure mode from the Sentry event: no `Accept` header at all, which is
     * what used to select the HTML error page.
     */
    public function testAValidationFailureIsProblemJsonAndNotAnHtmlPage(): void
    {
        $this->authenticatedPost(['dob' => 'not-a-date']);

        $response = $this->client->getResponse();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<html', (string) $response->getContent());

        $body = json_decode((string) $response->getContent(), true);

        $this->assertSame(422, $body['status']);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertSame('dob', $body['violations'][0]['propertyPath'], 'the client must learn which field was rejected');
        $this->assertNotSame('', $body['violations'][0]['message']);
    }

    /**
     * A JSON value the DTO's declared type cannot hold at all fails in the denormalizer
     * rather than at a constraint, and that has to reach `violations[]` the same way — it
     * is the half of a 422 a form most needs, and the half that was missing.
     */
    public function testAnUncoercibleTypeAlsoReportsTheOffendingField(): void
    {
        $this->authenticatedPost(['avatar' => 'not-an-array']);

        $response = $this->client->getResponse();
        $body     = json_decode((string) $response->getContent(), true);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $body['error']);
        $this->assertSame('avatar', $body['violations'][0]['propertyPath']);
        $this->assertStringContainsString('type', $body['violations'][0]['message']);
    }

    /** A route that does not exist still has to answer JSON, not the HTML 404 page. */
    public function testAnUnroutableApiPathAnswersJson(): void
    {
        $this->client->request('GET', '/api/this-route-does-not-exist');

        $response = $this->client->getResponse();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('not_found', json_decode((string) $response->getContent(), true)['error']);
    }

    /**
     * A 405 carries an `Allow` header that the client may act on, so the headers an
     * HttpException brought with it must survive being turned into JSON.
     */
    public function testHttpExceptionHeadersSurvive(): void
    {
        $this->client->request('DELETE', '/api/countries');

        $response = $this->client->getResponse();

        $this->assertSame(405, $response->getStatusCode());
        $this->assertNotNull($response->headers->get('Allow'));
        $this->assertSame('method_not_allowed', json_decode((string) $response->getContent(), true)['error']);
    }

    /**
     * `/admin` is a browser UI — it must keep Symfony's HTML error pages, or every admin
     * 404 turns into a JSON blob in the operator's face.
     */
    public function testAdminPathsAreLeftAlone(): void
    {
        $this->client->request('GET', '/admin/this-route-does-not-exist');

        $this->assertStringNotContainsString(
            'application/problem+json',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
    }

    /** @param array<string, mixed> $payload */
    private function authenticatedPost(array $payload): void
    {
        if ($this->userId === null) {
            $user = new User('api-error-' . bin2hex(random_bytes(6)) . '@example.com');
            $user->setPassword('x');
            $user->setRoles([User::ROLE_CLUB]);
            $this->em->persist($user);
            $this->em->flush();
            $this->cleanup[] = $user;
            $this->userId    = $user->getId();
        }

        // Single-use login against the stateless JWT firewall — see CLAUDE.md.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);

        $this->client->loginUser($this->em->find(User::class, $this->userId), 'api');
        $this->client->request(
            'POST',
            '/api/owner-avatar',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload),
        );
    }
}
