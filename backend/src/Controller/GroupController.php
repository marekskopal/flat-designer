<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\GroupInputDto;
use FlatDesigner\Dto\GroupMoveDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Item group operations that change several elements as one change (see `ElementController` for
 * renaming, copying and deleting a group). Like the element routes they take the scene's revision as
 * `If-Match` and answer an `EditResultDto` listing the whole batch.
 */
final readonly class GroupController
{
	public function __construct(
		private SceneEditor $sceneEditor,
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private RequestService $requestService,
	) {
	}

	/** Body `{name, itemIds}`. */
	#[RoutePost(Routes::ProjectFloorGroups->value)]
	public function actionPostGroup(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, GroupInputDto::class);

		return new JsonResponse($this->sceneEditor->createGroup(
			$this->requestService->getUser($request),
			$floor,
			$input->name,
			$input->itemIds,
			$this->requestService->getBaseRevision($request),
		), 201);
	}

	/** Body `{dx, dy, rotation}`, each optional. */
	#[RoutePost(Routes::ProjectFloorGroupMove->value)]
	public function actionPostMove(ServerRequestInterface $request, int $projectId, int $floorId, int $groupId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$move = $this->requestService->getRequestBodyDto($request, GroupMoveDto::class);

		return new JsonResponse($this->sceneEditor->moveGroup(
			$this->requestService->getUser($request),
			$floor,
			$groupId,
			$move->dx,
			$move->dy,
			$move->rotation,
			$this->requestService->getBaseRevision($request),
		));
	}

	#[RoutePost(Routes::ProjectFloorGroupUngroup->value)]
	public function actionPostUngroup(ServerRequestInterface $request, int $projectId, int $floorId, int $groupId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		return new JsonResponse($this->sceneEditor->ungroup(
			$this->requestService->getUser($request),
			$floor,
			$groupId,
			$this->requestService->getBaseRevision($request),
		));
	}

	private function findFloor(ServerRequestInterface $request, int $projectId, int $floorId): ?Floor
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
	}
}
