<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\DxfImportInputDto;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Dxf\DxfImporter;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Walls and openings from a DXF plan among the project's files: its layers, a preview of what the chosen layers make
 * (nothing written), and the import (one change, taking the scene's revision as `If-Match`).
 */
final readonly class DxfController
{
	public function __construct(
		private DxfImporter $importer,
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectFileDxf->value)]
	public function actionGetDxf(ServerRequestInterface $request, int $projectId, int $fileId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse('Project not found.');
		}

		return new JsonResponse($this->importer->summary($project, $fileId));
	}

	/** Body `{fileId, wallLayers, openingLayers?, units?, rotation?, anchor?, offsetX?, offsetY?}`. */
	#[RoutePost(Routes::ProjectFloorDxfImportPreview->value)]
	public function actionPostPreview(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, DxfImportInputDto::class);

		return new JsonResponse($this->importer->preview($floor->project, $input));
	}

	/** The preview's body plus `wallHeight?, materialId?, doorHeight?, windowSill?, windowHeight?, replaceExisting?`. */
	#[RoutePost(Routes::ProjectFloorDxfImport->value)]
	public function actionPostImport(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$floor = $this->findFloor($request, $projectId, $floorId);
		if ($floor === null) {
			return new NotFoundResponse('Floor not found.');
		}

		$input = $this->requestService->getRequestBodyDto($request, DxfImportInputDto::class);

		return new JsonResponse($this->importer->import(
			$this->requestService->getUser($request),
			$floor,
			$input,
			$this->requestService->getBaseRevision($request),
		), 201);
	}

	private function findFloor(ServerRequestInterface $request, int $projectId, int $floorId): ?Floor
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
	}
}
