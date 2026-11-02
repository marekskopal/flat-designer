<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\MaterialPricesDto;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Response\ErrorResponse;
use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Quantities\MaterialPriceService;
use Izbik\Service\Quantities\QuantitiesService;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Quantities and cost estimate of a project or floor (see QuantitiesService) and the project's price list. */
final readonly class QuantitiesController
{
	public function __construct(
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private QuantitiesService $quantitiesService,
		private MaterialPriceService $priceService,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectQuantities->value)]
	public function actionGetProjectQuantities(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse($this->quantitiesService->forProject($project));
	}

	#[RouteGet(Routes::ProjectFloorQuantities->value)]
	public function actionGetFloorQuantities(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		$floor = $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
		if ($floor === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse($this->quantitiesService->forFloor($floor));
	}

	#[RouteGet(Routes::ProjectMaterialPrices->value)]
	public function actionGetMaterialPrices(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse($this->priceService->get($project));
	}

	#[RoutePut(Routes::ProjectMaterialPrices->value)]
	public function actionPutMaterialPrices(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, MaterialPricesDto::class);

		try {
			return new JsonResponse($this->priceService->replace($project, $input));
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}
	}
}
