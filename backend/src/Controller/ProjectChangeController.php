<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\ChangeDto;
use FlatDesigner\Model\Entity\ProjectChange;
use FlatDesigner\Model\Repository\ProjectChangeRepository;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\UndoService;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Activity list of a project, and undo/redo of the signed-in user's own changes. */
final readonly class ProjectChangeController
{
	private const int DefaultLimit = 50;
	private const int MaxLimit = 200;

	public function __construct(
		private ProjectProvider $projectProvider,
		private ProjectChangeRepository $changeRepository,
		private UndoService $undoService,
		private RequestService $requestService,
	) {
	}

	/** Newest first; `?limit=` up to 200. */
	#[RouteGet(Routes::ProjectChanges->value)]
	public function actionGetChanges(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		$limit = $request->getQueryParams()['limit'] ?? null;
		$limit = is_string($limit) && ctype_digit($limit) ? min((int) $limit, self::MaxLimit) : self::DefaultLimit;

		return new JsonResponse(array_map(
			fn (ProjectChange $change): ChangeDto => ChangeDto::fromEntity($change),
			$this->changeRepository->findRecent($project->id, max(1, $limit)),
		));
	}

	#[RoutePost(Routes::ProjectUndo->value)]
	public function actionPostUndo(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$project = $this->projectProvider->findAccessible($user, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse($this->undoService->undo($user, $project));
	}

	#[RoutePost(Routes::ProjectRedo->value)]
	public function actionPostRedo(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$user = $this->requestService->getUser($request);
		$project = $this->projectProvider->findAccessible($user, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse($this->undoService->redo($user, $project));
	}
}
