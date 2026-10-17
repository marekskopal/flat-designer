<?php

declare(strict_types=1);

namespace FlatDesigner\OAuth;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\OAuthAuthorization;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\OAuthAuthorizationRepository;
use FlatDesigner\OAuth\Exception\OAuthException;
use MarekSkopal\ORM\Database\DatabaseInterface;

/**
 * Authorization codes and opaque access/refresh tokens for MCP clients (OAuth 2.1 with PKCE). Codes and
 * refresh tokens are single use: consuming one is an atomic 0→1 flip of `revoked`, and replaying a used
 * one revokes its whole token family (RFC 9700).
 */
final readonly class AuthorizationService
{
	private const int AccessTokenLifetime = 3600;
	private const int RefreshTokenLifetime = 2592000;
	private const int CodeLifetime = 60;

	public function __construct(
		private OAuthAuthorizationRepository $oAuthAuthorizationRepository,
		private PkceVerifier $pkceVerifier,
		private DatabaseInterface $database,
	) {
	}

	public function createAuthorizationCode(User $user, string $clientId, string $codeChallenge, string $redirectUri): string
	{
		$code = bin2hex(random_bytes(32));
		$this->oAuthAuthorizationRepository->persist(self::stamp(new OAuthAuthorization(
			clientId: $clientId,
			user: $user,
			authorizationCodeHash: hash('sha256', $code),
			codeChallenge: $codeChallenge,
			codeChallengeMethod: 'S256',
			redirectUri: $redirectUri,
			codeExpires: time() + self::CodeLifetime,
			familyId: bin2hex(random_bytes(16)),
		)));

		return $code;
	}

	public function exchangeCode(string $code, string $codeVerifier, string $clientId, string $redirectUri): OAuthTokenPair
	{
		$codeHash = hash('sha256', $code);
		$authorization = $this->oAuthAuthorizationRepository->findByAuthorizationCodeHash($codeHash)
			?? throw new OAuthException('Invalid authorization code');
		if ($authorization->codeExpires !== null && $authorization->codeExpires < time()) {
			throw new OAuthException('Authorization code has expired');
		}
		if ($authorization->clientId !== $clientId) {
			throw new OAuthException('Client ID mismatch');
		}
		if ($authorization->redirectUri !== $redirectUri) {
			throw new OAuthException('Redirect URI mismatch');
		}

		// Consumed before the PKCE check, so a code allows exactly one verifier attempt.
		if ($authorization->revoked || !$this->consume('authorization_code_hash', $codeHash)) {
			$this->revokeFamily($authorization->familyId);

			throw new OAuthException('Authorization code has already been used');
		}
		if ($authorization->codeChallenge === null || !$this->pkceVerifier->verify($codeVerifier, $authorization->codeChallenge)) {
			throw new OAuthException('PKCE verification failed');
		}

		return $this->issueTokenPair($clientId, $authorization->user, $authorization->familyId);
	}

	public function refreshToken(string $refreshToken, string $clientId): OAuthTokenPair
	{
		$hash = hash('sha256', $refreshToken);
		$authorization = $this->oAuthAuthorizationRepository->findByRefreshTokenHash($hash)
			?? throw new OAuthException('Invalid refresh token');
		if ($authorization->refreshTokenExpires !== null && $authorization->refreshTokenExpires < time()) {
			throw new OAuthException('Refresh token has expired');
		}
		if ($authorization->clientId !== $clientId) {
			throw new OAuthException('Client ID mismatch');
		}

		if ($authorization->revoked || !$this->consume('refresh_token_hash', $hash)) {
			$this->revokeFamily($authorization->familyId);

			throw new OAuthException('Refresh token has been revoked');
		}

		return $this->issueTokenPair($clientId, $authorization->user, $authorization->familyId);
	}

	public function validateAccessToken(string $accessToken): OAuthAuthorization
	{
		$authorization = $this->oAuthAuthorizationRepository->findByAccessTokenHash(hash('sha256', $accessToken))
			?? throw new OAuthException('Invalid access token');
		if ($authorization->revoked) {
			throw new OAuthException('Access token has been revoked');
		}
		if ($authorization->accessTokenExpires !== null && $authorization->accessTokenExpires < time()) {
			throw new OAuthException('Access token has expired');
		}

		return $authorization;
	}

	/** Disconnects a client: revokes every code and token it holds for the user. */
	public function revokeClient(User $user, string $clientId): void
	{
		$this->database->execute(
			'UPDATE oauth_authorizations SET revoked = 1, updated_at = NOW() WHERE user_id = ? AND client_id = ? AND revoked = 0',
			[$user->id, $clientId],
			cached: false,
		);
	}

	/** Flips `revoked` 0→1 for the row holding the hash; true only for the single winning request. */
	private function consume(string $hashColumn, string $hash): bool
	{
		return $this->database->execute(
			sprintf('UPDATE oauth_authorizations SET revoked = 1, updated_at = NOW() WHERE %s = ? AND revoked = 0', $hashColumn),
			[$hash],
			cached: false,
		)->rowCount() === 1;
	}

	private function revokeFamily(?string $familyId): void
	{
		if ($familyId === null) {
			return;
		}

		$this->database->execute(
			'UPDATE oauth_authorizations SET revoked = 1, updated_at = NOW() WHERE family_id = ? AND revoked = 0',
			[$familyId],
			cached: false,
		);
	}

	private function issueTokenPair(string $clientId, User $user, ?string $familyId): OAuthTokenPair
	{
		$accessToken = bin2hex(random_bytes(32));
		$refreshToken = bin2hex(random_bytes(32));
		$this->oAuthAuthorizationRepository->persist(self::stamp(new OAuthAuthorization(
			clientId: $clientId,
			user: $user,
			accessTokenHash: hash('sha256', $accessToken),
			refreshTokenHash: hash('sha256', $refreshToken),
			accessTokenExpires: time() + self::AccessTokenLifetime,
			refreshTokenExpires: time() + self::RefreshTokenLifetime,
			familyId: $familyId,
		)));

		return new OAuthTokenPair(accessToken: $accessToken, refreshToken: $refreshToken, expiresIn: self::AccessTokenLifetime);
	}

	private static function stamp(OAuthAuthorization $authorization): OAuthAuthorization
	{
		$authorization->createdAt = new DateTimeImmutable();
		$authorization->updatedAt = $authorization->createdAt;

		return $authorization;
	}
}
