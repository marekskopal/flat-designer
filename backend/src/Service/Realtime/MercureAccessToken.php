<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Realtime;

use Firebase\JWT\JWT;
use SensitiveParameter;

/**
 * Mercure protocol 1.0 access tokens: RFC 9068 JWTs (`typ: at+jwt`) whose grants ride in an RFC 9396
 * `authorization_details` claim. The hub (backend/Caddyfile, `issuer` block) accepts only tokens with
 * `iss` = {@see self::Issuer} and `aud` = its resource identifier.
 */
final readonly class MercureAccessToken
{
	public const string Issuer = 'flat-designer';
	public const string ActionPublish = 'publish';
	public const string ActionSubscribe = 'subscribe';

	private const string ClientId = 'flat-designer-backend';
	private const string Algorithm = 'HS256';
	private const string AuthorizationDetailType = 'https://mercure.rocks/authorization-detail';

	/** The hub's resource identifier; docker-entrypoint.sh derives the same value for the hub. */
	public static function resourceIdentifier(): string
	{
		$explicit = (string) getenv('MERCURE_RESOURCE_IDENTIFIER');
		if ($explicit !== '') {
			return $explicit;
		}

		$publishUrl = (string) getenv('MERCURE_PUBLISH_URL');

		return $publishUrl !== '' ? $publishUrl : 'http://localhost/.well-known/mercure';
	}

	/** @param list<string> $topics exact topics, or ["*"] for every topic */
	public static function encode(
		#[SensitiveParameter] string $key,
		string $audience,
		string $subject,
		string $action,
		array $topics,
		int $ttlSeconds,
	): string {
		$now = time();

		return JWT::encode(
			[
				'iss' => self::Issuer,
				'aud' => $audience,
				'sub' => $subject,
				'client_id' => self::ClientId,
				'iat' => $now,
				'exp' => $now + $ttlSeconds,
				'jti' => bin2hex(random_bytes(16)),
				'authorization_details' => [
					[
						'type' => self::AuthorizationDetailType,
						'actions' => [$action],
						'topics' => array_map(static fn (string $topic): array => ['match' => $topic], $topics),
					],
				],
			],
			$key,
			self::Algorithm,
			head: ['typ' => 'at+jwt'],
		);
	}
}
