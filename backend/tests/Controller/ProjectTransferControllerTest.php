<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\ProjectTransferController;
use Izbik\Route\Routes;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\Project\Transfer\ProjectBundle;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ZipArchive;

#[CoversClass(ProjectTransferController::class)]
final class ProjectTransferControllerTest extends IntegrationTestCase
{
	public function testAProjectDownloadedAsAFileImportsAsANewProject(): void
	{
		$author = Fixture::createUser('author@example.com');
		$project = Fixture::createProject($author, 'Byt 12 – Žižkov');
		Fixture::furnishFloor(Fixture::firstFloor($project));
		$files = str_replace('{projectId:number}', (string) $project->id, Routes::ProjectFiles->value);
		self::assertSame(201, $this->upload($files, 'notes.txt', 'Measured on site.', $author)->getStatusCode());

		$export = $this->request('GET', self::exportPath($project->id), authenticatedAs: $author);

		self::assertSame(200, $export->getStatusCode());
		self::assertSame('application/zip', $export->getHeaderLine('Content-Type'));
		self::assertSame(
			'attachment; filename="byt-12-_i_kov.izbik.zip"; filename*=UTF-8\'\'byt-12-%C5%BEi%C5%BEkov.izbik.zip',
			$export->getHeaderLine('Content-Disposition'),
		);
		$zip = (string) $export->getBody();
		self::assertSame(strlen($zip), (int) $export->getHeaderLine('Content-Length'));

		$user = Fixture::createUser('new@example.com');
		$imported = $this->upload(Routes::ProjectImport->value, 'byt-12.izbik.zip', $zip, $user);

		self::assertSame(201, $imported->getStatusCode());
		$body = $this->jsonBody($imported);
		self::assertIsArray($body['project']);
		self::assertSame(['Byt 12 – Žižkov', $user->id], [$body['project']['name'], $body['project']['ownerId']]);
		self::assertIsInt($body['floorId']);
		self::assertSame([], $body['warnings']);
		self::assertIsInt($body['project']['id']);
		$importedFiles = str_replace('{projectId:number}', (string) $body['project']['id'], Routes::ProjectFiles->value);
		self::assertSame(
			['notes.txt'],
			array_column($this->jsonList($this->request('GET', $importedFiles, authenticatedAs: $user)), 'filename'),
		);
	}

	public function testDownloadsTheJsonAloneWithoutFiles(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user, 'Flat');

		$export = $this->request('GET', self::exportPath($project->id) . '?files=0', authenticatedAs: $user);

		self::assertSame(200, $export->getStatusCode());
		self::assertSame('application/json', $export->getHeaderLine('Content-Type'));
		$bundle = JsonDecoder::decodeObject((string) $export->getBody());
		self::assertSame([ProjectBundle::Kind, []], [$bundle['kind'], $bundle['files']]);
	}

	public function testOthersCannotDownloadAProject(): void
	{
		$project = Fixture::createProject(Fixture::createUser());

		self::assertSame(
			404,
			$this->request('GET', self::exportPath($project->id), authenticatedAs: Fixture::createUser())->getStatusCode(),
		);
		self::assertSame(
			200,
			$this->request('GET', self::exportPath($project->id), authenticatedAs: Fixture::createAdmin())->getStatusCode(),
		);
	}

	public function testRefusesWhatIsNotAProjectFile(): void
	{
		$user = Fixture::createUser();
		$path = sys_get_temp_dir() . '/izbik-test-' . bin2hex(random_bytes(6));
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE));
		$zip->addFromString('readme.txt', 'Hello');
		$zip->close();
		$content = (string) file_get_contents($path);
		unlink($path);

		$response = $this->upload(Routes::ProjectImport->value, 'readme.zip', $content, $user);

		self::assertSame(422, $response->getStatusCode());
		self::assertSame('The zip archive holds no project.json; it is not a project file.', $this->jsonBody($response)['message'] ?? null);
		self::assertSame(422, $this->request('POST', Routes::ProjectImport->value, [], $user)->getStatusCode());
	}

	private static function exportPath(int $projectId): string
	{
		return str_replace('{projectId:number}', (string) $projectId, Routes::ProjectExport->value);
	}
}
