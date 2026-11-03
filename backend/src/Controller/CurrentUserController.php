<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\ChangePasswordDto;
use Izbik\Dto\CurrentUserUpdateDto;
use Izbik\Dto\UserDto;
use Izbik\OAuth\DiscoveryUrl;
use Izbik\Response\ErrorResponse;
use Izbik\Response\OkResponse;
use Izbik\Route\Routes;
use Izbik\Service\Authentication\EmailVerificationService;
use Izbik\Service\Authentication\TokenService;
use Izbik\Service\Mail\MailConfig;
use Izbik\Service\Provider\UserProvider;
use Izbik\Service\Request\RequestService;
use Izbik\Validator\PasswordValidator;
use Izbik\Validator\UserFieldValidator;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePatch;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CurrentUserController
{
	public function __construct(
		private UserProvider $userProvider,
		private TokenService $tokenService,
		private RequestService $requestService,
		private EmailVerificationService $emailVerificationService,
		private MailConfig $mailConfig,
	)
	{
	}

	#[RouteGet(Routes::CurrentUser->value)]
	public function actionGetCurrentUser(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse(UserDto::fromEntity($this->requestService->getUser($request)));
	}

	#[RoutePatch(Routes::CurrentUser->value)]
	public function actionPatchCurrentUser(ServerRequestInterface $request): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$dto = $this->requestService->getRequestBodyDto($request, CurrentUserUpdateDto::class);

		$name = null;
		if ($dto->name !== null) {
			$name = UserFieldValidator::normalizeName($dto->name);
			if ($name === null) {
				return new ErrorResponse('Name is required.', 422);
			}
		}
		$user = $this->userProvider->updateProfile($user, $name, $dto->electricalSymbols);

		return new JsonResponse(UserDto::fromEntity($user));
	}

	/**
	 * Changing the password revokes every token issued so far, so the response carries a fresh pair
	 * for the session that made the change.
	 */
	#[RoutePost(Routes::CurrentUserPassword->value)]
	public function actionPostPassword(ServerRequestInterface $request): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$dto = $this->requestService->getRequestBodyDto($request, ChangePasswordDto::class);

		if ($user->password === null) {
			return new ErrorResponse('Your account has no password yet. Set one with “Forgot password?” on the sign-in page.', 422);
		}
		if (!password_verify($dto->currentPassword, $user->password)) {
			return new ErrorResponse('Current password is incorrect.', 422);
		}

		if (!PasswordValidator::isValid($dto->newPassword)) {
			return new ErrorResponse(PasswordValidator::Requirements, 422);
		}

		$user = $this->userProvider->updatePassword($user, $dto->newPassword);

		return new JsonResponse($this->tokenService->createAuthentication($user));
	}

	/** Mails a new verification link; quietly none past the hourly cap. */
	#[RoutePost(Routes::CurrentUserResendVerification->value)]
	public function actionPostResendVerification(ServerRequestInterface $request): ResponseInterface
	{
		$user = $this->requestService->getUser($request);

		if ($user->emailVerified) {
			return new ErrorResponse('Your email is verified already.', 422);
		}
		if (!$this->mailConfig->isEnabled()) {
			return new ErrorResponse('This server sends no mail.', 404);
		}

		$this->emailVerificationService->requestVerification($user, DiscoveryUrl::appUrl($request));

		return new OkResponse();
	}
}
