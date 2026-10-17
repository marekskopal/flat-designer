<?php

declare(strict_types=1);

namespace FlatDesigner\Controller\Admin;

use FlatDesigner\Dto\AdminUserUpdateDto;
use FlatDesigner\Dto\UserDto;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\ForbiddenResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Admin\AdminUserService;
use FlatDesigner\Service\Admin\Exception\AdminUserException;
use FlatDesigner\Service\Provider\UserProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePatch;
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
		} catch (AdminUserException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new OkResponse();
	}
}
