<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\FurnitureDefinitionDto;
use FlatDesigner\Dto\FurnitureDefinitionInputDto;
use FlatDesigner\Dto\FurnitureScopeInputDto;
use FlatDesigner\Dto\FurnitureValidationDto;
use FlatDesigner\Dto\FurnitureValidationInputDto;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Furniture\DefinitionValidator;
use FlatDesigner\Service\Furniture\FurnitureDefinitionProvider;
use FlatDesigner\Service\Furniture\FurnitureException;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Request\QueryReader;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Furniture definitions: global ones (admins), the caller's library and projects' own (see FurnitureDefinitionProvider). */
final readonly class FurnitureDefinitionController
{
	private const string NotFound = 'Furniture definition not found.';

	public function __construct(
		private FurnitureDefinitionProvider $definitionProvider,
		private DefinitionValidator $validator,
		private ProjectProvider $projectProvider,
		private RequestService $requestService,
	) {
	}

	/** Global ones and the caller's library; with `?projectId=` also the project's and its owner's library. */
	#[RouteGet(Routes::FurnitureDefinitions->value)]
	public function actionGetDefinitions(ServerRequestInterface $request): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$projectId = QueryReader::optionalInt($request, 'projectId');
		$project = null;
		if ($projectId !== null) {
			$project = $this->projectProvider->findAccessible($user, $projectId);
			if ($project === null) {
				return new NotFoundResponse(ProjectController::NotFound);
			}
		}

		return new JsonResponse(array_map(
			fn (FurnitureDefinition $definition): FurnitureDefinitionDto => $this->definitionProvider->toDto($user, $definition),
			$this->definitionProvider->listVisible($user, $project),
		));
	}

	#[RoutePost(Routes::FurnitureDefinitions->value)]
	public function actionPostDefinition(ServerRequestInterface $request): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$input = $this->requestService->getRequestBodyDto($request, FurnitureDefinitionInputDto::class);

		try {
			$definition = $this->definitionProvider->create($user, $input);
		} catch (FurnitureException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->definitionProvider->toDto($user, $definition), 201);
	}

	/** Checks a definition without saving it; answers 200 either way (`valid`, the parts or the error with its path). */
	#[RoutePost(Routes::FurnitureDefinitionValidate->value)]
	public function actionPostValidate(ServerRequestInterface $request): ResponseInterface
	{
		$input = $this->requestService->getRequestBodyDto($request, FurnitureValidationInputDto::class);

		try {
			return new JsonResponse(FurnitureValidationDto::valid($this->validator->validate($input->definition)->parts));
		} catch (DefinitionException $e) {
			return new JsonResponse(FurnitureValidationDto::invalid($e));
		}
	}

	#[RouteGet(Routes::FurnitureDefinition->value)]
	public function actionGetDefinition(ServerRequestInterface $request, int $definitionId): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$definition = $this->definitionProvider->findReadable($user, $definitionId);
		if ($definition === null) {
			return new NotFoundResponse(self::NotFound);
		}

		return new JsonResponse($this->definitionProvider->toDto($user, $definition));
	}

	#[RoutePut(Routes::FurnitureDefinition->value)]
	public function actionPutDefinition(ServerRequestInterface $request, int $definitionId): ResponseInterface
	{
		return $this->withDefinition(
			$request,
			$definitionId,
			function (User $user, FurnitureDefinition $definition) use ($request): ResponseInterface {
				$input = $this->requestService->getRequestBodyDto($request, FurnitureDefinitionInputDto::class);
				$this->definitionProvider->update($user, $definition, $input);

				return new JsonResponse($this->definitionProvider->toDto($user, $definition));
			},
		);
	}

	#[RouteDelete(Routes::FurnitureDefinition->value)]
	public function actionDeleteDefinition(ServerRequestInterface $request, int $definitionId): ResponseInterface
	{
		return $this->withDefinition($request, $definitionId, function (User $user, FurnitureDefinition $definition): ResponseInterface {
			$this->definitionProvider->delete($user, $definition);

			return new OkResponse();
		});
	}

	/** Copies it to `scope` (+ `projectId`); the copy is independent of the source. */
	#[RoutePost(Routes::FurnitureDefinitionCopy->value)]
	public function actionPostCopy(ServerRequestInterface $request, int $definitionId): ResponseInterface
	{
		return $this->withDefinition(
			$request,
			$definitionId,
			function (User $user, FurnitureDefinition $definition) use ($request): ResponseInterface {
				$input = $this->requestService->getRequestBodyDto($request, FurnitureScopeInputDto::class);
				$copy = $this->definitionProvider->copy($user, $definition, $input->scope, $input->projectId);

				return new JsonResponse($this->definitionProvider->toDto($user, $copy), 201);
			},
		);
	}

	/** @param callable(User, FurnitureDefinition): ResponseInterface $action */
	private function withDefinition(ServerRequestInterface $request, int $definitionId, callable $action): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$definition = $this->definitionProvider->findReadable($user, $definitionId);
		if ($definition === null) {
			return new NotFoundResponse(self::NotFound);
		}

		try {
			return $action($user, $definition);
		} catch (FurnitureException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}
	}
}
