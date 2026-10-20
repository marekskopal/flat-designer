<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\SavedViewDto;
use FlatDesigner\Dto\SavedViewInputDto;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\SavedView;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Project\SavedViewProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The saved views of a project (owner or admin). */
final readonly class SavedViewController
{
	private const string NotFound = 'View not found.';

	public function __construct(
		private ProjectProvider $projectProvider,
		private SavedViewProvider $viewProvider,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectViews->value)]
	public function actionGetViews(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse(array_map(
			fn (SavedView $view): SavedViewDto => $this->viewProvider->toDto($view),
			$this->viewProvider->list($project),
		));
	}

	#[RoutePost(Routes::ProjectViews->value)]
	public function actionPostView(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}
		$input = $this->requestService->getRequestBodyDto($request, SavedViewInputDto::class);

		try {
			$view = $this->viewProvider->create($this->requestService->getUser($request), $project, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->viewProvider->toDto($view), 201);
	}

	#[RoutePut(Routes::ProjectView->value)]
	public function actionPutView(ServerRequestInterface $request, int $projectId, int $viewId): ResponseInterface
	{
		$view = $this->findView($request, $projectId, $viewId);
		if ($view === null) {
			return new NotFoundResponse(self::NotFound);
		}
		$input = $this->requestService->getRequestBodyDto($request, SavedViewInputDto::class);

		try {
			$this->viewProvider->update($view, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->viewProvider->toDto($view));
	}

	#[RouteDelete(Routes::ProjectView->value)]
	public function actionDeleteView(ServerRequestInterface $request, int $projectId, int $viewId): ResponseInterface
	{
		$view = $this->findView($request, $projectId, $viewId);
		if ($view === null) {
			return new NotFoundResponse(self::NotFound);
		}
		$this->viewProvider->delete($view);

		return new OkResponse();
	}

	private function findProject(ServerRequestInterface $request, int $projectId): ?Project
	{
		return $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
	}

	private function findView(ServerRequestInterface $request, int $projectId, int $viewId): ?SavedView
	{
		$project = $this->findProject($request, $projectId);

		return $project === null ? null : $this->viewProvider->find($project, $viewId);
	}
}
