<?php

namespace App\Controller\Admin;

use App\Entity\SocialAccountConnection;
use App\Enum\SocialPlatform;
use App\Repository\SocialAccountConnectionRepository;
use App\Service\TokenEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/admin/social')]
#[IsGranted('ROLE_ADMIN')]
class SocialAuthController extends AbstractController
{
    private const FB_GRAPH_VERSION     = 'v19.0';
    private const FB_OAUTH_SCOPES      = 'pages_show_list,pages_manage_posts,pages_read_engagement';
    private const TWITTER_OAUTH_SCOPES = 'tweet.read tweet.write users.read offline.access';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SocialAccountConnectionRepository $connectionRepository,
        private readonly TokenEncryptionService $tokenEncryption,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $facebookAppId,
        private readonly string $facebookAppSecret,
        private readonly string $facebookRedirectUri,
        private readonly string $twitterClientId,
        private readonly string $twitterClientSecret,
        private readonly string $twitterRedirectUri,
    ) {
    }

    // ── Disconnect ───────────────────────────────────────────────────────
    //
    // The connections list screen itself lives on DashboardController, not
    // here — EasyAdmin only populates the `ea` Twig context (used by
    // @EasyAdmin/layout.html.twig) for actions defined on a class extending
    // AbstractDashboardController. Rendering that layout from a plain
    // AbstractController blows up with "i18n on null". The OAuth actions
    // below never render Twig (they only ever redirect), so they're
    // unaffected and stay here.

    #[Route('/{id}/disconnect', name: 'admin_social_disconnect', methods: ['POST'])]
    public function disconnect(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('social_disconnect', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Invalid CSRF token.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $connection = $this->connectionRepository->find($id);
        if ($connection === null) {
            $this->addFlash('danger', 'Connection not found.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $connection->setIsActive(false);
        $this->em->flush();

        $this->addFlash('success', sprintf('Disconnected "%s".', $connection->getDisplayName()));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
    }

    // ── Facebook OAuth ───────────────────────────────────────────────────

    #[Route('/facebook/connect', name: 'admin_social_facebook_connect', methods: ['GET'])]
    public function facebookConnect(Request $request): RedirectResponse
    {
        $state = bin2hex(random_bytes(32));
        $request->getSession()->set('social_oauth_state_facebook', $state);

        $query = http_build_query([
            'client_id'     => $this->facebookAppId,
            'redirect_uri'  => $this->facebookRedirectUri,
            'state'         => $state,
            'scope'         => self::FB_OAUTH_SCOPES,
            'response_type' => 'code',
        ]);

        return new RedirectResponse('https://www.facebook.com/' . self::FB_GRAPH_VERSION . '/dialog/oauth?' . $query);
    }

    #[Route('/facebook/callback', name: 'admin_social_facebook_callback', methods: ['GET'])]
    public function facebookCallback(Request $request): Response
    {
        $session       = $request->getSession();
        $expectedState = $session->get('social_oauth_state_facebook');
        $session->remove('social_oauth_state_facebook');

        $state = (string) $request->query->get('state', '');
        if ($expectedState === null || $state === '' || !hash_equals($expectedState, $state)) {
            $this->addFlash('danger', 'Facebook connection failed: invalid or missing state parameter (possible CSRF attempt).');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $code = $request->query->get('code');
        if (!$code) {
            $errorDescription = $request->query->get('error_description', 'No authorization code returned.');
            $this->addFlash('danger', 'Facebook connection failed: ' . $errorDescription);
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        try {
            // Step 1: exchange the code for a short-lived user access token.
            $shortLivedToken = $this->fetchFacebookAccessToken([
                'client_id'     => $this->facebookAppId,
                'client_secret' => $this->facebookAppSecret,
                'redirect_uri'  => $this->facebookRedirectUri,
                'code'          => $code,
            ]);

            // Step 2: exchange the short-lived token for a long-lived user access token.
            $longLivedToken = $this->fetchFacebookAccessToken([
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $this->facebookAppId,
                'client_secret'     => $this->facebookAppSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

            // Step 3: list the Pages the user manages. Page tokens derived from
            // a long-lived user token are themselves long-lived.
            $pagesResponse = $this->httpClient->request(
                'GET',
                'https://graph.facebook.com/' . self::FB_GRAPH_VERSION . '/me/accounts',
                ['query' => ['access_token' => $longLivedToken]],
            );
            $pages = $pagesResponse->toArray()['data'] ?? [];
        } catch (\Throwable $e) {
            $this->logSocialAuthError('Facebook', $e);
            $this->addFlash('danger', 'Facebook connection failed. Check application logs for details.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        if (empty($pages)) {
            $this->addFlash('warning', 'Facebook authorization succeeded, but no Pages were returned. Make sure the account manages at least one Page and granted the requested permissions.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        foreach ($pages as $page) {
            $this->upsertConnection(
                SocialPlatform::FACEBOOK,
                (string) $page['id'],
                (string) $page['name'],
                (string) $page['access_token'],
                null,
                null,
            );
        }

        $this->addFlash('success', sprintf('Connected %d Facebook Page(s).', count($pages)));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
    }

    /** @param array<string, string> $params */
    private function fetchFacebookAccessToken(array $params): string
    {
        $response = $this->httpClient->request(
            'GET',
            'https://graph.facebook.com/' . self::FB_GRAPH_VERSION . '/oauth/access_token',
            ['query' => $params],
        );
        $data = $response->toArray();

        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Facebook did not return an access_token.');
        }

        return (string) $data['access_token'];
    }

    // ── X (Twitter) OAuth 2.0 with PKCE ──────────────────────────────────

    #[Route('/twitter/connect', name: 'admin_social_twitter_connect', methods: ['GET'])]
    public function twitterConnect(Request $request): RedirectResponse
    {
        $state     = bin2hex(random_bytes(32));
        $verifier  = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $session = $request->getSession();
        $session->set('social_oauth_state_twitter', $state);
        $session->set('social_oauth_pkce_verifier_twitter', $verifier);

        $query = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->twitterClientId,
            'redirect_uri'          => $this->twitterRedirectUri,
            'scope'                 => self::TWITTER_OAUTH_SCOPES,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return new RedirectResponse('https://twitter.com/i/oauth2/authorize?' . $query);
    }

    #[Route('/twitter/callback', name: 'admin_social_twitter_callback', methods: ['GET'])]
    public function twitterCallback(Request $request): Response
    {
        $session       = $request->getSession();
        $expectedState = $session->get('social_oauth_state_twitter');
        $verifier      = $session->get('social_oauth_pkce_verifier_twitter');
        $session->remove('social_oauth_state_twitter');
        $session->remove('social_oauth_pkce_verifier_twitter');

        $state = (string) $request->query->get('state', '');
        if ($expectedState === null || $state === '' || !hash_equals($expectedState, $state)) {
            $this->addFlash('danger', 'X connection failed: invalid or missing state parameter (possible CSRF attempt).');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        if ($verifier === null) {
            $this->addFlash('danger', 'X connection failed: missing PKCE verifier — please try connecting again.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $code = $request->query->get('code');
        if (!$code) {
            $errorDescription = $request->query->get('error_description', 'No authorization code returned.');
            $this->addFlash('danger', 'X connection failed: ' . $errorDescription);
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        try {
            $tokenResponse = $this->httpClient->request('POST', 'https://api.twitter.com/2/oauth2/token', [
                'auth_basic' => [$this->twitterClientId, $this->twitterClientSecret],
                'body'       => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $this->twitterRedirectUri,
                    'code_verifier' => $verifier,
                    'client_id'     => $this->twitterClientId,
                ],
            ]);
            $tokenData = $tokenResponse->toArray();

            if (!isset($tokenData['access_token'])) {
                throw new \RuntimeException('X did not return an access_token.');
            }

            $accessToken  = (string) $tokenData['access_token'];
            $refreshToken = isset($tokenData['refresh_token']) ? (string) $tokenData['refresh_token'] : null;
            $expiresIn    = isset($tokenData['expires_in']) ? (int) $tokenData['expires_in'] : null;

            $userResponse = $this->httpClient->request('GET', 'https://api.twitter.com/2/users/me', [
                'auth_bearer' => $accessToken,
            ]);
            $userData = $userResponse->toArray()['data'] ?? [];
        } catch (\Throwable $e) {
            $this->logSocialAuthError('X', $e);
            $this->addFlash('danger', 'X connection failed. Check application logs for details.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        if (empty($userData['id'])) {
            $this->addFlash('danger', 'X connection failed: could not retrieve account details.');
            return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
        }

        $expiresAt = $expiresIn !== null ? (new \DateTimeImmutable())->modify("+{$expiresIn} seconds") : null;
        $username  = $userData['username'] ?? $userData['id'];

        $this->upsertConnection(
            SocialPlatform::TWITTER,
            (string) $userData['id'],
            '@' . $username,
            $accessToken,
            $refreshToken,
            $expiresAt,
        );

        $this->addFlash('success', sprintf('Connected X account @%s.', $username));
        return $this->redirect($this->generateUrl('admin', ['routeName' => 'admin_social_connections']));
    }

    // ── Shared error logging ─────────────────────────────────────────────

    /**
     * Logs OAuth failures server-side instead of surfacing them to the
     * browser. HttpExceptionInterface's own getMessage() embeds the full
     * request URL — which for Facebook's GET-based token exchange includes
     * client_secret/code as query params — so we deliberately log the HTTP
     * status and response body instead of the exception message for those.
     * Other exceptions (our own RuntimeExceptions) never embed request
     * URLs, so their message is safe to log directly.
     */
    private function logSocialAuthError(string $platform, \Throwable $e): void
    {
        $context = ['exception_class' => $e::class];

        if ($e instanceof HttpExceptionInterface) {
            $context['status_code'] = $e->getResponse()->getStatusCode();
            try {
                $context['response_body'] = $e->getResponse()->getContent(false);
            } catch (\Throwable) {
                // Transport-level failures (e.g. connection reset) may not have a readable body.
            }
        } else {
            $context['message'] = $e->getMessage();
        }

        $this->logger->error("{$platform} OAuth connection failed", $context);
    }

    // ── Shared persistence ───────────────────────────────────────────────

    private function upsertConnection(
        SocialPlatform $platform,
        string $externalAccountId,
        string $displayName,
        string $accessToken,
        ?string $refreshToken,
        ?\DateTimeImmutable $tokenExpiresAt,
    ): void {
        $encryptedAccessToken  = $this->tokenEncryption->encrypt($accessToken);
        $encryptedRefreshToken = $refreshToken !== null ? $this->tokenEncryption->encrypt($refreshToken) : null;

        $connection = $this->connectionRepository->findByPlatformAndExternalId($platform, $externalAccountId);

        if ($connection === null) {
            $connection = new SocialAccountConnection($platform, $displayName, $externalAccountId, $encryptedAccessToken);
            $this->em->persist($connection);
        } else {
            $connection->setDisplayName($displayName);
            $connection->setAccessToken($encryptedAccessToken);
            $connection->setLastRefreshedAt(new \DateTimeImmutable());
        }

        $connection->setRefreshToken($encryptedRefreshToken);
        $connection->setTokenExpiresAt($tokenExpiresAt);
        $connection->setIsActive(true);

        $this->em->flush();
    }
}
