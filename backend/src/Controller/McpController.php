<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Mcp\McpUserContext;
use Izbik\Mcp\Server\IzbikServer;
use Izbik\Mcp\Session\DatabaseSessionLock;
use Izbik\Mcp\Session\DatabaseSessionStore;
use Izbik\OAuth\AuthorizationService;
use Izbik\OAuth\ClientService;
use Izbik\OAuth\DiscoveryUrl;
use Izbik\OAuth\Exception\OAuthException;
use Izbik\Response\ErrorResponse;
use Izbik\Route\Routes;
use Izbik\Service\Actor\ActorContext;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The MCP endpoint (Streamable HTTP). Requests carry an OAuth access token issued by `OAuthController`;
 * the tools then act as that token's user, and their changes are logged under the client's name.
 */
final readonly class McpController
{
	private const int DefaultSessionTtl = 86400;

	private const int DefaultSessionLockTimeout = 30;

	public function __construct(
		private AuthorizationService $authorizationService,
		private ClientService $clientService,
		private McpUserContext $userContext,
		private ActorContext $actorContext,
		private IzbikServer $server,
		private DatabaseInterface $database,
	) {
	}

	#[RouteGet(Routes::Mcp->value)]
	public function actionGetMcp(ServerRequestInterface $request): ResponseInterface
	{
		return $this->handle($request);
	}

	#[RoutePost(Routes::Mcp->value)]
	public function actionPostMcp(ServerRequestInterface $request): ResponseInterface
	{
		return $this->handle($request);
	}

	#[RouteDelete(Routes::Mcp->value)]
	public function actionDeleteMcp(ServerRequestInterface $request): ResponseInterface
	{
		return $this->handle($request);
	}

	private function handle(ServerRequestInterface $request): ResponseInterface
	{
		$header = $request->getHeaderLine('Authorization');
		$token = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
		if ($token === '') {
			return $this->unauthorized($request, 'Missing Authorization header. Expected: Bearer <access_token>');
		}

		try {
			$authorization = $this->authorizationService->validateAccessToken($token);
		} catch (OAuthException) {
			return $this->unauthorized($request, 'Invalid or expired access token.');
		}

		$this->userContext->setUser($authorization->user, DiscoveryUrl::baseUrl($request));
		$client = $this->clientService->findByClientId($authorization->clientId);
		$this->actorContext->setAgent($authorization->clientId, $client->clientName ?? $authorization->clientId);

		$ttl = (int) getenv('MCP_SESSION_TTL');
		$server = $this->server->build(
			new DatabaseSessionStore($this->database, $ttl > 0 ? $ttl : self::DefaultSessionTtl),
			new DatabaseSessionLock($this->database, $this->sessionLockTimeout()),
		);

		$transport = new StreamableHttpTransport($request, middleware: [
			new CorsMiddleware(),
			new DnsRebindingProtectionMiddleware(allowedHosts: $this->allowedHosts()),
			new ProtocolVersionMiddleware(),
		]);

		return $server->run($transport);
	}

	/** How long a request waits for another request of the same session to finish (then 503). */
	private function sessionLockTimeout(): int
	{
		$configured = getenv('MCP_SESSION_LOCK_TIMEOUT');

		return is_string($configured) && is_numeric($configured) ? (int) $configured : self::DefaultSessionLockTimeout;
	}

	/** Points the client at the resource metadata, which starts its OAuth flow (RFC 9728). */
	private function unauthorized(ServerRequestInterface $request, string $message): ResponseInterface
	{
		return new ErrorResponse($message, 401, [
			'WWW-Authenticate' => sprintf(
				'Bearer resource_metadata="%s%s"',
				DiscoveryUrl::baseUrl($request),
				Routes::OAuthResourceMetadata->value,
			),
		]);
	}

	/**
	 * Hosts the transport's DNS-rebinding protection accepts: loopback, plus `PROXY_HOST` when the app is
	 * served under a public name.
	 *
	 * @return list<string>
	 */
	private function allowedHosts(): array
	{
		$hosts = ['localhost', '127.0.0.1', '[::1]'];
		$proxyHost = (string) getenv('PROXY_HOST');
		if ($proxyHost !== '') {
			$hosts[] = strtolower($proxyHost);
		}

		return $hosts;
	}
}
