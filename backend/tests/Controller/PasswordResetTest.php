<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\AuthenticationController;
use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Model\Repository\UserRepository;
use Izbik\Route\Routes;
use Izbik\Service\Authentication\PasswordResetService;
use Izbik\Service\Mail\MailOutbox;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Mime\Email;

#[CoversClass(AuthenticationController::class)]
#[CoversClass(PasswordResetService::class)]
#[CoversClass(MailOutbox::class)]
final class PasswordResetTest extends IntegrationTestCase
{
	public function testOptionsTellWhatTheSignInPageOffers(): void
	{
		$response = $this->request('GET', Routes::AuthenticationOptions->value);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame(
			['googleClientId' => 'test-client.apps.googleusercontent.com', 'mail' => true, 'signUp' => true],
			$this->jsonBody($response),
		);
	}

	public function testResetLinkSetsANewPasswordAndRevokesOldTokens(): void
	{
		$user = Fixture::createUser('jane@example.com', 'Jane');
		$oldToken = Fixture::accessTokenFor($user);

		$requested = $this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => ' Jane@Example.com ']);
		self::assertSame(200, $requested->getStatusCode());

		$token = $this->mailedToken('jane@example.com');
		$confirmed = $this->request(
			'POST',
			Routes::AuthenticationConfirmPasswordReset->value,
			['token' => $token, 'password' => 'NewSecret1'],
		);

		self::assertSame(200, $confirmed->getStatusCode());
		$me = $this->request('GET', Routes::CurrentUser->value, bearerToken: self::stringField($this->jsonBody($confirmed)['accessToken']));
		self::assertSame(200, $me->getStatusCode());
		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value, bearerToken: $oldToken)->getStatusCode());
		$login = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'NewSecret1']);
		self::assertSame(200, $login->getStatusCode());

		$again = $this->request('POST', Routes::AuthenticationConfirmPasswordReset->value, ['token' => $token, 'password' => 'Other1234']);
		self::assertSame(422, $again->getStatusCode());
		self::assertSame('This reset link has already been used.', $this->jsonBody($again)['message'] ?? null);
	}

	public function testResetUnlocksTheAccountAndVoidsOtherLinks(): void
	{
		$user = Fixture::createUser('jane@example.com');
		for ($i = 0; $i < 5; $i++) {
			$this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Wrong1234']);
		}
		$this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => 'jane@example.com']);
		$this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => 'jane@example.com']);
		[$first, $second] = array_map(self::tokenOf(...), AppHarness::mailer()->sent);

		$confirmed = $this->request(
			'POST',
			Routes::AuthenticationConfirmPasswordReset->value,
			['token' => $second, 'password' => 'NewSecret1'],
		);

		self::assertSame(200, $confirmed->getStatusCode());
		$reloaded = $this->userRepository()->findById($user->id);
		self::assertNotNull($reloaded);
		self::assertSame(0, $reloaded->failedLoginAttempts);
		self::assertNull($reloaded->lockedUntil);
		$stale = $this->request('POST', Routes::AuthenticationConfirmPasswordReset->value, ['token' => $first, 'password' => 'Other1234']);
		self::assertSame(422, $stale->getStatusCode());
	}

	public function testUnknownEmailAnswersTheSameAndSendsNothing(): void
	{
		$response = $this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => 'nobody@example.com']);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame([], AppHarness::mailer()->sent);
	}

	public function testTheMailIsInTheAccountsLanguage(): void
	{
		$user = Fixture::createUser('jana@example.com', 'Jana');
		$user->locale = LocaleEnum::Cs;
		$this->userRepository()->persist($user);

		$this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => 'jana@example.com']);

		$mail = AppHarness::mailer()->sent[0] ?? null;
		self::assertInstanceOf(Email::class, $mail);
		self::assertSame('Obnovení hesla k Izbiku', $mail->getSubject());
		self::assertStringContainsString('po dobu 60 minut', (string) $mail->getTextBody());
		self::assertStringContainsString('<html lang="cs">', (string) $mail->getHtmlBody());
		self::tokenOf($mail);
	}

	public function testResetsPerAccountAreCappedSilently(): void
	{
		Fixture::createUser('jane@example.com');

		for ($i = 0; $i < 7; $i++) {
			$response = $this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => 'jane@example.com']);
			self::assertSame(200, $response->getStatusCode());
		}

		self::assertCount(5, AppHarness::mailer()->sent);
	}

	public function testInvalidLinksAndWeakPasswordsAreRefused(): void
	{
		Fixture::createUser('jane@example.com');
		$this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => 'jane@example.com']);
		$token = $this->mailedToken('jane@example.com');

		$unknown = $this->request(
			'POST',
			Routes::AuthenticationConfirmPasswordReset->value,
			['token' => 'abc', 'password' => 'NewSecret1'],
		);
		$weak = $this->request('POST', Routes::AuthenticationConfirmPasswordReset->value, ['token' => $token, 'password' => 'short']);

		self::assertSame(422, $unknown->getStatusCode());
		self::assertSame('This reset link is invalid.', $this->jsonBody($unknown)['message'] ?? null);
		self::assertSame(422, $weak->getStatusCode());
	}

	public function testExpiredLinkIsRefused(): void
	{
		Fixture::createUser('jane@example.com');
		$this->request('POST', Routes::AuthenticationRequestPasswordReset->value, ['email' => 'jane@example.com']);
		$token = $this->mailedToken('jane@example.com');
		AppHarness::pdo()->exec('UPDATE password_reset_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE');

		$response = $this->request(
			'POST',
			Routes::AuthenticationConfirmPasswordReset->value,
			['token' => $token, 'password' => 'NewSecret1'],
		);

		self::assertSame(422, $response->getStatusCode());
		self::assertSame('This reset link has expired.', $this->jsonBody($response)['message'] ?? null);
	}

	private function mailedToken(string $recipient): string
	{
		$sent = AppHarness::mailer()->sent;
		self::assertCount(1, $sent);
		self::assertSame($recipient, $sent[0]->getTo()[0]->getAddress());
		self::assertSame('no-reply@izbik.test', $sent[0]->getFrom()[0]->getAddress());

		return self::tokenOf($sent[0]);
	}

	private static function tokenOf(Email $email): string
	{
		self::assertSame(1, preg_match('#/reset-password\?token=([a-f0-9]{64})#', (string) $email->getTextBody(), $match));

		return $match[1];
	}

	private function userRepository(): UserRepository
	{
		$repository = AppHarness::container()->get(UserRepository::class);
		assert($repository instanceof UserRepository);

		return $repository;
	}
}
