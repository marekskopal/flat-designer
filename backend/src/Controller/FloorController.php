<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\FloorProvider;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Provider\SceneProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Floors of the working copy of a project, and the viewer scene of one floor. */
final readonly class FloorController
{
	private const string NotFound = 'Floor not found.';

	public function __construct(
		private ProjectProvider $projectProvider,
		private FloorProvider $floorProvider,
		private FloorRepository $floorRepository,
		private SceneProvider $sceneProvider,
		private RequestService $requestService,
	) {
	}

	#[RoutePost(Routes::ProjectFloors->value)]
	public function actionPostFloor(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, FloorInputDto::class);

		try {
			return new JsonResponse(FloorDto::fromEntity($this->floorProvider->create($project, $input)), 201);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}
	}

	#[RoutePut(Routes::ProjectFloor->value)]
	public function actionPutFloor(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, FloorInputDto::class);

		try {
			return new JsonResponse(FloorDto::fromEntity($this->floorProvider->update($floor, $input)));
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}
	}

	#[RouteDelete(Routes::ProjectFloor->value)]
	public function actionDeleteFloor(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse(self::NotFound);
		}

		try {
			$this->floorProvider->delete($floor);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new OkResponse();
	}

	#[RouteGet(Routes::ProjectFloorScene->value)]
	public function actionGetScene(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse(self::NotFound);
		}

		return new JsonResponse($this->sceneProvider->getScene($floor));
	}

	private function findFloor(ServerRequestInterface $request, int $projectId, int $floorId): ?Floor
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
	}
}
