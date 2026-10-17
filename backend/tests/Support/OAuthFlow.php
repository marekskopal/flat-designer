<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use FlatDesigner\Model\Entity\User;
use FlatDesigner\Route\Routes;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use const JSON_THROW_ON_ERROR;

/** Drives the OAuth endpoints like an MCP client: register, approve as a user, exchange the code. */
final class OAuthFlow
{
	public const string RedirectUri = 'http://localhost:33418/callback';
	public const string Verifier = 'test-verifier-0123456789-0123456789-0123456789-abcdef';

	public static function challenge(string $verifier = self::Verifier): string
	{
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	/** @param array<string, mixed> $body */
	public static function json(string $method, string $path, array $body, ?User $user = null): ResponseInterface
	{
		$request = new ServerRequest([], [], $path, $method);
		parse_str($request->getUri()->getQuery(), $query);
		$request = $request
			->withQueryParams($query)
			->withHeader('Host', 'localhost:4400')
			->withHeader('Content-Type', 'application/json')
			->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
		if ($user !== null) {
			$request = $request->withHeader('Authorization', 'Bearer ' . Fixture::accessTokenFor($user));
		}

		return self::dispatch($request);
	}

	/** @param array<string, string> $form */
	public static function form(string $path, array $form): ResponseInterface
	{
		$request = (new ServerRequest([], [], $path, 'POST'))
			->withHeader('Host', 'localhost:4400')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody($form);

		return self::dispatch($request);
	}

	public static function registerClient(string $name = 'Claude Code'): string
	{
		$body = self::decode(
			self::json('POST', Routes::OAuthRegister->value, ['client_name' => $name, 'redirect_uris' => [self::RedirectUri]]),
		);

		return is_string($body['client_id'] ?? null) ? $body['client_id'] : throw new RuntimeException('Registration failed');
	}

	public static function authorize(User $user, string $clientId): string
	{
		$body = self::decode(self::json('POST', Routes::OAuthAuthorize->value, [
			'clientId' => $clientId,
			'redirectUri' => self::RedirectUri,
			'codeChallenge' => self::challenge(),
			'codeChallengeMethod' => 'S256',
			'state' => 'xyz',
		], $user));

		return is_string($body['code'] ?? null) ? $body['code'] : throw new RuntimeException('Authorization failed');
	}

	/** @return array<string, mixed> token response */
	public static function exchange(string $clientId, string $code, string $verifier = self::Verifier): array
	{
		return self::decode(self::form(Routes::OAuthToken->value, [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'code_verifier' => $verifier,
			'client_id' => $clientId,
			'redirect_uri' => self::RedirectUri,
		]));
	}

	/** Registered client plus access token for the user, as an MCP client ends up after its OAuth flow. */
	public static function accessToken(User $user, string $clientName = 'Claude Code'): string
	{
		$clientId = self::registerClient($clientName);
		$tokens = self::exchange($clientId, self::authorize($user, $clientId));

		return is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : throw new RuntimeException('Token exchange failed');
	}

	/** @return array<string, mixed> */
	public static function decode(ResponseInterface $response): array
	{
		$decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			throw new RuntimeException('Expected a JSON object');
		}

		/** @var array<string, mixed> $object */
		$object = $decoded;

		return $object;
	}

	private static function dispatch(ServerRequest $request): ResponseInterface
	{
		return AppHarness::handle($request);
	}
}
