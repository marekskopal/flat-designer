<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\VariantInputDto;
use FlatDesigner\Dto\VariantSetInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\VariantEditor;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Variant sets: making one of groups, adding a variant and switching the shown one (see `ElementController`
 * for renaming and deciding a set). Like the element routes they take the scene's revision as `If-Match`
 * and answer an `EditResultDto` listing the batch.
 */
final readonly class VariantSetController
{
	public function __construct(
		private VariantEditor $variantEditor,
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private RequestService $requestService,
	) {
	}

	/** Body `{name, groupIds}`. */
	#[RoutePost(Routes::ProjectFloorVariantSets->value)]
	public function actionPostVariantSet(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, VariantSetInputDto::class);

		return new JsonResponse($this->variantEditor->createVariantSet(
			$this->requestService->getUser($request),
			$floor,
			$input->name,
			$input->groupIds,
			$this->requestService->getBaseRevision($request),
		), 201);
	}

	/** Body `{fromGroupId?, name?}`: a copy of a variant, or an empty one; it is shown from then on. */
	#[RoutePost(Routes::ProjectFloorVariantSetVariants->value)]
	public function actionPostVariant(ServerRequestInterface $request, int $projectId, int $floorId, int $setId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, VariantInputDto::class);

		return new JsonResponse($this->variantEditor->addVariant(
			$this->requestService->getUser($request),
			$floor,
			$setId,
			$input->fromGroupId,
			$input->name,
			$this->requestService->getBaseRevision($request),
		), 201);
	}

	/** Body `{groupId}` (null: show none). */
	#[RoutePost(Routes::ProjectFloorVariantSetSwitch->value)]
	public function actionPostSwitch(ServerRequestInterface $request, int $projectId, int $floorId, int $setId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, VariantInputDto::class);

		return new JsonResponse($this->variantEditor->switchVariant(
			$this->requestService->getUser($request),
			$floor,
			$setId,
			$input->groupId,
			$this->requestService->getBaseRevision($request),
		));
	}

	private function findFloor(ServerRequestInterface $request, int $projectId, int $floorId): ?Floor
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
	}
}
