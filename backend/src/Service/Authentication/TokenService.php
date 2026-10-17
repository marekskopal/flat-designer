<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Authentication;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use FlatDesigner\Dto\AuthenticationDto;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Authentication\Exception\InvalidTokenException;
use SensitiveParameter;

/**
 * Stateless HS256 JWTs. Access tokens live 1 h, refresh tokens 7 d; both carry the user id, the user's
 * `tokenVersion` (bumping it revokes them) and their type, so neither can stand in for the other.
 */
final readonly class TokenService
{
	public const string TypeAccess = 'access';
	public const string TypeRefresh = 'refresh';

	private const string Algorithm = 'HS256';
	private const int AccessTokenLifetime = 3600;
	private const int RefreshTokenLifetime = 604800;
	private const int MinKeyLength = 32;

	public function __construct(#[SensitiveParameter] private string $key)
	{
		if (strlen($key) < self::MinKeyLength) {
			throw new \RuntimeException(sprintf('AUTHORIZATION_TOKEN_KEY must be at least %d characters.', self::MinKeyLength));
		}
	}

	public static function fromEnv(): self
	{
		return new self((string) getenv('AUTHORIZATION_TOKEN_KEY'));
	}

	public function createAuthentication(User $user): AuthenticationDto
	{
		return new AuthenticationDto(
			accessToken: $this->createToken($user, self::TypeAccess, self::AccessTokenLifetime),
			refreshToken: $this->createToken($user, self::TypeRefresh, self::RefreshTokenLifetime),
			userId: $user->id,
		);
	}

	public function decode(#[SensitiveParameter] string $token, string $expectedType): TokenPayload
	{
		try {
			$claims = JWT::decode($token, new Key($this->key, self::Algorithm));
		} catch (ExpiredException $e) {
			throw new InvalidTokenException('Token is expired.', 0, $e);
		} catch (\UnexpectedValueException | \DomainException | \InvalidArgumentException $e) {
			throw new InvalidTokenException('Token is invalid.', 0, $e);
		}

		$userId = $claims->id ?? null;
		$tokenVersion = $claims->tv ?? null;
		$type = $claims->type ?? null;
		if (!is_int($userId) || !is_int($tokenVersion) || $type !== $expectedType) {
			throw new InvalidTokenException('Token is invalid.');
		}

		return new TokenPayload($userId, $tokenVersion);
	}

	private function createToken(User $user, string $type, int $lifetime): string
	{
		return JWT::encode(
			[
				'id' => $user->id,
				'tv' => $user->tokenVersion,
				'type' => $type,
				'exp' => time() + $lifetime,
			],
			$this->key,
			self::Algorithm,
		);
	}
}
