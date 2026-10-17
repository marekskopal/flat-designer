<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\ProjectDto;
use FlatDesigner\Dto\ProjectInputDto;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Projects of the signed-in user (all projects for admins). Others' projects answer 404. */
final readonly class ProjectController
{
	public const string NotFound = 'Project not found.';

	public function __construct(private ProjectProvider $projectProvider, private RequestService $requestService)
	{
	}

	#[RouteGet(Routes::Projects->value)]
	public function actionGetProjects(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse(array_map(
			fn (Project $project): ProjectDto => $this->projectProvider->toDto($project),
			$this->projectProvider->listAccessible($this->requestService->getUser($request)),
		));
	}

	#[RoutePost(Routes::Projects->value)]
	public function actionPostProject(ServerRequestInterface $request): ResponseInterface
	{
		$input = $this->requestService->getRequestBodyDto($request, ProjectInputDto::class);

		try {
			$project = $this->projectProvider->create($this->requestService->getUser($request), $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->projectProvider->toDto($project), 201);
	}

	#[RouteGet(Routes::Project->value)]
	public function actionGetProject(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(self::NotFound);
		}

		return new JsonResponse($this->projectProvider->toDto($project));
	}

	#[RoutePut(Routes::Project->value)]
	public function actionPutProject(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, ProjectInputDto::class);

		try {
			$this->projectProvider->update($project, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->projectProvider->toDto($project));
	}

	#[RouteDelete(Routes::Project->value)]
	public function actionDeleteProject(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$this->projectProvider->delete($project);

		return new OkResponse();
	}
}
