<?php

declare(strict_types=1);

namespace Izbik\Controller\Admin;

use Izbik\Dto\AdminUserCreateDto;
use Izbik\Dto\AdminUserUpdateDto;
use Izbik\Dto\UserDto;
use Izbik\Model\Entity\User;
use Izbik\Response\ErrorResponse;
use Izbik\Response\ForbiddenResponse;
use Izbik\Response\NotFoundResponse;
use Izbik\Response\OkResponse;
use Izbik\Route\Routes;
use Izbik\Service\Account\AccountException;
use Izbik\Service\Admin\AdminUserService;
use Izbik\Service\Admin\Exception\AdminUserException;
use Izbik\Service\Provider\UserProvider;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePatch;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AdminUserController
{
	private const string AdminRequired = 'Administrator access required.';

	public function __construct(
		private AdminUserService $adminUserService,
		private UserProvider $userProvider,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::AdminUsers->value)]
	public function actionGetUsers(ServerRequestInterface $request): ResponseInterface
	{
		if (!$this->requestService->getUser($request)->isAdmin()) {
			return new ForbiddenResponse(self::AdminRequired);
		}

		return new JsonResponse(array_map(
			fn (User $user): UserDto => UserDto::fromEntity($user),
			$this->adminUserService->listUsers(),
		));
	}

	#[RoutePost(Routes::AdminUsers->value)]
	public function actionPostUser(ServerRequestInterface $request): ResponseInterface
	{
		if (!$this->requestService->getUser($request)->isAdmin()) {
			return new ForbiddenResponse(self::AdminRequired);
		}

		$dto = $this->requestService->getRequestBodyDto($request, AdminUserCreateDto::class);

		try {
			return new JsonResponse(UserDto::fromEntity($this->adminUserService->createUser($dto)), 201);
		} catch (AdminUserException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}
	}

	#[RoutePatch(Routes::AdminUser->value)]
	public function actionPatchUser(ServerRequestInterface $request, int $userId): ResponseInterface
	{
		if (!$this->requestService->getUser($request)->isAdmin()) {
			return new ForbiddenResponse(self::AdminRequired);
		}

		$target = $this->userProvider->getUser($userId);
		if ($target === null) {
			return new NotFoundResponse('User not found.');
		}

		$dto = $this->requestService->getRequestBodyDto($request, AdminUserUpdateDto::class);
		// It would sign the admin out: their own goes through the account page, which keeps the session.
		if ($dto->password !== null && $target->id === $this->requestService->getUser($request)->id) {
			return new ErrorResponse('Change your own password on the account page.', 422);
		}

		try {
			return new JsonResponse(UserDto::fromEntity($this->adminUserService->updateUser($target, $dto)));
		} catch (AdminUserException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}
	}

	#[RouteDelete(Routes::AdminUser->value)]
	public function actionDeleteUser(ServerRequestInterface $request, int $userId): ResponseInterface
	{
		$actor = $this->requestService->getUser($request);
		if (!$actor->isAdmin()) {
			return new ForbiddenResponse(self::AdminRequired);
		}

		$target = $this->userProvider->getUser($userId);
		if ($target === null) {
			return new NotFoundResponse('User not found.');
		}

		try {
			$this->adminUserService->deleteUser($actor, $target);
		} catch (AdminUserException | AccountException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new OkResponse();
	}
}
