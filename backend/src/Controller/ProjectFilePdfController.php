<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\PdfPageDto;
use Izbik\Dto\ProjectFileDto;
use Izbik\Model\Entity\ProjectFile;
use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Project\Pdf\PdfPage;
use Izbik\Service\Project\Pdf\PdfPageImageService;
use Izbik\Service\Project\ProjectFileProvider;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\StreamFactory;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The pages of a PDF project file: their list, a thumbnail of one, and one made into a PNG file (to lay under a floor). */
final readonly class ProjectFilePdfController
{
	private const string NotFound = 'File not found.';
	private const int DefaultThumbnailSize = 240;

	public function __construct(
		private PdfPageImageService $pdfPages,
		private ProjectProvider $projectProvider,
		private ProjectFileProvider $projectFileProvider,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::ProjectFilePdf->value)]
	public function actionGetPages(ServerRequestInterface $request, int $projectId, int $fileId): ResponseInterface
	{
		$file = $this->findFile($request, $projectId, $fileId);
		if ($file === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$document = $this->pdfPages->document($file);

		return new JsonResponse([
			'pageCount' => $document->pageCount,
			'pages' => array_map(static fn (PdfPage $page): PdfPageDto => PdfPageDto::fromPage($page), $document->pages),
		]);
	}

	/** A PNG of the page, its longer side `?size=` pixels (default 240). */
	#[RouteGet(Routes::ProjectFilePdfPage->value)]
	public function actionGetPage(ServerRequestInterface $request, int $projectId, int $fileId, int $page): ResponseInterface
	{
		$file = $this->findFile($request, $projectId, $fileId);
		if ($file === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$size = $request->getQueryParams()['size'] ?? null;
		$png = $this->pdfPages->thumbnail($file, $page, is_numeric($size) ? (int) $size : self::DefaultThumbnailSize);

		return new Response((new StreamFactory())->createStream($png), 200, [
			'Content-Type' => 'image/png',
			'Content-Length' => (string) strlen($png),
			'X-Content-Type-Options' => 'nosniff',
			// A stored file never changes.
			'Cache-Control' => 'private, max-age=86400',
		]);
	}

	#[RoutePost(Routes::ProjectFilePdfPageImage->value)]
	public function actionPostPageImage(ServerRequestInterface $request, int $projectId, int $fileId, int $page): ResponseInterface
	{
		$file = $this->findFile($request, $projectId, $fileId);
		if ($file === null) {
			return new NotFoundResponse(self::NotFound);
		}

		$image = $this->pdfPages->toImageFile($this->requestService->getUser($request), $file, $page);

		return new JsonResponse(ProjectFileDto::fromEntity($image), 201);
	}

	private function findFile(ServerRequestInterface $request, int $projectId, int $fileId): ?ProjectFile
	{
		$project = $this->projectProvider->findAccessible($this->requestService->getUser($request), $projectId);

		return $project === null ? null : $this->projectFileProvider->find($project, $fileId);
	}
}
