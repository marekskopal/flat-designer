<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\CredentialsDto;
use Izbik\Dto\RefreshTokenDto;
use Izbik\Dto\SignUpDto;
use Izbik\Response\ErrorResponse;
use Izbik\Response\NotAuthorizedResponse;
use Izbik\Response\OkResponse;
use Izbik\Route\Routes;
use Izbik\Service\Authentication\AuthenticationService;
use Izbik\Service\Authentication\Exception\AccountLockedException;
use Izbik\Service\Authentication\Exception\AuthenticationException;
use Izbik\Service\Authentication\Exception\InvalidTokenException;
use Izbik\Service\Authentication\TokenService;
use Izbik\Service\Provider\UserProvider;
use Izbik\Service\Request\RequestService;
use Izbik\Validator\PasswordValidator;
use Izbik\Validator\UserFieldValidator;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuthenticationController
{
	public function __construct(
		private AuthenticationService $authenticationService,
		private TokenService $tokenService,
		private UserProvider $userProvider,
		private RequestService $requestService,
	) {
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
	 * accounts. The client follows up with a normal login.
	 */
	#[RoutePost(Routes::AuthenticationSignUp->value)]
	public function actionPostSignUp(ServerRequestInterface $request): ResponseInterface
	{
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
			$this->userProvider->createUser($email, $signUp->password, $name);
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
}
