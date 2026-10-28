<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ProjectVersionController;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\StairRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Project\ProjectSnapshotService;
use FlatDesigner\Service\Project\ProjectVersionProvider;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use MarekSkopal\ORM\Database\DatabaseInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use const JSON_THROW_ON_ERROR;

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

		self::assertSame(
			[['id' => $floor->id, 'name' => 'Ground floor', 'level' => 0, 'elevation' => 0, 'slabThickness' => 300]],
			$detail['floors'],
		);
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

	public function testRestoreOfSnapshotWithoutNewOpeningFieldsUsesDefaults(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		Fixture::furnishFloor(Fixture::firstFloor($project));
		$versionId = $this->saveVersion($user, $project->id);
		// Snapshots saved before doors had a frame style and handle, and windows divisions.
		Fixture::service(DatabaseInterface::class)->getPdo()->exec(sprintf(
			"UPDATE project_versions SET snapshot = REPLACE(REPLACE(REPLACE(snapshot, '\"frameStyle\":\"Architrave\",', ''), ',\"handle\":\"Knob\"', ''), ',\"mullions\":1,\"transoms\":0,\"sillBoard\":null', '') WHERE id = %d",
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
		$door = $scene['walls'][0]['openings'][0];
		self::assertSame(['Lining', null, 1, 0], [$door['frameStyle'], $door['handle'], $door['mullions'], $door['transoms']]);
	}

	public function testRestoreKeepsFloorElevationsAndStacksOlderSnapshots(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$this->request(
			'POST',
			self::path(Routes::ProjectFloors, $project->id),
			['name' => 'Attic', 'level' => 1, 'elevation' => 3100],
			$user,
		);
		$versionId = $this->saveVersion($user, $project->id);

		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame([[0, 300], [3100, 250]], $this->restoredFloorStack($user, $project->id));

		// Snapshots saved before floors had an elevation are stacked on the default walls.
		Fixture::service(DatabaseInterface::class)->getPdo()->exec(sprintf(
			'UPDATE project_versions SET snapshot = REGEXP_REPLACE(snapshot, \',"elevation":-?[0-9]+,"slabThickness":[0-9]+\', \'\') WHERE id = %d',
			$versionId,
		));
		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame([[0, 300], [2850, 250]], $this->restoredFloorStack($user, $project->id));
	}

	public function testRestoreKeepsWallFaceFinishesAndOlderSnapshotsHaveNone(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$wall = $this->jsonBody(
			$this->request('GET', self::path(Routes::ProjectFloorScene, $project->id, null, $floor->id), authenticatedAs: $user),
		)['walls'];
		self::assertIsList($wall);
		self::assertIsArray($wall[0]);
		$finishes = [$wall[0]['leftFinishes'], $wall[0]['rightFinishes']];
		self::assertIsList($finishes[0]);
		self::assertSame([], $finishes[1]);
		$versionId = $this->saveVersion($user, $project->id);

		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame($finishes, $this->restoredWallFinishes($user, $project->id));

		// Snapshots saved before walls had face finishes.
		Fixture::service(DatabaseInterface::class)->getPdo()->exec(sprintf(
			'UPDATE project_versions SET snapshot = REGEXP_REPLACE(snapshot, \',"(left|right)Finishes":\\\\[[^]]*\\\\]\', \'\') WHERE id = %d',
			$versionId,
		));
		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame([null, null], $this->restoredWallFinishes($user, $project->id));
	}

	public function testRestoreKeepsRoofsAndGablesAndOlderSnapshotsHaveNone(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$versionId = $this->saveVersion($user, $project->id);

		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		$roofs = $this->restoredScene($user, $project->id)['roofs'];
		self::assertIsList($roofs);
		self::assertCount(1, $roofs);
		self::assertIsArray($roofs[0]);
		self::assertSame(
			['Main roof', 'Hip', 40, 280],
			[$roofs[0]['name'], $roofs[0]['shape'], $roofs[0]['pitch'], $roofs[0]['thickness']],
		);
		self::assertIsList($roofs[0]['windows']);
		self::assertIsArray($roofs[0]['windows'][0]);
		self::assertSame(
			['Skylight', 'TopHung', -900],
			[$roofs[0]['windows'][0]['name'], $roofs[0]['windows'][0]['type'], $roofs[0]['windows'][0]['across']],
		);
		self::assertTrue($this->restoredWall($user, $project->id)['upToRoof']);

		// Snapshots saved before roofs.
		$pdo = Fixture::service(DatabaseInterface::class)->getPdo();
		$select = FurnitureSeeds::prepare($pdo, 'SELECT snapshot FROM project_versions WHERE id = ?');
		$select->execute([$versionId]);
		$data = JsonDecoder::decodeObject((string) $select->fetchColumn());
		self::assertIsList($data['floors']);
		$floors = [];
		foreach ($data['floors'] as $entry) {
			self::assertIsArray($entry);
			self::assertIsList($entry['walls']);
			unset($entry['roofs']);
			$entry['walls'] = array_map(static function (mixed $wall): array {
				self::assertIsArray($wall);
				unset($wall['upToRoof']);

				return $wall;
			}, $entry['walls']);
			$floors[] = $entry;
		}
		$data['floors'] = $floors;
		$update = FurnitureSeeds::prepare($pdo, 'UPDATE project_versions SET snapshot = ? WHERE id = ?');
		$update->execute([json_encode($data, JSON_THROW_ON_ERROR), $versionId]);
		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame([], $this->restoredScene($user, $project->id)['roofs']);
		self::assertFalse($this->restoredWall($user, $project->id)['upToRoof']);
	}

	public function testRestoreKeepsSiteWallsAndGardenSteps(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$walls = Fixture::service(WallRepository::class);
		foreach ($walls->findByFloor($floor->id) as $wall) {
			[$wall->heightEnd, $wall->baseStart, $wall->baseEnd, $wall->onGround] = [1800, -400, -200, true];
			$walls->persist($wall);
		}
		$stairs = Fixture::service(StairRepository::class);
		foreach ($stairs->findByFloor($floor->id) as $stair) {
			[$stair->rise, $stair->baseZ] = [600, -300];
			$stairs->persist($stair);
		}
		$versionId = $this->saveVersion($user, $project->id);

		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		$wall = $this->restoredWall($user, $project->id);
		self::assertSame([1800, -400, -200, true], [$wall['heightEnd'], $wall['baseStart'], $wall['baseEnd'], $wall['onGround']]);
		$restored = $this->restoredScene($user, $project->id)['stairs'];
		self::assertIsList($restored);
		self::assertIsArray($restored[0]);
		self::assertSame([600, -300], [$restored[0]['rise'], $restored[0]['baseZ']]);
	}

	public function testRestoreKeepsCurvedWallsAndOlderSnapshotsHaveStraightOnes(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$walls = Fixture::service(WallRepository::class);
		$wall = $walls->findByFloor($floor->id)[0];
		$wall->sagitta = 321;
		$walls->persist($wall);
		$versionId = $this->saveVersion($user, $project->id);

		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame(321, $this->restoredWall($user, $project->id)['sagitta']);

		// Snapshots saved before walls could curve.
		Fixture::service(DatabaseInterface::class)->getPdo()->exec(sprintf(
			'UPDATE project_versions SET snapshot = REPLACE(snapshot, \',"sagitta":321\', \'\') WHERE id = %d',
			$versionId,
		));
		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame(0, $this->restoredWall($user, $project->id)['sagitta']);
	}

	public function testRestoreKeepsFloorAngleAndOlderSnapshotsLayAlongTheAxes(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$rooms = Fixture::service(RoomRepository::class);
		$room = $rooms->findByFloor($floor->id)[0];
		$room->floorAngle = 105;
		$rooms->persist($room);
		$versionId = $this->saveVersion($user, $project->id);

		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame(105, $this->restoredRoom($user, $project->id)['floorAngle']);

		// Snapshots saved before rooms had a floor angle.
		Fixture::service(DatabaseInterface::class)->getPdo()->exec(sprintf(
			'UPDATE project_versions SET snapshot = REPLACE(snapshot, \',"floorAngle":105\', \'\') WHERE id = %d',
			$versionId,
		));
		$this->request('POST', self::path(Routes::ProjectVersionRestore, $project->id, $versionId), [], $user);
		self::assertSame(0, $this->restoredRoom($user, $project->id)['floorAngle']);
	}

	/** @return array<mixed> the first room of the project's first floor */
	private function restoredRoom(User $user, int $projectId): array
	{
		$projectBody = $this->jsonBody($this->request('GET', self::path(Routes::Project, $projectId), authenticatedAs: $user));
		self::assertIsList($projectBody['floors']);
		self::assertIsArray($projectBody['floors'][0]);
		self::assertIsInt($projectBody['floors'][0]['id']);
		$rooms = $this->jsonBody(
			$this->request(
				'GET',
				self::path(Routes::ProjectFloorScene, $projectId, null, $projectBody['floors'][0]['id']),
				authenticatedAs: $user,
			),
		)['rooms'];
		self::assertIsList($rooms);
		self::assertIsArray($rooms[0]);

		return $rooms[0];
	}

	/** @return array{mixed, mixed} the left and right finishes of the first wall of the project's first floor */
	private function restoredWallFinishes(User $user, int $projectId): array
	{
		$wall = $this->restoredWall($user, $projectId);

		return [$wall['leftFinishes'], $wall['rightFinishes']];
	}

	/** @return array<mixed> the scene of the project's first floor */
	private function restoredScene(User $user, int $projectId): array
	{
		$projectBody = $this->jsonBody($this->request('GET', self::path(Routes::Project, $projectId), authenticatedAs: $user));
		self::assertIsList($projectBody['floors']);
		self::assertIsArray($projectBody['floors'][0]);
		self::assertIsInt($projectBody['floors'][0]['id']);

		return $this->jsonBody($this->request(
			'GET',
			self::path(Routes::ProjectFloorScene, $projectId, null, $projectBody['floors'][0]['id']),
			authenticatedAs: $user,
		));
	}

	/** @return array<mixed> the first wall of the project's first floor */
	private function restoredWall(User $user, int $projectId): array
	{
		$projectBody = $this->jsonBody($this->request('GET', self::path(Routes::Project, $projectId), authenticatedAs: $user));
		self::assertIsList($projectBody['floors']);
		self::assertIsArray($projectBody['floors'][0]);
		self::assertIsInt($projectBody['floors'][0]['id']);
		$walls = $this->jsonBody(
			$this->request(
				'GET',
				self::path(Routes::ProjectFloorScene, $projectId, null, $projectBody['floors'][0]['id']),
				authenticatedAs: $user,
			),
		)['walls'];
		self::assertIsList($walls);
		self::assertIsArray($walls[0]);

		return $walls[0];
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

	/** @return list<array{mixed, mixed}> elevation and slab of the working copy's floors, by level */
	private function restoredFloorStack(User $user, int $projectId): array
	{
		$floors = $this->jsonBody($this->request('GET', self::path(Routes::Project, $projectId), authenticatedAs: $user))['floors'];
		self::assertIsList($floors);

		return array_map(static fn (mixed $f): array => is_array($f) ? [$f['elevation'], $f['slabThickness']] : [null, null], $floors);
	}
}
