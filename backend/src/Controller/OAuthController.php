<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\OAuth\AuthorizationService;
use FlatDesigner\OAuth\ClientService;
use FlatDesigner\OAuth\DiscoveryUrl;
use FlatDesigner\OAuth\Exception\OAuthException;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use const JSON_THROW_ON_ERROR;

/**
 * OAuth 2.1 authorization server for MCP clients: discovery documents (RFC 8414, RFC 9728), dynamic
 * client registration (RFC 7591), the authorization step behind the app's consent page (`/oauth/authorize`,
 * needs the signed-in user's JWT) and the token endpoint (code + PKCE, refresh rotation).
 */
final readonly class OAuthController
{
	/** The consent page of the frontend. */
	private const string AuthorizePage = '/oauth/authorize';

	public function __construct(
		private AuthorizationService $authorizationService,
		private ClientService $clientService,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::OAuthServerMetadata->value)]
	public function actionGetServerMetadata(ServerRequestInterface $request): ResponseInterface
	{
		$baseUrl = DiscoveryUrl::baseUrl($request);

		return new JsonResponse([
			'issuer' => DiscoveryUrl::mcpUrl($request),
			'authorization_endpoint' => $baseUrl . self::AuthorizePage,
			'token_endpoint' => $baseUrl . Routes::OAuthToken->value,
			'registration_endpoint' => $baseUrl . Routes::OAuthRegister->value,
			'response_types_supported' => ['code'],
			'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported' => ['S256'],
			'token_endpoint_auth_methods_supported' => ['none'],
		]);
	}

	/** Same document at the root path, for clients that do not insert the issuer path. */
	#[RouteGet(Routes::OAuthServerMetadataRoot->value)]
	public function actionGetServerMetadataRoot(ServerRequestInterface $request): ResponseInterface
	{
		return $this->actionGetServerMetadata($request);
	}

	#[RouteGet(Routes::OAuthResourceMetadata->value)]
	public function actionGetResourceMetadata(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse([
			'resource' => DiscoveryUrl::mcpUrl($request),
			'authorization_servers' => [DiscoveryUrl::mcpUrl($request)],
			'bearer_methods_supported' => ['header'],
		]);
	}

	#[RouteGet(Routes::OAuthResourceMetadataRoot->value)]
	public function actionGetResourceMetadataRoot(ServerRequestInterface $request): ResponseInterface
	{
		return $this->actionGetResourceMetadata($request);
	}

	/** The client's display name for the consent page. */
	#[RouteGet(Routes::OAuthClientInfo->value)]
	public function actionGetClientInfo(ServerRequestInterface $request): ResponseInterface
	{
		$clientId = self::string($request->getQueryParams(), 'client_id');
		$client = $clientId === '' ? null : $this->clientService->findByClientId($clientId);
		if ($client === null) {
			return new ErrorResponse('Unknown client_id.', 404);
		}

		return new JsonResponse(['clientName' => $client->clientName]);
	}

	/** The signed-in user approved the client on the consent page: issue a code for its redirect. */
	#[RoutePost(Routes::OAuthAuthorize->value)]
	public function actionPostAuthorize(ServerRequestInterface $request): ResponseInterface
	{
		$body = $this->requestService->getRequestBody($request);
		$clientId = self::string($body, 'clientId');
		$redirectUri = self::string($body, 'redirectUri');
		$codeChallenge = self::string($body, 'codeChallenge');

		if ($clientId === '' || $redirectUri === '' || $codeChallenge === '') {
			return new ErrorResponse('client_id, redirect_uri and code_challenge are required.', 400);
		}
		if (self::string($body, 'codeChallengeMethod') !== 'S256') {
			return new ErrorResponse('code_challenge_method must be "S256".', 400);
		}
		if (!DiscoveryUrl::resourceMatches($request, $body['resource'] ?? null)) {
			return new ErrorResponse('This server only issues tokens for its own MCP endpoint.', 400);
		}
		if (!$this->clientService->validateRedirectUri($clientId, $redirectUri)) {
			return new ErrorResponse('Unknown client_id or redirect_uri.', 400);
		}

		$code = $this->authorizationService->createAuthorizationCode(
			$this->requestService->getUser($request),
			$clientId,
			$codeChallenge,
			$redirectUri,
		);

		return new JsonResponse(['code' => $code, 'redirectUri' => $redirectUri, 'state' => self::string($body, 'state')]);
	}

	#[RoutePost(Routes::OAuthToken->value)]
	public function actionPostToken(ServerRequestInterface $request): ResponseInterface
	{
		$body = $request->getParsedBody();
		$body = is_array($body) ? $body : [];
		if (!DiscoveryUrl::resourceMatches($request, $body['resource'] ?? null)) {
			return new JsonResponse(
				['error' => 'invalid_target', 'error_description' => 'The resource is not served by this authorization server.'],
				400,
			);
		}

		try {
			$pair = match (self::string($body, 'grant_type')) {
				'authorization_code' => $this->authorizationService->exchangeCode(
					code: self::string($body, 'code'),
					codeVerifier: self::string($body, 'code_verifier'),
					clientId: self::string($body, 'client_id'),
					redirectUri: self::string($body, 'redirect_uri'),
				),
				'refresh_token' => $this->authorizationService->refreshToken(
					self::string($body, 'refresh_token'),
					self::string($body, 'client_id'),
				),
				default => throw new OAuthException('Unsupported grant type'),
			};
		} catch (OAuthException $e) {
			return new JsonResponse(['error' => 'invalid_grant', 'error_description' => $e->getMessage()], 400);
		}

		return new JsonResponse(
			[
				'access_token' => $pair->accessToken,
				'token_type' => $pair->tokenType,
				'expires_in' => $pair->expiresIn,
				'refresh_token' => $pair->refreshToken,
			],
			200,
			['Cache-Control' => 'no-store'],
		);
	}

	#[RoutePost(Routes::OAuthRegister->value)]
	public function actionPostRegister(ServerRequestInterface $request): ResponseInterface
	{
		if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
			return new ErrorResponse('Content-Type must be application/json.', 400);
		}

		$body = $this->requestService->getRequestBody($request);
		$redirectUris = [];
		foreach (is_array($body['redirect_uris'] ?? null) ? $body['redirect_uris'] : [] as $uri) {
			if (is_string($uri) && $uri !== '') {
				$redirectUris[] = $uri;
			}
		}
		if ($redirectUris === []) {
			return new ErrorResponse('At least one redirect_uri is required.', 400);
		}

		try {
			$client = $this->clientService->registerClient(self::string($body, 'client_name'), $redirectUris);
		} catch (OAuthException $e) {
			return new ErrorResponse($e->getMessage(), 400);
		}

		return new JsonResponse([
			'client_id' => $client->clientId,
			'client_name' => $client->clientName,
			'redirect_uris' => json_decode($client->redirectUris, true, 2, JSON_THROW_ON_ERROR),
			'grant_types' => ['authorization_code', 'refresh_token'],
			'response_types' => ['code'],
			'token_endpoint_auth_method' => 'none',
		], 201);
	}

	/** @param array<mixed> $params */
	private static function string(array $params, string $key): string
	{
		return is_string($params[$key] ?? null) ? $params[$key] : '';
	}
}
