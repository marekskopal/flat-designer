<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\ProjectVersionDetailDto;
use FlatDesigner\Dto\ProjectVersionDto;
use FlatDesigner\Dto\ProjectVersionInputDto;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectVersion;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Project\ProjectSnapshotService;
use FlatDesigner\Service\Project\ProjectVersionProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Saving, browsing (read-only) and restoring versions of a project. */
final readonly class ProjectVersionController
{
	private const string NotFound = 'Version not found.';

	public function __construct(
		private ProjectProvider $projectProvider,
		private ProjectVersionProvider $projectVersionProvider,
		private ProjectSnapshotService $snapshotService,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectVersions->value)]
	public function actionGetVersions(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse(array_map(
			fn (ProjectVersion $version): ProjectVersionDto => ProjectVersionDto::fromEntity($version),
			$this->projectVersionProvider->list($project),
		));
	}

	#[RoutePost(Routes::ProjectVersions->value)]
	public function actionPostVersion(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, ProjectVersionInputDto::class);

		try {
			$version = $this->projectVersionProvider->create($this->requestService->getUser($request), $project, $input->note);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse(ProjectVersionDto::fromEntity($version), 201);
	}

	#[RouteGet(Routes::ProjectVersion->value)]
	public function actionGetVersion(ServerRequestInterface $request, int $projectId, int $versionId): ResponseInterface
	{
		$version = $this->findVersion($request, $projectId, $versionId);
		if ($version === null) {
			return new NotFoundResponse(self::NotFound);
		}

		return new JsonResponse(new ProjectVersionDetailDto(
			ProjectVersionDto::fromEntity($version),
			$this->snapshotService->getFloors($version->snapshot),
		));
	}

	#[RouteGet(Routes::ProjectVersionFloorScene->value)]
	public function actionGetFloorScene(ServerRequestInterface $request, int $projectId, int $versionId, int $floorId): ResponseInterface
	{
		$version = $this->findVersion($request, $projectId, $versionId);
		$scene = $version === null ? null : $this->snapshotService->getFloorScene($version->snapshot, $floorId);
		if ($scene === null) {
			return new NotFoundResponse('Floor not found in this version.');
		}

		return new JsonResponse($scene);
	}

	/** Replaces the working copy with the version; answers with the version auto-saved just before. */
	#[RoutePost(Routes::ProjectVersionRestore->value)]
	public function actionPostRestore(ServerRequestInterface $request, int $projectId, int $versionId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		$version = $project === null ? null : $this->projectVersionProvider->find($project, $versionId);
		if ($project === null || $version === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$backup = $this->projectVersionProvider->restore($this->requestService->getUser($request), $project, $version);

		return new JsonResponse(ProjectVersionDto::fromEntity($backup));
	}

	private function findProject(ServerRequestInterface $request, int $projectId): ?Project
	{
		return $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
	}

	private function findVersion(ServerRequestInterface $request, int $projectId, int $versionId): ?ProjectVersion
	{
		$project = $this->findProject($request, $projectId);

		return $project === null ? null : $this->projectVersionProvider->find($project, $versionId);
	}
}
