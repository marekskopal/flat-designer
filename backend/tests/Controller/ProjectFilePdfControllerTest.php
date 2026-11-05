<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\ProjectFilePdfController;
use Izbik\Dto\PdfPageDto;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;
use Izbik\Route\Routes;
use Izbik\Service\Project\Pdf\PdfPageImageService;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use Izbik\Tests\Support\Pdf;
use PHPUnit\Framework\Attributes\CoversClass;
use function array_column;
use function getimagesizefromstring;
use function str_replace;

#[CoversClass(ProjectFilePdfController::class)]
#[CoversClass(PdfPageImageService::class)]
#[CoversClass(PdfPageDto::class)]
final class ProjectFilePdfControllerTest extends IntegrationTestCase
{
	private User $user;

	private Project $project;

	protected function setUp(): void
	{
		parent::setUp();

		$this->user = Fixture::createUser();
		$this->project = Fixture::createProject($this->user);
	}

	public function testThePagesWithTheSizesTheirImagesGet(): void
	{
		$fileId = $this->uploadPdf();

		$document = $this->jsonBody($this->request('GET', $this->path(Routes::ProjectFilePdf, $fileId), authenticatedAs: $this->user));

		self::assertSame(2, $document['pageCount']);
		self::assertSame(
			[
				['page' => 1, 'widthPt' => 595, 'heightPt' => 842, 'widthPx' => 1240, 'heightPx' => 1755],
				['page' => 2, 'widthPt' => 1191, 'heightPt' => 842, 'widthPx' => 2482, 'heightPx' => 1755],
			],
			$document['pages'],
		);
	}

	public function testAThumbnail(): void
	{
		$fileId = $this->uploadPdf();

		$response = $this->request('GET', $this->path(Routes::ProjectFilePdfPage, $fileId, 2) . '?size=200', authenticatedAs: $this->user);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
		$size = getimagesizefromstring((string) $response->getBody());
		self::assertNotFalse($size);
		self::assertSame(200, $size[0]);

		$tooLarge = $this->request('GET', $this->path(Routes::ProjectFilePdfPage, $fileId, 2) . '?size=5000', authenticatedAs: $this->user);
		self::assertSame(422, $tooLarge->getStatusCode());
		$missing = $this->request('GET', $this->path(Routes::ProjectFilePdfPage, $fileId, 3), authenticatedAs: $this->user);
		self::assertSame(422, $missing->getStatusCode());
	}

	public function testAPageBecomesAPngFileOfTheProject(): void
	{
		$fileId = $this->uploadPdf();

		$response = $this->request('POST', $this->path(Routes::ProjectFilePdfPageImage, $fileId, 2), authenticatedAs: $this->user);

		self::assertSame(201, $response->getStatusCode());
		$image = $this->jsonBody($response);
		self::assertSame(['Plans – page 2.png', 'image/png'], [$image['filename'], $image['mimeType']]);
		self::assertIsInt($image['id']);
		$content = $this->request('GET', $this->path(Routes::ProjectFileContent, $image['id']), authenticatedAs: $this->user);
		$size = getimagesizefromstring((string) $content->getBody());
		self::assertNotFalse($size);
		self::assertSame([2482, 1755], [$size[0], $size[1]]);

		$files = $this->jsonList($this->request('GET', $this->path(Routes::ProjectFiles), authenticatedAs: $this->user));
		self::assertEqualsCanonicalizing(['Plans.pdf', 'Plans – page 2.png'], array_column($files, 'filename'));
	}

	public function testAMissingPageOrAnotherKindOfFileIsRefused(): void
	{
		$fileId = $this->uploadPdf();
		$missing = $this->request('POST', $this->path(Routes::ProjectFilePdfPageImage, $fileId, 3), authenticatedAs: $this->user);
		self::assertSame(422, $missing->getStatusCode());
		self::assertSame('The PDF has no page 3; its pages are 1–2.', $this->jsonBody($missing)['message']);

		$text = $this->jsonBody($this->upload($this->path(Routes::ProjectFiles), 'notes.txt', 'Kitchen', $this->user))['id'];
		self::assertIsInt($text);
		$notPdf = $this->request('GET', $this->path(Routes::ProjectFilePdf, $text), authenticatedAs: $this->user);
		self::assertSame(422, $notPdf->getStatusCode());
	}

	public function testAnotherUsersProjectIsNotFound(): void
	{
		$fileId = $this->uploadPdf();
		$stranger = Fixture::createUser();

		self::assertSame(
			404,
			$this->request('GET', $this->path(Routes::ProjectFilePdf, $fileId), authenticatedAs: $stranger)->getStatusCode(),
		);
		self::assertSame(
			404,
			$this->request('POST', $this->path(Routes::ProjectFilePdfPageImage, $fileId, 1), authenticatedAs: $stranger)->getStatusCode(),
		);
	}

	private function uploadPdf(): int
	{
		$id = $this->jsonBody($this->upload($this->path(Routes::ProjectFiles), 'Plans.pdf', Pdf::plans(), $this->user))['id'];
		self::assertIsInt($id);

		return $id;
	}

	private function path(Routes $route, ?int $fileId = null, ?int $page = null): string
	{
		return str_replace(
			['{projectId:number}', '{fileId:number}', '{page:number}'],
			[(string) $this->project->id, (string) $fileId, (string) $page],
			$route->value,
		);
	}
}
