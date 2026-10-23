<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\RoomDetectInputDto;
use FlatDesigner\Dto\WallPolylineInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\Drawing\RoomOutliner;
use FlatDesigner\Service\Editor\Drawing\WallDrawer;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The editor's drawing tools: a run of walls (one change listing the batch) and a room outlined by the
 * walls around a point. Like the element routes they take the scene's revision as `If-Match`.
 */
final readonly class DrawingController
{
	public function __construct(
		private WallDrawer $wallDrawer,
		private RoomOutliner $roomOutliner,
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private RequestService $requestService,
	) {
	}

	/** Body `{points: [[x, y], …], closed?, sagittas?: [mm per wall], name?, type?, thickness?, height?, materialId?}`. */
	#[RoutePost(Routes::ProjectFloorWallPolyline->value)]
	public function actionPostWallPolyline(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, WallPolylineInputDto::class);

		return new JsonResponse($this->wallDrawer->drawWalls(
			$this->requestService->getUser($request),
			$floor,
			$input->points,
			$input->closed,
			$input->fields,
			$this->requestService->getBaseRevision($request),
			$input->sagittas,
		), 201);
	}

	/** Body `{x, y, name?, code?, type?, …}`: any room field except the polygon, which comes from the walls. */
	#[RoutePost(Routes::ProjectFloorRoomDetect->value)]
	public function actionPostRoomDetect(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, RoomDetectInputDto::class);

		return new JsonResponse($this->roomOutliner->createRoom(
			$this->requestService->getUser($request),
			$floor,
			$input->x,
			$input->y,
			$input->fields,
			$this->requestService->getBaseRevision($request),
		), 201);
	}

	private function findFloor(ServerRequestInterface $request, int $projectId, int $floorId): ?Floor
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
	}
}
