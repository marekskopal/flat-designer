<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\UnderlayDto;
use FlatDesigner\Dto\UnderlayInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Project\UnderlayService;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The plan image under a floor's top view: set (place or calibrate) and remove. Both take `If-Match` and answer the new revision. */
final readonly class UnderlayController
{
	private const string NotFound = 'Floor not found.';

	public function __construct(
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private UnderlayService $underlayService,
		private RequestService $requestService,
	) {
	}

	#[RoutePut(Routes::ProjectFloorUnderlay->value)]
	public function actionPutUnderlay(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, UnderlayInputDto::class);

		try {
			$underlay = $this->underlayService->set($floor, $input, $this->requestService->getBaseRevision($request));
		} catch (ProjectException | EditException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse(['underlay' => UnderlayDto::fromEntity($underlay), 'revision' => $floor->project->revision]);
	}

	#[RouteDelete(Routes::ProjectFloorUnderlay->value)]
	public function actionDeleteUnderlay(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse(self::NotFound);
		}

		try {
			$this->underlayService->remove($floor, $this->requestService->getBaseRevision($request));
		} catch (EditException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse(['underlay' => null, 'revision' => $floor->project->revision]);
	}

	private function findFloor(ServerRequestInterface $request, int $projectId, int $floorId): ?Floor
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
	}
}
