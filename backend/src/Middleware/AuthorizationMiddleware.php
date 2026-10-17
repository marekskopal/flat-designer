<?php

declare(strict_types=1);

namespace FlatDesigner\Middleware;

use FlatDesigner\Middleware\Exception\NotAuthorizedException;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Authentication\Exception\InvalidTokenException;
use FlatDesigner\Service\Authentication\TokenService;
use FlatDesigner\Service\Provider\UserProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requires a valid access token (`Authorization: Bearer …`) on every route except `OpenRoutes`, and
 * puts the signed-in user on the request (`RequestService::getUser()`).
 */
final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
	public const string AttributeUser = 'user';

	private const string AuthHeaderType = 'Bearer ';

	private const array OpenRoutes = [
		Routes::Health->value,
		Routes::AuthenticationLogin->value,
		Routes::AuthenticationSignUp->value,
		Routes::AuthenticationRefreshToken->value,
		// MCP authenticates with its own OAuth tokens (McpController); discovery, registration and token
		// exchange are public by design, the consent page looks the client up before sign-in.
		Routes::Mcp->value,
		Routes::OAuthServerMetadata->value,
		Routes::OAuthServerMetadataRoot->value,
		Routes::OAuthResourceMetadata->value,
		Routes::OAuthResourceMetadataRoot->value,
		Routes::OAuthToken->value,
		Routes::OAuthRegister->value,
		Routes::OAuthClientInfo->value,
	];

	public function __construct(private TokenService $tokenService, private UserProvider $userProvider)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($request->getMethod() === 'OPTIONS' || in_array($request->getUri()->getPath(), self::OpenRoutes, true)) {
			return $handler->handle($request);
		}

		$header = $request->getHeaderLine('Authorization');
		if (!str_starts_with($header, self::AuthHeaderType)) {
			throw new NotAuthorizedException('Authorization header with a Bearer token is required.');
		}

		try {
			$payload = $this->tokenService->decode(substr($header, strlen(self::AuthHeaderType)), TokenService::TypeAccess);
		} catch (InvalidTokenException $e) {
			throw new NotAuthorizedException('Access token: ' . $e->getMessage(), $e);
		}

		$user = $this->userProvider->getUser($payload->userId);
		// A token minted before a password change (which bumps the version) is revoked.
		if ($user === null || $user->tokenVersion !== $payload->tokenVersion) {
			throw new NotAuthorizedException('Access token has been revoked.');
		}

		return $handler->handle($request->withAttribute(self::AttributeUser, $user));
	}
}
