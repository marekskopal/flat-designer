<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Response\ErrorResponse;
use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Project\Transfer\ProjectExporter;
use Izbik\Service\Project\Transfer\ProjectImporter;
use Izbik\Service\Project\Transfer\ProjectImportException;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\StreamFactory;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_OK;

/**
 * Project files (`ProjectBundle`): a project downloaded as one, to move it to another instance or keep it as a backup,
 * and a new project of the user's made from one.
 */
final readonly class ProjectTransferController
{
	public function __construct(
		private ProjectProvider $projectProvider,
		private ProjectExporter $exporter,
		private ProjectImporter $importer,
		private RequestService $requestService,
	) {
	}

	/** `?files=0` leaves the files and models out: the JSON alone. */
	#[RouteGet(Routes::ProjectExport->value)]
	public function actionGetExport(ServerRequestInterface $request, int $projectId): ResponseInterface
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);
		if ($project === null) {
			return new NotFoundResponse(ProjectController::NotFound);
		}
		$name = self::fileName($project->name);
		if (($request->getQueryParams()['files'] ?? '1') === '0') {
			return self::download(
				(new StreamFactory())->createStream($this->exporter->json($project)),
				'application/json',
				$name . '.izbik.json',
			);
		}

		$path = tempnam(sys_get_temp_dir(), 'izbik-export');
		if ($path === false) {
			throw new RuntimeException('Cannot create a temporary file.');
		}

		try {
			$this->exporter->zip($project, $path);
			$handle = fopen($path, 'rb');
			if ($handle === false) {
				throw new RuntimeException('Cannot read the exported project.');
			}
		} finally {
			// The open handle keeps the file readable until the response has been sent.
			if (is_file($path)) {
				unlink($path);
			}
		}

		return self::download((new StreamFactory())->createStreamFromResource($handle), 'application/zip', $name . '.izbik.zip');
	}

	#[RoutePost(Routes::ProjectImport->value)]
	public function actionPostImport(ServerRequestInterface $request): ResponseInterface
	{
		$uploaded = $request->getUploadedFiles()['file'] ?? null;
		if (!$uploaded instanceof UploadedFileInterface) {
			return new ErrorResponse('Missing "file" multipart field.', 422);
		}
		if (in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
			return new ErrorResponse('The project file is too large.', 413);
		}
		if ($uploaded->getError() !== UPLOAD_ERR_OK) {
			return new ErrorResponse('Upload failed with code ' . $uploaded->getError() . '.', 422);
		}

		$path = tempnam(sys_get_temp_dir(), 'izbik-import');
		if ($path === false) {
			throw new RuntimeException('Cannot create a temporary file.');
		}

		try {
			$uploaded->moveTo($path);

			return new JsonResponse($this->importer->import($this->requestService->getUser($request), $path), 201);
		} catch (ProjectImportException $e) {
			return new ErrorResponse($e->getMessage(), $e->getCode());
		} finally {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	private static function download(StreamInterface $stream, string $contentType, string $filename): ResponseInterface
	{
		$ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename) ?? 'project';
		$headers = [
			'Content-Type' => $contentType,
			'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($filename)),
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control' => 'private, no-store',
		];
		$size = $stream->getSize();
		if ($size !== null) {
			$headers['Content-Length'] = (string) $size;
		}

		return new Response($stream, 200, $headers);
	}

	/** The project's name as a file name: letters, digits and dashes, `project` when none is left. */
	private static function fileName(string $name): string
	{
		$slug = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($name)), '-');

		return $slug === '' ? 'project' : mb_substr($slug, 0, 80);
	}
}
