<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\ChangePasswordDto;
use FlatDesigner\Dto\CurrentUserUpdateDto;
use FlatDesigner\Dto\UserDto;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Authentication\TokenService;
use FlatDesigner\Service\Provider\UserProvider;
use FlatDesigner\Service\Request\RequestService;
use FlatDesigner\Validator\PasswordValidator;
use FlatDesigner\Validator\UserFieldValidator;
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

		if ($dto->name !== null) {
			$name = UserFieldValidator::normalizeName($dto->name);
			if ($name === null) {
				return new ErrorResponse('Name is required.', 422);
			}
			$user = $this->userProvider->updateName($user, $name);
		}

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

		if (!password_verify($dto->currentPassword, $user->password)) {
			return new ErrorResponse('Current password is incorrect.', 422);
		}

		if (!PasswordValidator::isValid($dto->newPassword)) {
			return new ErrorResponse(PasswordValidator::Requirements, 422);
		}

		$user = $this->userProvider->updatePassword($user, $dto->newPassword);

		return new JsonResponse($this->tokenService->createAuthentication($user));
	}
}
