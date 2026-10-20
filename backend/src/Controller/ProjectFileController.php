<?php

declare(strict_types=1);

namespace FlatDesigner\Controller;

use FlatDesigner\Dto\ProjectFileDto;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Response\NotFoundResponse;
use FlatDesigner\Response\OkResponse;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\ProjectFileProvider;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Project\ShareLinkProvider;
use FlatDesigner\Service\Request\RequestService;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\StreamFactory;
use MarekSkopal\Router\Attribute\RouteDelete;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

/** Files of a project: list, upload (multipart field `file`), download, delete. */
final readonly class ProjectFileController
{
	private const string NotFound = 'File not found.';

	/**
	 * Content types echoed back on download; anything else is sent as application/octet-stream so a
	 * stored file is never rendered as HTML or script (no text/html, no image/svg+xml).
	 */
	private const array SafeDownloadMimeTypes = [
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/plain',
		'text/csv',
		'application/json',
		'application/zip',
		'video/mp4',
	];

	public function __construct(
		private ProjectProvider $projectProvider,
		private ProjectFileProvider $projectFileProvider,
		private ShareLinkProvider $shareLinkProvider,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectFiles->value)]
	public function actionGetFiles(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		return new JsonResponse(array_map(
			fn (ProjectFile $file): ProjectFileDto => ProjectFileDto::fromEntity($file),
			$this->projectFileProvider->list($project),
		));
	}

	#[RoutePost(Routes::ProjectFiles->value)]
	public function actionPostFile(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->findProject($request, $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}

		$uploaded = $request->getUploadedFiles()['file'] ?? null;
		if (!$uploaded instanceof UploadedFileInterface) {
			return new ErrorResponse('Missing "file" multipart field.', 422);
		}
		if (in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
			return new ErrorResponse('The file is too large.', 413);
		}
		if ($uploaded->getError() !== UPLOAD_ERR_OK) {
			return new ErrorResponse('Upload failed with code ' . $uploaded->getError() . '.', 422);
		}

		try {
			$file = $this->projectFileProvider->upload(
				$this->requestService->getUser($request),
				$project,
				$uploaded->getClientFilename() ?? 'file',
				$uploaded->getStream()->getContents(),
			);
		} catch (ProjectException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		}

		return new JsonResponse(ProjectFileDto::fromEntity($file), 201);
	}

	#[RouteGet(Routes::ProjectFileContent->value)]
	public function actionGetFileContent(ServerRequestInterface $request, int $projectId, int $fileId): ResponseInterface
	{
		$file = $this->findFile($request, $projectId, $fileId);

		return $file === null ? new NotFoundResponse(self::NotFound) : $this->content($file);
	}

	/** For share guests, without sign-in: an image their view shows as a picture. */
	#[RouteGet(Routes::SharePictureContent->value)]
	public function actionGetSharePictureContent(ServerRequestInterface $request, string $token, int $fileId): ResponseInterface
	{
		$file = $this->shareLinkProvider->findSharedPicture($token, $fileId);

		return $file === null ? new NotFoundResponse(self::NotFound) : $this->content($file);
	}

	private function content(ProjectFile $file): ResponseInterface
	{
		return new Response(
			(new StreamFactory())->createStream($this->projectFileProvider->readContent($file)),
			200,
			[
				'Content-Type' => in_array(
					$file->mimeType,
					self::SafeDownloadMimeTypes,
					true,
				) ? $file->mimeType : 'application/octet-stream',
				'Content-Length' => (string) $file->size,
				'Content-Disposition' => self::contentDisposition($file->filename),
				'X-Content-Type-Options' => 'nosniff',
				'Cache-Control' => 'private, no-store',
			],
		);
	}

	#[RouteDelete(Routes::ProjectFile->value)]
	public function actionDeleteFile(ServerRequestInterface $request, int $projectId, int $fileId): ResponseInterface
	{
		$file = $this->findFile($request, $projectId, $fileId);
		if ($file === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$this->projectFileProvider->delete($file);

		return new OkResponse();
	}

	/** ASCII fallback for old clients plus the UTF-8 name (RFC 6266 / 5987), so diacritics survive. */
	private static function contentDisposition(string $filename): string
	{
		$ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?? 'file';

		return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($filename));
	}

	private function findProject(ServerRequestInterface $request, int $projectId): ?Project
	{
		return $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
	}

	private function findFile(ServerRequestInterface $request, int $projectId, int $fileId): ?ProjectFile
	{
		$project = $this->findProject($request, $projectId);

		return $project === null ? null : $this->projectFileProvider->find($project, $fileId);
	}
}
