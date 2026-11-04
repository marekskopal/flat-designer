<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\AuthenticationOptionsDto;
use Izbik\Dto\ConfirmPasswordResetDto;
use Izbik\Dto\CredentialsDto;
use Izbik\Dto\GoogleLoginDto;
use Izbik\Dto\RefreshTokenDto;
use Izbik\Dto\RequestPasswordResetDto;
use Izbik\Dto\SignUpDto;
use Izbik\Dto\VerifyEmailDto;
use Izbik\OAuth\DiscoveryUrl;
use Izbik\Response\ErrorResponse;
use Izbik\Response\ForbiddenResponse;
use Izbik\Response\NotAuthorizedResponse;
use Izbik\Response\OkResponse;
use Izbik\Route\Routes;
use Izbik\Service\Authentication\AuthenticationService;
use Izbik\Service\Authentication\EmailVerificationService;
use Izbik\Service\Authentication\Exception\AccountLockedException;
use Izbik\Service\Authentication\Exception\AuthenticationException;
use Izbik\Service\Authentication\Exception\GoogleAuthException;
use Izbik\Service\Authentication\Exception\InvalidTokenException;
use Izbik\Service\Authentication\GoogleAuthServiceInterface;
use Izbik\Service\Authentication\PasswordResetService;
use Izbik\Service\Authentication\SignUpConfig;
use Izbik\Service\Authentication\TokenService;
use Izbik\Service\Mail\MailConfig;
use Izbik\Service\Provider\UserProvider;
use Izbik\Service\Request\RequestService;
use Izbik\Validator\PasswordValidator;
use Izbik\Validator\UserFieldValidator;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class AuthenticationController
{
	public const string SignUpClosed = 'This server takes no new sign-ups; ask its administrator for an account.';

	public function __construct(
		private AuthenticationService $authenticationService,
		private TokenService $tokenService,
		private UserProvider $userProvider,
		private RequestService $requestService,
		private PasswordResetService $passwordResetService,
		private EmailVerificationService $emailVerificationService,
		private GoogleAuthServiceInterface $googleAuthService,
		private MailConfig $mailConfig,
		private SignUpConfig $signUpConfig,
		private LoggerInterface $logger,
	) {
	}

	#[RouteGet(Routes::AuthenticationOptions->value)]
	public function actionGetOptions(): ResponseInterface
	{
		return new JsonResponse(
			new AuthenticationOptionsDto(
				googleClientId: $this->googleAuthService->clientId(),
				mail: $this->mailConfig->isEnabled(),
				signUp: $this->signUpConfig->enabled,
			),
		);
	}

	#[RoutePost(Routes::AuthenticationLogin->value)]
	public function actionPostLogin(ServerRequestInterface $request): ResponseInterface
	{
		$credentials = $this->requestService->getRequestBodyDto($request, CredentialsDto::class);

		try {
			return new JsonResponse($this->authenticationService->authenticate($credentials));
		} catch (AccountLockedException $e) {
			return new ErrorResponse(
				'Too many failed sign-in attempts. Please try again later.',
				429,
				['Retry-After' => (string) $e->retryAfterSeconds],
			);
		} catch (AuthenticationException) {
			return new NotAuthorizedResponse('Email or password is invalid.');
		}
	}

	/**
	 * Answers the same whether or not the email is already registered, so the endpoint does not reveal
	 * accounts. The client follows up with a normal login. A new account gets a link to confirm its email. Closed
	 * (`SignUpConfig`), it answers 403.
	 */
	#[RoutePost(Routes::AuthenticationSignUp->value)]
	public function actionPostSignUp(ServerRequestInterface $request): ResponseInterface
	{
		if (!$this->signUpConfig->enabled) {
			return new ForbiddenResponse(self::SignUpClosed);
		}

		$signUp = $this->requestService->getRequestBodyDto($request, SignUpDto::class);

		$email = UserFieldValidator::normalizeEmail($signUp->email);
		if ($email === null) {
			return new ErrorResponse('Invalid email address.', 422);
		}

		$name = UserFieldValidator::normalizeName($signUp->name);
		if ($name === null) {
			return new ErrorResponse('Name is required.', 422);
		}

		if (!PasswordValidator::isValid($signUp->password)) {
			return new ErrorResponse(PasswordValidator::Requirements, 422);
		}

		if ($this->userProvider->getUserByEmail($email) === null) {
			$user = $this->userProvider->createUser($email, $signUp->password, $name);
			$this->emailVerificationService->requestVerification($user, DiscoveryUrl::appUrl($request));
		}

		return new OkResponse();
	}

	#[RoutePost(Routes::AuthenticationRefreshToken->value)]
	public function actionPostRefreshToken(ServerRequestInterface $request): ResponseInterface
	{
		$dto = $this->requestService->getRequestBodyDto($request, RefreshTokenDto::class);

		try {
			$payload = $this->tokenService->decode($dto->refreshToken, TokenService::TypeRefresh);
		} catch (InvalidTokenException $e) {
			return new NotAuthorizedResponse('Refresh token: ' . $e->getMessage());
		}

		$user = $this->userProvider->getUser($payload->userId);
		if ($user === null || $user->tokenVersion !== $payload->tokenVersion) {
			return new NotAuthorizedResponse('Refresh token has been revoked.');
		}

		return new JsonResponse($this->tokenService->createAuthentication($user));
	}

	/** Answers the same whether or not the email has an account; the mail goes out after the response (`MailOutbox`). */
	#[RoutePost(Routes::AuthenticationRequestPasswordReset->value)]
	public function actionPostRequestPasswordReset(ServerRequestInterface $request): ResponseInterface
	{
		$dto = $this->requestService->getRequestBodyDto($request, RequestPasswordResetDto::class);

		if (!$this->mailConfig->isEnabled()) {
			return new ErrorResponse('Password reset by email is not available on this server.', 404);
		}

		$email = UserFieldValidator::normalizeEmail($dto->email);
		if ($email === null) {
			return new ErrorResponse('Invalid email address.', 422);
		}

		$this->passwordResetService->requestReset($email, DiscoveryUrl::appUrl($request));

		return new OkResponse();
	}

	/** Sets the new password and signs the user in; every token issued before is revoked. */
	#[RoutePost(Routes::AuthenticationConfirmPasswordReset->value)]
	public function actionPostConfirmPasswordReset(ServerRequestInterface $request): ResponseInterface
	{
		$dto = $this->requestService->getRequestBodyDto($request, ConfirmPasswordResetDto::class);

		if (!PasswordValidator::isValid($dto->password)) {
			return new ErrorResponse(PasswordValidator::Requirements, 422);
		}

		try {
			$user = $this->passwordResetService->confirmReset($dto->token, $dto->password);
		} catch (RuntimeException $e) {
			return new ErrorResponse($e->getMessage(), 422);
		}

		return new JsonResponse($this->tokenService->createAuthentication($user));
	}

	/**
	 * Signs in with a Google ID token: the account linked to that Google account, else the account with the email Google
	 * verified (which gets linked), else a new account (403 while sign-up is closed).
	 */
	#[RoutePost(Routes::AuthenticationGoogleLogin->value)]
	public function actionPostGoogleLogin(ServerRequestInterface $request): ResponseInterface
	{
		$dto = $this->requestService->getRequestBodyDto($request, GoogleLoginDto::class);

		try {
			$identity = $this->googleAuthService->verifyIdToken($dto->idToken);
		} catch (GoogleAuthException $e) {
			$this->logger->info('Google sign-in failed: ' . $e->getMessage());

			return new NotAuthorizedResponse('Google sign-in failed.');
		}

		$email = UserFieldValidator::normalizeEmail($identity->email);
		if ($email === null) {
			return new NotAuthorizedResponse('Google sign-in failed.');
		}

		$user = $this->userProvider->getUserByGoogleId($identity->subject);
		if ($user === null) {
			$user = $this->userProvider->getUserByEmail($email);
			if ($user?->googleId !== null) {
				return new NotAuthorizedResponse('This email\'s account is linked to another Google account.');
			}
			if ($user === null && !$this->signUpConfig->enabled) {
				return new ForbiddenResponse(self::SignUpClosed);
			}
			$user = $user !== null
				? $this->userProvider->linkGoogleAccount($user, $identity->subject)
				: $this->userProvider->createUserFromGoogle(
					$email,
					UserFieldValidator::normalizeName($identity->name) ?? $email,
					$identity->subject,
				);
		}

		$this->userProvider->recordLogin($user);

		return new JsonResponse($this->tokenService->createAuthentication($user));
	}

	/** The link of a verification mail; the token is the credential, so it works signed out too. */
	#[RoutePost(Routes::AuthenticationVerifyEmail->value)]
	public function actionPostVerifyEmail(ServerRequestInterface $request): ResponseInterface
	{
		$dto = $this->requestService->getRequestBodyDto($request, VerifyEmailDto::class);

		try {
			$this->emailVerificationService->confirm($dto->token);
		} catch (RuntimeException $e) {
			return new ErrorResponse($e->getMessage(), 422);
		}

		return new OkResponse();
	}
}
