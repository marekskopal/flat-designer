<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

use Izbik\Service\Authentication\Exception\GoogleAuthException;
use JsonException;
use SensitiveParameter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use const JSON_THROW_ON_ERROR;

/**
 * Verifies a Google ID token at Google's tokeninfo endpoint, which checks its signature and expiry, then checks that it
 * was issued for this app's client id and that Google has verified the email.
 */
final readonly class GoogleAuthService implements GoogleAuthServiceInterface
{
	private const string TokenInfoUrl = 'https://oauth2.googleapis.com/tokeninfo';

	private HttpClientInterface $httpClient;

	public function __construct(private string $clientId, ?HttpClientInterface $httpClient = null)
	{
		$this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 10]);
	}

	public static function fromEnv(): self
	{
		return new self(trim((string) getenv('GOOGLE_CLIENT_ID')));
	}

	public function clientId(): ?string
	{
		return $this->clientId !== '' ? $this->clientId : null;
	}

	public function verifyIdToken(#[SensitiveParameter] string $idToken): GoogleIdentity
	{
		if ($this->clientId === '') {
			throw new GoogleAuthException('Sign in with Google is not configured.');
		}

		try {
			$response = $this->httpClient->request('GET', self::TokenInfoUrl, ['query' => ['id_token' => $idToken]]);
			$payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
		} catch (HttpClientException | JsonException $e) {
			throw new GoogleAuthException('The Google ID token could not be verified: ' . $e->getMessage(), 0, $e);
		}

		if (!is_array($payload)) {
			throw new GoogleAuthException('The Google ID token could not be verified.');
		}

		$subject = $payload['sub'] ?? null;
		$email = $payload['email'] ?? null;
		$audience = $payload['aud'] ?? null;
		$verified = $payload['email_verified'] ?? null;
		if (!is_string($subject) || $subject === '' || !is_string($email) || $email === '') {
			throw new GoogleAuthException('The Google ID token names no account.');
		}
		if ($audience !== $this->clientId) {
			throw new GoogleAuthException('The Google ID token was issued for another client.');
		}
		if ($verified !== true && $verified !== 'true') {
			throw new GoogleAuthException('Google has not verified the email.');
		}

		$name = $payload['name'] ?? null;

		return new GoogleIdentity(subject: $subject, email: $email, name: is_string($name) && trim($name) !== '' ? $name : $email);
	}
}
