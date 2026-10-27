<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RoutePatch;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Editing floor elements (items, walls, openings, rooms, plots; groups are created, moved and dissolved through
 * `GroupController`, renamed, copied and deleted — with their items — here; variant sets are made, extended
 * and switched through `VariantSetController`, renamed and decided — keeping the active variant — here). Bodies are JSON objects of the element's
 * fields as the scene serves them; PATCH changes only the given ones. Send the scene's `revision` as
 * `If-Match` to get 409 instead of overwriting someone else's change. Every answer is an
 * `EditResultDto`; rejected edits answer 404/409/422 with a message (see `EditException`).
 */
final readonly class ElementController
{
	private const array Types = [
		'items' => ElementTypeEnum::Item,
		'walls' => ElementTypeEnum::Wall,
		'openings' => ElementTypeEnum::Opening,
		'rooms' => ElementTypeEnum::Room,
		'groups' => ElementTypeEnum::Group,
		'variant-sets' => ElementTypeEnum::VariantSet,
		'plots' => ElementTypeEnum::Plot,
		'stairs' => ElementTypeEnum::Stair,
		'roofs' => ElementTypeEnum::Roof,
		'roof-windows' => ElementTypeEnum::RoofWindow,
	];

	public function __construct(
		private SceneEditor $sceneEditor,
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private RequestService $requestService,
	) {
	}

	#[RoutePost(Routes::ProjectFloorElements->value)]
	public function actionPostElement(ServerRequestInterface $request, int $projectId, int $floorId, string $elementType): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		return new JsonResponse($this->sceneEditor->create(
			$this->requestService->getUser($request),
			$floor,
			self::Types[$elementType],
			$this->requestService->getRequestBody($request),
			$this->requestService->getBaseRevision($request),
		), 201);
	}

	#[RoutePatch(Routes::ProjectFloorElement->value)]
	public function actionPatchElement(
		ServerRequestInterface $request,
		int $projectId,
		int $floorId,
		string $elementType,
		int $elementId,
	): ResponseInterface {
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		return new JsonResponse($this->sceneEditor->update(
			$this->requestService->getUser($request),
			$floor,
			self::Types[$elementType],
			$elementId,
			$this->requestService->getRequestBody($request),
			$this->requestService->getBaseRevision($request),
		));
	}

	#[RouteDelete(Routes::ProjectFloorElement->value)]
	public function actionDeleteElement(
		ServerRequestInterface $request,
		int $projectId,
		int $floorId,
		string $elementType,
		int $elementId,
	): ResponseInterface {
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		return new JsonResponse($this->sceneEditor->delete(
			$this->requestService->getUser($request),
			$floor,
			self::Types[$elementType],
			$elementId,
			$this->requestService->getBaseRevision($request),
		));
	}

	/** The body may override fields of the copy (e.g. its position); an empty body is fine. */
	#[RoutePost(Routes::ProjectFloorElementDuplicate->value)]
	public function actionPostDuplicate(
		ServerRequestInterface $request,
		int $projectId,
		int $floorId,
		string $elementType,
		int $elementId,
	): ResponseInterface {
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		return new JsonResponse($this->sceneEditor->duplicate(
			$this->requestService->getUser($request),
			$floor,
			self::Types[$elementType],
			$elementId,
			$this->requestService->getRequestBody($request),
			$this->requestService->getBaseRevision($request),
		), 201);
	}

	private function findFloor(ServerRequestInterface $request, int $projectId, int $floorId): ?Floor
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
	}
}
