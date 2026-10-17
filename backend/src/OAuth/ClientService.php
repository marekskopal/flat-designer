<?php

declare(strict_types=1);

namespace FlatDesigner\OAuth;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\OAuthClient;
use FlatDesigner\Model\Repository\OAuthClientRepository;
use FlatDesigner\OAuth\Exception\OAuthException;
use MarekSkopal\ORM\Database\DatabaseInterface;
use const JSON_THROW_ON_ERROR;

/** Registered OAuth clients (RFC 7591 dynamic registration, open: public clients with PKCE). */
final readonly class ClientService
{
	public const int MaxRedirectUris = 10;
	public const int MaxClientNameLength = 100;
	public const int MaxRedirectUriLength = 2000;

	/** Anonymous registrations that never completed an authorization are purged after this long. */
	private const string AnonymousClientMaxAge = '30 days';

	private const array LoopbackHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];

	public function __construct(private OAuthClientRepository $oAuthClientRepository, private DatabaseInterface $database)
	{
	}

	public function findByClientId(string $clientId): ?OAuthClient
	{
		return $this->oAuthClientRepository->findByClientId($clientId);
	}

	public function validateRedirectUri(string $clientId, string $redirectUri): bool
	{
		$client = $this->oAuthClientRepository->findByClientId($clientId);
		if ($client === null) {
			return false;
		}

		$allowedUris = json_decode($client->redirectUris, true, 2, JSON_THROW_ON_ERROR);
		foreach (is_array($allowedUris) ? $allowedUris : [] as $allowedUri) {
			if (is_string($allowedUri) && self::matchesRedirectUri($allowedUri, $redirectUri)) {
				return true;
			}
		}

		return false;
	}

	/** @param list<string> $redirectUris */
	public function registerClient(string $clientName, array $redirectUris): OAuthClient
	{
		if (count($redirectUris) > self::MaxRedirectUris) {
			throw new OAuthException(sprintf('Too many redirect_uris (max %d)', self::MaxRedirectUris));
		}
		foreach ($redirectUris as $redirectUri) {
			if (strlen($redirectUri) > self::MaxRedirectUriLength) {
				throw new OAuthException(sprintf('redirect_uri is too long (max %d characters)', self::MaxRedirectUriLength));
			}
			self::assertSafeRedirectUri($redirectUri);
		}

		$this->garbageCollectAnonymousClients();

		$client = new OAuthClient(
			clientId: bin2hex(random_bytes(16)),
			clientName: self::sanitizeClientName($clientName),
			redirectUris: json_encode($redirectUris, JSON_THROW_ON_ERROR),
			user: null,
		);
		$client->createdAt = new DateTimeImmutable();
		$client->updatedAt = $client->createdAt;
		$this->oAuthClientRepository->persist($client);

		return $client;
	}

	/**
	 * The name is attacker-controlled (open registration) and shown on the consent page, in the user's
	 * connected clients and in the activity list: strip control/invisible characters and cap the length.
	 */
	private static function sanitizeClientName(string $clientName): string
	{
		$clientName = trim((string) preg_replace('/\p{C}+/u', '', $clientName));

		return $clientName === '' ? 'MCP Client' : mb_substr($clientName, 0, self::MaxClientNameLength);
	}

	/**
	 * The consent page navigates to the redirect URI after approval, so a `javascript:`/`data:` URI would
	 * run in the app's origin. Only https is allowed, plus http for loopback (local clients).
	 */
	private static function assertSafeRedirectUri(string $redirectUri): void
	{
		$parsed = parse_url($redirectUri);
		if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
			throw new OAuthException('redirect_uri must be an absolute http(s) URL');
		}

		$scheme = strtolower($parsed['scheme']);
		$isLoopback = in_array(strtolower($parsed['host']), self::LoopbackHosts, true);
		if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
			throw new OAuthException('redirect_uri must use https (http is allowed only for loopback)');
		}
	}

	/** Open registration must not grow the table without bound: drop stale registrations nobody approved. */
	private function garbageCollectAnonymousClients(): void
	{
		$this->database->execute(
			'DELETE c FROM oauth_clients c LEFT JOIN oauth_authorizations a ON a.client_id = c.client_id'
			. ' WHERE c.user_id IS NULL AND a.id IS NULL AND c.created_at < ?',
			[new DateTimeImmutable('-' . self::AnonymousClientMaxAge)->format('Y-m-d H:i:s')],
			cached: false,
		);
	}

	/** Exact match, or for loopback hosts any port with the same scheme and path (OAuth 2.1). */
	private static function matchesRedirectUri(string $allowedUri, string $requestedUri): bool
	{
		if ($allowedUri === $requestedUri) {
			return true;
		}

		$allowed = parse_url($allowedUri);
		$requested = parse_url($requestedUri);
		if ($allowed === false || $requested === false) {
			return false;
		}

		$host = $allowed['host'] ?? '';

		return in_array($host, self::LoopbackHosts, true)
			&& $host === ($requested['host'] ?? '')
			&& ($allowed['scheme'] ?? '') === ($requested['scheme'] ?? '')
			&& ($allowed['path'] ?? '/') === ($requested['path'] ?? '/')
			&& ($allowed['query'] ?? '') === ($requested['query'] ?? '');
	}
}
