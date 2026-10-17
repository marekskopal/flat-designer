<?php

declare(strict_types=1);

namespace FlatDesigner\OAuth;

use FlatDesigner\Route\Routes;
use Psr\Http\Message\ServerRequestInterface;
use const PHP_URL_HOST;

/**
 * Base URL advertised in the OAuth discovery documents, and RFC 8707 resource-indicator checks.
 *
 * MCP clients follow the discovery documents blindly, so the URLs must not be attacker-influenceable:
 * the base is built from the `Host` the proxy forwards (never `X-Forwarded-Host`), and when `PROXY_HOST`
 * is configured, any other host gets the canonical `https://PROXY_HOST` instead.
 */
final readonly class DiscoveryUrl
{
	public static function baseUrl(ServerRequestInterface $request): string
	{
		$scheme = $request->getHeaderLine('X-Forwarded-Proto');
		if ($scheme !== 'https' && $scheme !== 'http') {
			$scheme = $request->getUri()->getScheme() === 'https' ? 'https' : 'http';
		}

		$host = $request->getHeaderLine('Host');
		if ($host === '') {
			$host = $request->getUri()->getAuthority();
		}

		$configuredHost = (string) getenv('PROXY_HOST');
		if ($configuredHost !== '' && !self::hostnameMatches($host, $configuredHost)) {
			return 'https://' . $configuredHost;
		}

		return $scheme . '://' . $host;
	}

	public static function mcpUrl(ServerRequestInterface $request): string
	{
		return self::baseUrl($request) . Routes::Mcp->value;
	}

	/**
	 * This server protects exactly one resource, its own MCP endpoint. A `resource` naming anything else
	 * is rejected (no tokens minted here for another resource); an absent one is allowed for clients that
	 * predate resource indicators.
	 */
	public static function resourceMatches(ServerRequestInterface $request, mixed $resource): bool
	{
		if (!is_string($resource) || $resource === '') {
			return true;
		}

		return rtrim($resource, '/') === rtrim(self::mcpUrl($request), '/');
	}

	private static function hostnameMatches(string $hostWithOptionalPort, string $configuredHost): bool
	{
		$hostname = parse_url('//' . $hostWithOptionalPort, PHP_URL_HOST);

		return is_string($hostname) && strtolower($hostname) === strtolower($configuredHost);
	}
}
