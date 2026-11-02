<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\FurnitureDefinitionDto;
use Izbik\Dto\FurnitureDefinitionInputDto;
use Izbik\Dto\FurnitureScopeInputDto;
use Izbik\Dto\FurnitureValidationDto;
use Izbik\Dto\FurnitureValidationInputDto;
use Izbik\Model\Entity\FurnitureDefinition;
use Izbik\Model\Entity\User;
use Izbik\Response\ErrorResponse;
use Izbik\Response\NotFoundResponse;
use Izbik\Response\OkResponse;
use Izbik\Route\Routes;
use Izbik\Service\Furniture\DefinitionException;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureDefinitionProvider;
use Izbik\Service\Furniture\FurnitureException;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Request\QueryReader;
use Izbik\Service\Request\RequestService;
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

		return new JsonResponse($this->definitionProvider->toDto($user, $definition, withUsage: true));
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
