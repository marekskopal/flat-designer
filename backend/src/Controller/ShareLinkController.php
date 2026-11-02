<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\ShareLinkDto;
use Izbik\Dto\ShareLinkInputDto;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\ShareLink;
use Izbik\OAuth\DiscoveryUrl;
use Izbik\Response\ErrorResponse;
use Izbik\Response\NotFoundResponse;
use Izbik\Response\OkResponse;
use Izbik\Route\Routes;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Project\ShareLinkProvider;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use MarekSkopal\Router\Attribute\RoutePut;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Managing the share links of a project (owner or admin); guests read them through `SharedViewController`. */
final readonly class ShareLinkController
{
	private const string NotFound = 'Share link not found.';

	public function __construct(
		private ProjectProvider $projectProvider,
		private ShareLinkProvider $shareLinkProvider,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectShareLinks->value)]
	public function actionGetShareLinks(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse(array_map(
			fn (ShareLink $link): ShareLinkDto => $this->shareLinkProvider->toDto($link, DiscoveryUrl::appUrl($request)),
			$this->shareLinkProvider->list($project),
		));
	}

	#[RoutePost(Routes::ProjectShareLinks->value)]
	public function actionPostShareLink(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, ShareLinkInputDto::class);

		try {
			$link = $this->shareLinkProvider->create($this->requestService->getUser($request), $project, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->shareLinkProvider->toDto($link, DiscoveryUrl::appUrl($request)), 201);
	}

	#[RoutePut(Routes::ProjectShareLink->value)]
	public function actionPutShareLink(ServerRequestInterface $request, int $projectId, int $shareLinkId): ResponseInterface
	{
		$link = $this->findShareLink($request, $projectId, $shareLinkId);
		if ($link === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$input = $this->requestService->getRequestBodyDto($request, ShareLinkInputDto::class);

		try {
			$this->shareLinkProvider->update($link, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->shareLinkProvider->toDto($link, DiscoveryUrl::appUrl($request)));
	}

	/** Points the link at a new version of the project as it is now (same URL). */
	#[RoutePost(Routes::ProjectShareLinkRefresh->value)]
	public function actionPostShareLinkRefresh(ServerRequestInterface $request, int $projectId, int $shareLinkId): ResponseInterface
	{
		$link = $this->findShareLink($request, $projectId, $shareLinkId);
		if ($link === null) {
			return new NotFoundResponse(self::NotFound);
		}

		try {
			$this->shareLinkProvider->refresh($this->requestService->getUser($request), $link);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->shareLinkProvider->toDto($link, DiscoveryUrl::appUrl($request)));
	}

	#[RouteDelete(Routes::ProjectShareLink->value)]
	public function actionDeleteShareLink(ServerRequestInterface $request, int $projectId, int $shareLinkId): ResponseInterface
	{
		$link = $this->findShareLink($request, $projectId, $shareLinkId);
		if ($link === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$this->shareLinkProvider->delete($link);

		return new OkResponse();
	}

	private function findProject(ServerRequestInterface $request, int $projectId): ?Project
	{
		return $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
	}

	private function findShareLink(ServerRequestInterface $request, int $projectId, int $shareLinkId): ?ShareLink
	{
		$project = $this->findProject($request, $projectId);

		return $project === null ? null : $this->shareLinkProvider->find($project, $shareLinkId);
	}
}
