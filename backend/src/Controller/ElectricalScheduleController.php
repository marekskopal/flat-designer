<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Request\RequestService;
use Izbik\Service\Schedule\ElectricalScheduleService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The electrical schedule of a project or floor (see ElectricalScheduleService). */
final readonly class ElectricalScheduleController
{
	public function __construct(
		private ProjectProvider $projectProvider,
		private FloorRepository $floorRepository,
		private ElectricalScheduleService $electricalScheduleService,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectElectricalSchedule->value)]
	public function actionGetProjectElectricalSchedule(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse(
			$this->electricalScheduleService->forProject(
				$project,
				LocaleEnum::fromAcceptLanguage($request->getHeaderLine('Accept-Language')),
			),
		);
	}

	#[RouteGet(Routes::ProjectFloorElectricalSchedule->value)]
	public function actionGetFloorElectricalSchedule(ServerRequestInterface $request, int $projectId, int $floorId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		$floor = $project === null ? null : $this->floorRepository->findOneInProject($project->id, $floorId);
		if ($floor === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse(
			$this->electricalScheduleService->forFloor($floor, LocaleEnum::fromAcceptLanguage($request->getHeaderLine('Accept-Language'))),
		);
	}
}
