<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ProjectFileController;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\ProjectFileProvider;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ProjectFileController::class)]
#[CoversClass(ProjectFileProvider::class)]
final class ProjectFileControllerTest extends IntegrationTestCase
{
	/** Smallest valid PNG (1 × 1 transparent pixel). */
	private const string Png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\0\x01\0\0\x05\0\x01\x0d\x0a\x2d\xb4\0\0\0\0IEND\xaeB`\x82";

	public function testUploadListDownloadDelete(): void
	{
		$user = Fixture::createUser(name: 'Jane');
		$project = Fixture::createProject($user);

		$uploaded = $this->upload(self::path(Routes::ProjectFiles, $project->id), 'kitchen/Návrh KZ.png', self::Png, $user);

		self::assertSame(201, $uploaded->getStatusCode());
		$file = $this->jsonBody($uploaded);
		self::assertSame('Návrh KZ.png', $file['filename']);
		self::assertSame('image/png', $file['mimeType']);
		self::assertSame(strlen(self::Png), $file['size']);
		self::assertSame('Jane', $file['uploadedByName']);
		self::assertIsInt($file['id']);
		self::assertCount(1, AppHarness::fileStorage()->objects);
		self::assertMatchesRegularExpression(
			'~^projects/\d+/[0-9a-f]{32}\.png$~',
			array_key_first(AppHarness::fileStorage()->objects),
		);

		$list = $this->jsonList($this->request('GET', self::path(Routes::ProjectFiles, $project->id), authenticatedAs: $user));
		self::assertSame(['Návrh KZ.png'], array_column($list, 'filename'));

		$content = $this->request('GET', self::path(Routes::ProjectFileContent, $project->id, $file['id']), authenticatedAs: $user);
		self::assertSame(200, $content->getStatusCode());
		self::assertSame(self::Png, (string) $content->getBody());
		self::assertSame('image/png', $content->getHeaderLine('Content-Type'));
		self::assertSame('nosniff', $content->getHeaderLine('X-Content-Type-Options'));
		self::assertSame(
			'attachment; filename="N_vrh KZ.png"; filename*=UTF-8\'\'N%C3%A1vrh%20KZ.png',
			$content->getHeaderLine('Content-Disposition'),
		);

		self::assertSame(
			200,
			$this->request('DELETE', self::path(Routes::ProjectFile, $project->id, $file['id']), authenticatedAs: $user)->getStatusCode(),
		);
		self::assertSame([], AppHarness::fileStorage()->objects);
		self::assertSame(
			[],
			$this->jsonList($this->request('GET', self::path(Routes::ProjectFiles, $project->id), authenticatedAs: $user)),
		);
	}

	public function testHtmlIsDetectedFromContentAndNeverServedAsHtml(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);

		$file = $this->jsonBody(
			$this->upload(self::path(Routes::ProjectFiles, $project->id), 'evil.png', '<html><script>alert(1)</script></html>', $user),
		);
		self::assertSame('text/html', $file['mimeType']);
		self::assertIsInt($file['id']);

		$content = $this->request('GET', self::path(Routes::ProjectFileContent, $project->id, $file['id']), authenticatedAs: $user);
		self::assertSame('application/octet-stream', $content->getHeaderLine('Content-Type'));
	}

	public function testRejectsEmptyAndTooLargeFiles(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);

		self::assertSame(422, $this->upload(self::path(Routes::ProjectFiles, $project->id), 'empty.txt', '', $user)->getStatusCode());
		// FILE_MAX_SIZE_MB is 1 in tests.
		self::assertSame(
			413,
			$this->upload(
				self::path(Routes::ProjectFiles, $project->id),
				'big.bin',
				str_repeat('x', 1024 * 1024 + 1),
				$user,
			)->getStatusCode(),
		);
		self::assertSame([], AppHarness::fileStorage()->objects);
	}

	public function testFailedStorageLeavesNoRow(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		AppHarness::fileStorage()->failPuts = true;

		$response = $this->upload(self::path(Routes::ProjectFiles, $project->id), 'a.txt', 'hello', $user);

		self::assertSame(500, $response->getStatusCode());
		AppHarness::fileStorage()->failPuts = false;
		self::assertSame(
			[],
			$this->jsonList($this->request('GET', self::path(Routes::ProjectFiles, $project->id), authenticatedAs: $user)),
		);
	}

	public function testFilesOfOtherProjectsAreNotFound(): void
	{
		$owner = Fixture::createUser();
		$project = Fixture::createProject($owner);
		$file = $this->jsonBody($this->upload(self::path(Routes::ProjectFiles, $project->id), 'a.txt', 'hello', $owner));
		self::assertIsInt($file['id']);
		$other = Fixture::createUser();
		$otherProject = Fixture::createProject($other);

		self::assertSame(
			404,
			$this->request('GET', self::path(Routes::ProjectFiles, $project->id), authenticatedAs: $other)->getStatusCode(),
		);
		self::assertSame(404, $this->upload(self::path(Routes::ProjectFiles, $project->id), 'b.txt', 'x', $other)->getStatusCode());
		self::assertSame(
			404,
			$this->request(
				'GET',
				self::path(Routes::ProjectFileContent, $otherProject->id, $file['id']),
				authenticatedAs: $other,
			)->getStatusCode(),
		);
		self::assertSame(
			404,
			$this->request('DELETE', self::path(Routes::ProjectFile, $project->id, $file['id']), authenticatedAs: $other)->getStatusCode(),
		);
	}

	public function testDeletingProjectRemovesStoredFiles(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$this->upload(self::path(Routes::ProjectFiles, $project->id), 'a.txt', 'hello', $user);
		$this->upload(self::path(Routes::ProjectFiles, $project->id), 'b.txt', 'world', $user);
		self::assertCount(2, AppHarness::fileStorage()->objects);

		$this->request('DELETE', self::path(Routes::Project, $project->id), authenticatedAs: $user);

		self::assertSame([], AppHarness::fileStorage()->objects);
	}

	public function testCleanFilename(): void
	{
		self::assertSame('plan.pdf', ProjectFileProvider::cleanFilename('C:\\Users\\me\\plan.pdf'));
		self::assertSame('a b.txt', ProjectFileProvider::cleanFilename("  a \t\n b.txt "));
		self::assertSame('file', ProjectFileProvider::cleanFilename('...'));
		self::assertSame('byt č.50_(H1B).pdf', ProjectFileProvider::cleanFilename('byt č.50_(H1B).pdf'));
	}

	private static function path(Routes $route, int $projectId, ?int $fileId = null): string
	{
		return str_replace(['{projectId:number}', '{fileId:number}'], [(string) $projectId, (string) $fileId], $route->value);
	}
}
