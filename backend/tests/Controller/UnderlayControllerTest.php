<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\UnderlayController;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\SnapshotUnderlayRestorer;
use FlatDesigner\Service\Project\UnderlayService;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UnderlayController::class)]
#[CoversClass(UnderlayService::class)]
#[CoversClass(SnapshotUnderlayRestorer::class)]
final class UnderlayControllerTest extends IntegrationTestCase
{
	public function testCalibratedUnderlayIsInTheSceneAndBumpsTheRevision(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$fileId = $this->uploadImage($user, $project, 400, 200);
		$revision = $this->scene($user, $project, $floor->id)['revision'];
		self::assertIsInt($revision);

		$response = $this->request('PUT', self::path(Routes::ProjectFloorUnderlay, $project->id, $floor->id), [
			'fileId' => $fileId,
			'points' => [['px' => 100, 'py' => 150, 'x' => 0, 'y' => 0], ['px' => 300, 'py' => 150, 'x' => 4000, 'y' => 0]],
		], $user);

		self::assertSame(200, $response->getStatusCode());
		$expected = [
			'fileId' => $fileId,
			'filename' => 'plan.png',
			'widthPx' => 400,
			'heightPx' => 200,
			'x' => -2000,
			'y' => 3000,
			'scale' => 20.0,
			'rotation' => 0.0,
			'opacity' => 0.5,
		];
		self::assertEquals($expected, $this->jsonBody($response)['underlay']);
		$scene = $this->scene($user, $project, $floor->id);
		self::assertEquals($expected, $scene['underlay']);
		self::assertSame($revision + 1, $scene['revision']);
		self::assertSame($revision + 1, $this->jsonBody($response)['revision']);
	}

	public function testFieldsLeftOutKeepTheirValue(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$fileId = $this->uploadImage($user, $project, 100, 50);
		$path = self::path(Routes::ProjectFloorUnderlay, $project->id, $floor->id);

		$new = $this->jsonBody($this->request('PUT', $path, ['fileId' => $fileId], $user))['underlay'];
		// A new underlay without placement: 10 mm per pixel, bottom-left corner on the origin.
		self::assertIsArray($new);
		self::assertEquals([0, 500, 10.0], [$new['x'], $new['y'], $new['scale']]);

		$moved = $this->jsonBody(
			$this->request('PUT', $path, ['fileId' => $fileId, 'x' => 250, 'rotation' => -90, 'opacity' => 0.8], $user),
		);
		self::assertIsArray($moved['underlay']);
		self::assertEquals([250, 500, 10.0, 270.0, 0.8], [
			$moved['underlay']['x'],
			$moved['underlay']['y'],
			$moved['underlay']['scale'],
			$moved['underlay']['rotation'],
			$moved['underlay']['opacity'],
		]);
	}

	public function testRejectsFilesThatAreNotImagesOfTheProject(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$path = self::path(Routes::ProjectFloorUnderlay, $project->id, $floor->id);

		$text = $this->jsonBody($this->upload(self::filesPath($project), 'notes.txt', "Kitchen 3.6 m\n", $user))['id'];
		$response = $this->request('PUT', $path, ['fileId' => $text], $user);
		self::assertSame(422, $response->getStatusCode());
		self::assertStringContainsString('must be an image', (string) $response->getBody());

		$otherProject = Fixture::createProject($user, 'Other');
		$foreign = $this->uploadImage($user, $otherProject, 10, 10);
		self::assertSame(422, $this->request('PUT', $path, ['fileId' => $foreign], $user)->getStatusCode());

		$image = $this->uploadImage($user, $project, 10, 10);
		self::assertSame(422, $this->request('PUT', $path, ['fileId' => $image, 'scale' => 0], $user)->getStatusCode());
		self::assertSame(422, $this->request('PUT', $path, ['fileId' => $image, 'opacity' => 2], $user)->getStatusCode());

		self::assertSame(404, $this->request('PUT', $path, ['fileId' => $image], Fixture::createUser())->getStatusCode());
	}

	public function testRemoveAndFileDeletion(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$fileId = $this->uploadImage($user, $project, 20, 20);
		$path = self::path(Routes::ProjectFloorUnderlay, $project->id, $floor->id);

		$set = $this->jsonBody($this->request('PUT', $path, ['fileId' => $fileId], $user));
		self::assertIsInt($set['revision']);
		// A stale If-Match is refused like any other edit; the current one goes through.
		$stale = $this->request(
			'PUT',
			$path,
			['fileId' => $fileId, 'opacity' => 0.7],
			$user,
			headers: ['If-Match' => (string) ($set['revision'] - 1)],
		);
		self::assertSame(409, $stale->getStatusCode());
		$fresh = $this->request(
			'PUT',
			$path,
			['fileId' => $fileId, 'opacity' => 0.7],
			$user,
			headers: ['If-Match' => (string) $set['revision']],
		);
		self::assertSame(200, $fresh->getStatusCode());
		self::assertSame(409, $this->request('DELETE', $path, authenticatedAs: $user, headers: ['If-Match' => '1'])->getStatusCode());
		self::assertSame(200, $this->request('DELETE', $path, authenticatedAs: $user)->getStatusCode());
		self::assertNull($this->scene($user, $project, $floor->id)['underlay']);

		// Deleting the file takes its underlay with it, and open viewers learn of it from the revision.
		$revision = $this->jsonBody($this->request('PUT', $path, ['fileId' => $fileId], $user))['revision'];
		self::assertIsInt($revision);
		$this->request('DELETE', self::filesPath($project) . '/' . $fileId, authenticatedAs: $user);
		$scene = $this->scene($user, $project, $floor->id);
		self::assertNull($scene['underlay']);
		self::assertSame($revision + 1, $scene['revision']);
	}

	public function testVersionsKeepTheUnderlayWhileItsFileExists(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$fileId = $this->uploadImage($user, $project, 80, 40);
		$this->request('PUT', self::path(Routes::ProjectFloorUnderlay, $project->id, $floor->id), [
			'fileId' => $fileId,
			'x' => 100,
			'y' => 900,
			'scale' => 12.5,
			'rotation' => 14.1,
			'opacity' => 0.3,
		], $user);
		$underlay = $this->scene($user, $project, $floor->id)['underlay'];
		$versionId = $this->jsonBody($this->request('POST', self::projectPath(Routes::ProjectVersions, $project), [], $user))['id'];
		self::assertIsInt($versionId);
		$restorePath = str_replace('{versionId:number}', (string) $versionId, self::projectPath(Routes::ProjectVersionRestore, $project));

		$this->request('DELETE', self::path(Routes::ProjectFloorUnderlay, $project->id, $floor->id), authenticatedAs: $user);
		$this->request('POST', $restorePath, [], $user);
		self::assertEquals($underlay, $this->scene($user, $project, $this->firstFloorId($user, $project))['underlay']);

		$this->request('DELETE', self::filesPath($project) . '/' . $fileId, authenticatedAs: $user);
		$this->request('POST', $restorePath, [], $user);
		self::assertNull($this->scene($user, $project, $this->firstFloorId($user, $project))['underlay']);
	}

	/**
	 * @param positive-int $width
	 * @param positive-int $height
	 */
	private function uploadImage(User $user, Project $project, int $width, int $height): int
	{
		$image = imagecreatetruecolor($width, $height);
		self::assertNotFalse($image);
		ob_start();
		imagepng($image);
		$id = $this->jsonBody($this->upload(self::filesPath($project), 'plan.png', (string) ob_get_clean(), $user))['id'];
		self::assertIsInt($id);

		return $id;
	}

	/** @return array<string, mixed> */
	private function scene(User $user, Project $project, int $floorId): array
	{
		return $this->jsonBody(
			$this->request('GET', self::path(Routes::ProjectFloorScene, $project->id, $floorId), authenticatedAs: $user),
		);
	}

	private function firstFloorId(User $user, Project $project): int
	{
		$floors = $this->jsonBody($this->request('GET', self::projectPath(Routes::Project, $project), authenticatedAs: $user))['floors'];
		self::assertIsList($floors);
		self::assertIsArray($floors[0]);
		self::assertIsInt($floors[0]['id']);

		return $floors[0]['id'];
	}

	private static function path(Routes $route, int $projectId, int $floorId): string
	{
		return str_replace(['{projectId:number}', '{floorId:number}'], [(string) $projectId, (string) $floorId], $route->value);
	}

	private static function projectPath(Routes $route, Project $project): string
	{
		return str_replace('{projectId:number}', (string) $project->id, $route->value);
	}

	private static function filesPath(Project $project): string
	{
		return self::projectPath(Routes::ProjectFiles, $project);
	}
}
