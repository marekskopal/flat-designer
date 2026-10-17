<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\OAuthController;
use FlatDesigner\OAuth\AuthorizationService;
use FlatDesigner\OAuth\ClientService;
use FlatDesigner\Route\Routes;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use FlatDesigner\Tests\Support\OAuthFlow;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OAuthController::class)]
#[CoversClass(AuthorizationService::class)]
#[CoversClass(ClientService::class)]
final class OAuthControllerTest extends IntegrationTestCase
{
	public function testDiscoveryDocumentsPointAtThisServer(): void
	{
		$server = OAuthFlow::decode(OAuthFlow::json('GET', Routes::OAuthServerMetadata->value, []));
		$resource = OAuthFlow::decode(OAuthFlow::json('GET', Routes::OAuthResourceMetadata->value, []));

		self::assertSame('http://localhost:4400/mcp', $server['issuer']);
		self::assertSame('http://localhost:4400/oauth/authorize', $server['authorization_endpoint']);
		self::assertSame('http://localhost:4400/mcp/oauth/token', $server['token_endpoint']);
		self::assertSame(['S256'], $server['code_challenge_methods_supported']);
		self::assertSame('http://localhost:4400/mcp', $resource['resource']);
	}

	public function testFullFlowAndRefreshRotation(): void
	{
		$user = Fixture::createUser();
		$clientId = OAuthFlow::registerClient();

		$info = OAuthFlow::decode(OAuthFlow::json('GET', Routes::OAuthClientInfo->value . '?client_id=' . $clientId, []));
		self::assertSame('Claude Code', $info['clientName']);

		$tokens = OAuthFlow::exchange($clientId, OAuthFlow::authorize($user, $clientId));
		self::assertIsString($tokens['access_token']);
		self::assertIsString($tokens['refresh_token']);
		self::assertSame(3600, $tokens['expires_in']);

		$refreshed = OAuthFlow::decode(OAuthFlow::form(Routes::OAuthToken->value, [
			'grant_type' => 'refresh_token',
			'refresh_token' => $tokens['refresh_token'],
			'client_id' => $clientId,
		]));
		self::assertIsString($refreshed['access_token']);
		self::assertNotSame($tokens['access_token'], $refreshed['access_token']);

		// Replaying the rotated refresh token revokes the whole family, including the new pair.
		$replay = OAuthFlow::form(Routes::OAuthToken->value, [
			'grant_type' => 'refresh_token',
			'refresh_token' => $tokens['refresh_token'],
			'client_id' => $clientId,
		]);
		self::assertSame(400, $replay->getStatusCode());
		self::assertIsString($refreshed['refresh_token']);
		$afterReplay = OAuthFlow::form(Routes::OAuthToken->value, [
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshed['refresh_token'],
			'client_id' => $clientId,
		]);
		self::assertSame(400, $afterReplay->getStatusCode());
	}

	public function testCodeIsSingleUseAndNeedsTheRightVerifier(): void
	{
		$user = Fixture::createUser();
		$clientId = OAuthFlow::registerClient();

		$wrongVerifier = OAuthFlow::exchange(
			$clientId,
			OAuthFlow::authorize($user, $clientId),
			'another-verifier-0123456789-0123456789-0123',
		);
		self::assertSame('invalid_grant', $wrongVerifier['error']);

		$code = OAuthFlow::authorize($user, $clientId);
		self::assertArrayHasKey('access_token', OAuthFlow::exchange($clientId, $code));
		self::assertSame('invalid_grant', OAuthFlow::exchange($clientId, $code)['error']);
	}

	public function testAuthorizeNeedsSignedInUserAndValidRequest(): void
	{
		$user = Fixture::createUser();
		$clientId = OAuthFlow::registerClient();
		$request = [
			'clientId' => $clientId,
			'redirectUri' => OAuthFlow::RedirectUri,
			'codeChallenge' => OAuthFlow::challenge(),
			'codeChallengeMethod' => 'S256',
		];

		self::assertSame(401, OAuthFlow::json('POST', Routes::OAuthAuthorize->value, $request)->getStatusCode());
		self::assertSame(
			400,
			OAuthFlow::json('POST', Routes::OAuthAuthorize->value, ['codeChallengeMethod' => 'plain'] + $request, $user)->getStatusCode(),
		);
		self::assertSame(
			400,
			OAuthFlow::json(
				'POST',
				Routes::OAuthAuthorize->value,
				['redirectUri' => 'https://evil.example/cb'] + $request,
				$user,
			)->getStatusCode(),
		);
		self::assertSame(
			400,
			OAuthFlow::json(
				'POST',
				Routes::OAuthAuthorize->value,
				['resource' => 'https://evil.example/mcp'] + $request,
				$user,
			)->getStatusCode(),
		);
	}

	public function testRegistrationRejectsUnsafeRedirectUris(): void
	{
		self::assertSame(
			400,
			OAuthFlow::json('POST', Routes::OAuthRegister->value, ['redirect_uris' => ['javascript:alert(1)']])->getStatusCode(),
		);
		self::assertSame(
			400,
			OAuthFlow::json('POST', Routes::OAuthRegister->value, ['redirect_uris' => ['http://example.com/cb']])->getStatusCode(),
		);
		self::assertSame(400, OAuthFlow::json('POST', Routes::OAuthRegister->value, ['redirect_uris' => []])->getStatusCode());
		self::assertSame(
			201,
			OAuthFlow::json(
				'POST',
				Routes::OAuthRegister->value,
				['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']],
			)->getStatusCode(),
		);
	}

	public function testUsersSeeAndRevokeTheirClients(): void
	{
		$user = Fixture::createUser();
		$token = OAuthFlow::accessToken($user, 'Claude Desktop');

		$clients = $this->jsonList($this->request('GET', Routes::CurrentUserMcpClients->value, authenticatedAs: $user));
		self::assertSame(['Claude Desktop'], array_column($clients, 'clientName'));
		self::assertSame([true], array_column($clients, 'active'));
		self::assertIsString($clients[0]['clientId']);

		$this->request(
			'POST',
			str_replace('{clientId:[a-f0-9]+}', $clients[0]['clientId'], Routes::CurrentUserMcpClientRevoke->value),
			[],
			$user,
		);

		self::assertSame(
			[false],
			array_column($this->jsonList($this->request('GET', Routes::CurrentUserMcpClients->value, authenticatedAs: $user)), 'active'),
		);
		$mcp = $this->request('POST', Routes::Mcp->value, [], bearerToken: $token);
		self::assertSame(401, $mcp->getStatusCode());
		self::assertStringContainsString('resource_metadata=', $mcp->getHeaderLine('WWW-Authenticate'));
	}
}
