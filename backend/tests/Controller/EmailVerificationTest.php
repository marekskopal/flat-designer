<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\AuthenticationController;
use Izbik\Controller\CurrentUserController;
use Izbik\Route\Routes;
use Izbik\Service\Authentication\EmailVerificationService;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Mime\Email;

#[CoversClass(AuthenticationController::class)]
#[CoversClass(CurrentUserController::class)]
#[CoversClass(EmailVerificationService::class)]
final class EmailVerificationTest extends IntegrationTestCase
{
	public function testSignUpMailsALinkThatVerifiesTheEmailOnce(): void
	{
		$this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'jane@example.com', 'password' => 'Secret123', 'name' => 'Jane'],
		);
		$accessToken = $this->signIn();
		self::assertFalse($this->me($accessToken)['emailVerified']);

		$token = $this->mailedToken();
		$verified = $this->request('POST', Routes::AuthenticationVerifyEmail->value, ['token' => $token]);
		$again = $this->request('POST', Routes::AuthenticationVerifyEmail->value, ['token' => $token]);

		self::assertSame(200, $verified->getStatusCode());
		self::assertTrue($this->me($accessToken)['emailVerified']);
		self::assertSame(422, $again->getStatusCode());
		self::assertSame('This verification link has already been used.', $this->jsonBody($again)['message'] ?? null);
	}

	public function testSignUpWithARegisteredEmailSendsNothing(): void
	{
		Fixture::createUser('jane@example.com');

		$this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'jane@example.com', 'password' => 'Secret123', 'name' => 'Jane'],
		);

		self::assertSame([], AppHarness::mailer()->sent);
	}

	public function testResendMailsANewLinkAndVoidsNone(): void
	{
		$this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'jane@example.com', 'password' => 'Secret123', 'name' => 'Jane'],
		);
		$accessToken = $this->signIn();

		$resent = $this->request('POST', Routes::CurrentUserResendVerification->value, bearerToken: $accessToken);

		self::assertSame(200, $resent->getStatusCode());
		self::assertCount(2, AppHarness::mailer()->sent);
		$first = self::tokenOf(AppHarness::mailer()->sent[0]);
		self::assertSame(200, $this->request('POST', Routes::AuthenticationVerifyEmail->value, ['token' => $first])->getStatusCode());
		$second = self::tokenOf(AppHarness::mailer()->sent[1]);
		self::assertSame(422, $this->request('POST', Routes::AuthenticationVerifyEmail->value, ['token' => $second])->getStatusCode());
	}

	public function testResendIsRefusedForAVerifiedEmailAndCappedPerHour(): void
	{
		$verified = Fixture::createUser('joe@example.com');
		self::assertSame(
			422,
			$this->request('POST', Routes::CurrentUserResendVerification->value, authenticatedAs: $verified)->getStatusCode(),
		);

		$this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'jane@example.com', 'password' => 'Secret123', 'name' => 'Jane'],
		);
		$accessToken = $this->signIn();
		for ($i = 0; $i < 6; $i++) {
			$this->request('POST', Routes::CurrentUserResendVerification->value, bearerToken: $accessToken);
		}

		self::assertCount(5, AppHarness::mailer()->sent);
	}

	public function testAnAdminChangingTheEmailUnverifiesItAndVoidsLinksToTheOldAddress(): void
	{
		$admin = Fixture::createAdmin();
		$this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'jane@example.com', 'password' => 'Secret123', 'name' => 'Jane'],
		);
		$accessToken = $this->signIn();
		$userId = $this->me($accessToken)['id'];
		self::assertIsInt($userId);
		$oldLink = $this->mailedToken();

		$this->request(
			'PATCH',
			str_replace('{userId:number}', (string) $userId, Routes::AdminUser->value),
			['email' => 'jane.doe@example.com'],
			authenticatedAs: $admin,
		);
		$response = $this->request('POST', Routes::AuthenticationVerifyEmail->value, ['token' => $oldLink]);

		self::assertSame(422, $response->getStatusCode());
		self::assertSame('This verification link is for an earlier email address.', $this->jsonBody($response)['message'] ?? null);
		self::assertFalse($this->me($accessToken)['emailVerified']);
	}

	public function testAnAdminChangingAVerifiedEmailUnverifiesIt(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser('jane@example.com');

		$this->request(
			'PATCH',
			str_replace('{userId:number}', (string) $user->id, Routes::AdminUser->value),
			['email' => 'jane.doe@example.com'],
			authenticatedAs: $admin,
		);

		self::assertFalse($this->jsonBody($this->request('GET', Routes::CurrentUser->value, authenticatedAs: $user))['emailVerified']);
	}

	public function testUnknownLinkIsRefused(): void
	{
		$response = $this->request('POST', Routes::AuthenticationVerifyEmail->value, ['token' => 'abc']);

		self::assertSame(422, $response->getStatusCode());
		self::assertSame('This verification link is invalid.', $this->jsonBody($response)['message'] ?? null);
	}

	private function signIn(): string
	{
		$login = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Secret123']);

		return self::stringField($this->jsonBody($login)['accessToken']);
	}

	/** @return array<string, mixed> */
	private function me(string $accessToken): array
	{
		return $this->jsonBody($this->request('GET', Routes::CurrentUser->value, bearerToken: $accessToken));
	}

	private function mailedToken(): string
	{
		$sent = AppHarness::mailer()->sent;
		self::assertCount(1, $sent);
		self::assertSame('jane@example.com', $sent[0]->getTo()[0]->getAddress());
		self::assertSame('Confirm your email for Izbik', $sent[0]->getSubject());

		return self::tokenOf($sent[0]);
	}

	private static function tokenOf(Email $email): string
	{
		self::assertSame(1, preg_match('#/verify-email\?token=([a-f0-9]{64})#', (string) $email->getTextBody(), $match));

		return $match[1];
	}
}
