<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\CommentDto;
use FlatDesigner\Dto\CommentInputDto;
use FlatDesigner\Dto\CommentUpdateDto;
use FlatDesigner\Model\Entity\Comment;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Actor\ActorContext;
use FlatDesigner\Service\Comment\CommentActor;
use FlatDesigner\Service\Comment\CommentProvider;
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

/** The review comments of a project (owner or admin). `?floorId=` limits the list to a floor, `?resolved=0` to open threads. */
final readonly class CommentController
{
	private const string NotFound = 'Comment not found.';

	public function __construct(
		private ProjectProvider $projectProvider,
		private CommentProvider $commentProvider,
		private RequestService $requestService,
		private ActorContext $actorContext,
	) {
	}

	#[RouteGet(Routes::ProjectComments->value)]
	public function actionGetComments(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}
		$query = $request->getQueryParams();
		$floorId = $query['floorId'] ?? null;
		$floorId = is_string($floorId) && ctype_digit($floorId) ? (int) $floorId : null;
		$reader = $this->actor($request);

		return new JsonResponse(array_map(
			fn (Comment $comment): CommentDto => $this->commentProvider->toDto($comment, $reader),
			$this->commentProvider->list($project, $floorId, ($query['resolved'] ?? null) !== '0'),
		));
	}

	#[RoutePost(Routes::ProjectComments->value)]
	public function actionPostComment(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}
		$input = $this->requestService->getRequestBodyDto($request, CommentInputDto::class);
		$actor = $this->actor($request);

		try {
			$comment = $this->commentProvider->create($actor, $project, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->commentProvider->toDto($comment, $actor), 201);
	}

	#[RoutePut(Routes::ProjectComment->value)]
	public function actionPutComment(ServerRequestInterface $request, int $projectId, int $commentId): ResponseInterface
	{
		$comment = $this->findComment($request, $projectId, $commentId);
		if ($comment === null) {
			return new NotFoundResponse(self::NotFound);
		}
		$input = $this->requestService->getRequestBodyDto($request, CommentUpdateDto::class);
		$actor = $this->actor($request);

		try {
			$this->commentProvider->update($actor, $comment, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->commentProvider->toDto($comment, $actor));
	}

	#[RouteDelete(Routes::ProjectComment->value)]
	public function actionDeleteComment(ServerRequestInterface $request, int $projectId, int $commentId): ResponseInterface
	{
		$comment = $this->findComment($request, $projectId, $commentId);
		if ($comment === null) {
			return new NotFoundResponse(self::NotFound);
		}

		try {
			$this->commentProvider->delete($this->actor($request), $comment);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new OkResponse();
	}

	private function actor(ServerRequestInterface $request): CommentActor
	{
		return CommentActor::of($this->requestService->getUser($request), $this->actorContext);
	}

	private function findProject(ServerRequestInterface $request, int $projectId): ?Project
	{
		return $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
	}

	private function findComment(ServerRequestInterface $request, int $projectId, int $commentId): ?Comment
	{
		$project = $this->findProject($request, $projectId);

		return $project === null ? null : $this->commentProvider->find($project, $commentId);
	}
}
