<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ProjectVersionController;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\ProjectSnapshotService;
use FlatDesigner\Service\Project\ProjectVersionProvider;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ProjectVersionController::class)]
#[CoversClass(ProjectVersionProvider::class)]
#[CoversClass(ProjectSnapshotService::class)]
final class ProjectVersionControllerTest extends IntegrationTestCase
{
	public function testSaveVersionsAreNumberedAndListedNewestFirst(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$project = Fixture::createProject($user);

		$first = $this->request('POST', self::path(Routes::ProjectVersions, $project->id), ['note' => 'Initial'], $user);
		$this->request('POST', self::path(Routes::ProjectVersions, $project->id), [], $user);

		self::assertSame(201, $first->getStatusCode());
		$versions = $this->jsonList($this->request('GET', self::path(Routes::ProjectVersions, $project->id), authenticatedAs: $user));
		self::assertSame([2, 1], array_column($versions, 'number'));
		self::assertSame([null, 'Initial'], array_column($versions, 'note'));
		self::assertSame('Test User', $versions[0]['createdByName']);

		$projectBody = $this->jsonBody($this->request('GET', self::path(Routes::Project, $project->id), authenticatedAs: $user));
		self::assertSame(2, $projectBody['latestVersion']);
	}

	public function testVersionSceneMatchesWorkingCopyAtSaveTime(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);

		$working = $this->jsonBody(
			$this->request('GET', self::path(Routes::ProjectFloorScene, $project->id, null, $floor->id), authenticatedAs: $user),
		);
		// The working copy also reports the project revision; a stored version has none.
		unset($working['revision']);
		$versionId = $this->saveVersion($user, $project->id);

		$detail = $this->jsonBody(
			$this->request('GET', self::path(Routes::ProjectVersion, $project->id, $versionId), authenticatedAs: $user),
		);
		$stored = $this->request(
			'GET',
			self::path(Routes::ProjectVersionFloorScene, $project->id, $versionId, $floor->id),
			authenticatedAs: $user,
		);

		self::assertSame([['id' => $floor->id, 'name' => 'Ground floor', 'level' => 0]], $detail['floors']);
		self::assertSame(200, $stored->getStatusCode());
		self::assertEquals($working, $this->jsonBody($stored));
		// Empty item params stay a JSON object.
		self::assertStringContainsString('"params":{}', (string) $stored->getBody());
	}

	public function testRestoreBringsBackFloorsAndAutoSavesTheWorkingCopy(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$before = $this->sceneWithoutIds($user, $project->id, $floor->id);
		$versionId = $this->saveVersion($user, $project->id);

		// Change the working copy: drop the wall, add a floor.
		$walls = Fixture::service(WallRepository::class);
		foreach ($walls->findByFloor($floor->id) as $wall) {
			$walls->delete($wall);
		}
		$this->request('POST', self::path(Routes::ProjectFloors, $project->id), ['name' => 'Attic', 'level' => 1], $user);

		$restore = $this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);

		self::assertSame(200, $restore->getStatusCode());
		self::assertSame('Before restoring version 1', $this->jsonBody($restore)['note']);

		$projectBody = $this->jsonBody($this->request('GET', self::path(Routes::Project, $project->id), authenticatedAs: $user));
		self::assertIsList($projectBody['floors']);
		self::assertCount(1, $projectBody['floors']);
		self::assertIsArray($projectBody['floors'][0]);
		self::assertIsInt($projectBody['floors'][0]['id']);
		self::assertSame($before, $this->sceneWithoutIds($user, $project->id, $projectBody['floors'][0]['id']));
		self::assertSame(2, $projectBody['latestVersion']);
	}

	public function testRestoreOfSnapshotWithoutDoorStyleUsesDefaults(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		Fixture::furnishFloor(Fixture::firstFloor($project));
		$versionId = $this->saveVersion($user, $project->id);
		// Snapshots saved before doors had a frame style and handle.
		Fixture::service(DatabaseInterface::class)->getPdo()->exec(sprintf(
			"UPDATE project_versions SET snapshot = REPLACE(REPLACE(snapshot, '\"frameStyle\":\"Architrave\",', ''), ',\"handle\":\"Knob\"', '') WHERE id = %d",
			$versionId,
		));

		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);

		$projectBody = $this->jsonBody($this->request('GET', self::path(Routes::Project, $project->id), authenticatedAs: $user));
		self::assertIsList($projectBody['floors']);
		self::assertIsArray($projectBody['floors'][0]);
		self::assertIsInt($projectBody['floors'][0]['id']);
		$scene = $this->jsonBody(
			$this->request(
				'GET',
				self::path(Routes::ProjectFloorScene, $project->id, null, $projectBody['floors'][0]['id']),
				authenticatedAs: $user,
			),
		);
		self::assertIsList($scene['walls']);
		self::assertIsArray($scene['walls'][0]);
		self::assertIsList($scene['walls'][0]['openings']);
		self::assertIsArray($scene['walls'][0]['openings'][0]);
		self::assertSame(['Lining', null], [$scene['walls'][0]['openings'][0]['frameStyle'], $scene['walls'][0]['openings'][0]['handle']]);
	}

	public function testVersionsOfOtherProjectsAreNotFound(): void
	{
		$owner = Fixture::createUser();
		$project = Fixture::createProject($owner);
		$versionId = $this->saveVersion($owner, $project->id);
		$other = Fixture::createUser();

		self::assertSame(
			404,
			$this->request('GET', self::path(Routes::ProjectVersions, $project->id), authenticatedAs: $other)->getStatusCode(),
		);
		self::assertSame(
			404,
			$this->request('GET', self::path(Routes::ProjectVersion, $project->id, $versionId), authenticatedAs: $other)->getStatusCode(),
		);
		self::assertSame(
			404,
			$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $other)->getStatusCode(),
		);
	}

	private function saveVersion(User $user, int $projectId): int
	{
		$id = $this->jsonBody($this->request('POST', self::path(Routes::ProjectVersions, $projectId), [], $user))['id'];
		self::assertIsInt($id);

		return $id;
	}

	/**
	 * The floor scene with every `id`/`roomId` dropped, to compare content across a restore.
	 *
	 * @return array<mixed>
	 */
	private function sceneWithoutIds(User $user, int $projectId, int $floorId): array
	{
		$scene = $this->jsonBody(
			$this->request('GET', self::path(Routes::ProjectFloorScene, $projectId, null, $floorId), authenticatedAs: $user),
		);

		return self::stripIds($scene);
	}

	/**
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	private static function stripIds(array $data): array
	{
		$stripped = [];
		foreach ($data as $key => $value) {
			if ($key === 'id' || $key === 'roomId' || $key === 'revision') {
				continue;
			}
			$stripped[$key] = is_array($value) ? self::stripIds($value) : $value;
		}

		return $stripped;
	}

	private static function path(Routes $route, int $projectId, ?int $versionId = null, ?int $floorId = null): string
	{
		return str_replace(
			['{projectId:number}', '{versionId:number}', '{floorId:number}'],
			[(string) $projectId, (string) $versionId, (string) $floorId],
			$route->value,
		);
	}
}
