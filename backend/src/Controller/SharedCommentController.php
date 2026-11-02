<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\CommentDto;
use Izbik\Dto\CommentInputDto;
use Izbik\Model\Entity\Comment;
use Izbik\Model\Entity\Enum\ShareCommentsEnum;
use Izbik\Model\Entity\ShareLink;
use Izbik\Response\ErrorResponse;
use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Comment\CommentProvider;
use Izbik\Service\Comment\SharedComments;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\ShareLinkProvider;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public (no sign-in) review comments of a share link: the open threads of its floor, when the link shows them, and a
 * guest's new comment, when it allows writing (`ShareSettingsDto::$comments`). Unknown or expired link: 404.
 */
final readonly class SharedCommentController
{
	private const string NotFound = 'This link does not exist or has expired.';

	public function __construct(
		private ShareLinkProvider $shareLinkProvider,
		private CommentProvider $commentProvider,
		private SharedComments $sharedComments,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ShareComments->value)]
	public function actionGetComments(ServerRequestInterface $request, string $token): ResponseInterface
	{
		$link = $this->shareLinkProvider->activeLink($token);
		if ($link === null) {
			return new NotFoundResponse(self::NotFound);
		}
		if (self::access($link) === ShareCommentsEnum::Hidden) {
			return new JsonResponse([]);
		}

		return new JsonResponse(array_map(
			fn (Comment $comment): CommentDto => $this->commentProvider->toDto($comment, null),
			$this->sharedComments->list($link),
		));
	}

	#[RoutePost(Routes::ShareComments->value)]
	public function actionPostComment(ServerRequestInterface $request, string $token): ResponseInterface
	{
		$link = $this->shareLinkProvider->activeLink($token);
		if ($link === null) {
			return new NotFoundResponse(self::NotFound);
		}
		if (self::access($link) !== ShareCommentsEnum::Write) {
			return new ErrorResponse('This link does not take comments.', 403);
		}
		$input = $this->requestService->getRequestBodyDto($request, CommentInputDto::class);

		try {
			$comment = $this->sharedComments->add($link, $input);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse($this->commentProvider->toDto($comment, null), 201);
	}

	private static function access(ShareLink $link): ShareCommentsEnum
	{
		return ShareLinkProvider::readSettings($link)->comments;
	}
}
