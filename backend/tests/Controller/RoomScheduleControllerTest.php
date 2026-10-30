<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\RoomScheduleController;
use FlatDesigner\Dto\RoomScheduleRowDto;
use FlatDesigner\Dto\ScheduleTotalDto;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Schedule\RoomScheduleService;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RoomScheduleController::class)]
#[CoversClass(RoomScheduleService::class)]
#[CoversClass(RoomScheduleRowDto::class)]
#[CoversClass(ScheduleTotalDto::class)]
final class RoomScheduleControllerTest extends EditorTestCase
{
	public function testCountsPathsAndDrivewaysOutdoors(): void
	{
		$this->create(
			'rooms',
			['code' => 'P1', 'name' => 'Path', 'type' => 'Path', 'polygon' => [[0, 0], [1000, 0], [1000, 4000], [0, 4000]]],
		);
		$this->create('rooms', [
			'code' => 'D1',
			'name' => 'Driveway',
			'type' => 'Driveway',
			'polygon' => [[2000, 0], [5000, 0], [5000, 6000], [2000, 6000]],
		]);

		$floor = $this->schedule(false)['floors'];
		self::assertIsList($floor);
		self::assertIsArray($floor[0]);
		self::assertEquals(['rooms' => 2, 'area' => 22.0, 'perimeter' => 28.0], $floor[0]['outdoor']);
		self::assertEquals(['rooms' => 0, 'area' => 0.0, 'perimeter' => 0.0], $floor[0]['indoor']);
	}

	public function testCountsGaragesApartFromTheLivingArea(): void
	{
		$this->create('rooms', ['code' => 'R1', 'name' => 'Hall', 'polygon' => [[0, 0], [2000, 0], [2000, 2000], [0, 2000]]]);
		$garage = $this->create(
			'rooms',
			['code' => 'G1', 'name' => 'Garage', 'type' => 'Garage', 'polygon' => [[2000, 0], [5000, 0], [5000, 6000], [2000, 6000]]],
		);
		// A new garage stands on a concrete floor.
		self::assertSame(Fixture::service(MaterialRepository::class)->findByCode('concrete-floor')?->id, $garage['floorMaterialId']);

		$schedule = $this->schedule(false);
		self::assertEquals(['rooms' => 1, 'area' => 4.0, 'perimeter' => 8.0], $schedule['indoor']);
		self::assertEquals(['rooms' => 1, 'area' => 18.0, 'perimeter' => 18.0], $schedule['ancillary']);
		self::assertEquals(['rooms' => 0, 'area' => 0.0, 'perimeter' => 0.0], $schedule['outdoor']);
		$floors = $schedule['floors'];
		self::assertIsList($floors);
		self::assertIsArray($floors[0]);
		self::assertEquals($schedule['ancillary'], $floors[0]['ancillary']);
		self::assertIsList($floors[0]['rooms']);
		self::assertIsArray($floors[0]['rooms'][0]);
		self::assertSame([true, false], [$floors[0]['rooms'][0]['ancillary'], $floors[0]['rooms'][0]['outdoor']]);
	}

	public function testAVoidIsListedAndTakenOffTheRoomsItCuts(): void
	{
		$this->create(
			'rooms',
			['code' => 'R1', 'name' => 'Landing', 'polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'ceilingHeight' => 2500],
		);
		// An L-shaped void, half over the landing.
		$this->create(
			'voids',
			['name' => 'Stairwell', 'polygon' => [[3000, 1000], [5000, 1000], [5000, 3000], [4000, 3000], [4000, 2000], [3000, 2000]]],
		);

		$floor = $this->schedule(false)['floors'];
		self::assertIsList($floor);
		self::assertIsArray($floor[0]);
		self::assertIsList($floor[0]['rooms']);
		self::assertIsArray($floor[0]['rooms'][0]);
		self::assertEquals(
			[11.0, 1.0, 27.5],
			[$floor[0]['rooms'][0]['area'], $floor[0]['rooms'][0]['voidArea'], $floor[0]['rooms'][0]['volume']],
		);
		self::assertEquals(['rooms' => 1, 'area' => 11.0, 'perimeter' => 14.0], $floor[0]['indoor']);
		self::assertIsList($floor[0]['voids']);
		self::assertEquals([['name' => 'Stairwell', 'area' => 3.0]], array_map(
			static fn (mixed $v): array => is_array($v) ? ['name' => $v['name'], 'area' => $v['area']] : [],
			$floor[0]['voids'],
		));
	}

	public function testListsTheRoomsWithIndoorAndOutdoorTotals(): void
	{
		$this->create('rooms', [
			'code' => 'R2',
			'name' => 'Bedroom',
			'type' => 'Bedroom',
			'polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]],
			'ceilingHeight' => 2500,
		]);
		// An L-shaped hall: 2 × 1 m plus 1 × 1 m, perimeter 8 m.
		$this->create('rooms', [
			'code' => 'R1',
			'name' => 'Hall',
			'type' => 'Hallway',
			'polygon' => [[4000, 0], [6000, 0], [6000, 1000], [5000, 1000], [5000, 2000], [4000, 2000]],
		]);
		$this->create('rooms', [
			'code' => 'T1',
			'name' => 'Terrace',
			'type' => 'Terrace',
			'polygon' => [[0, -2000], [3000, -2000], [3000, 0], [0, 0]],
			'ceilingHeight' => null,
		]);
		$deleted = $this->create('rooms', ['code' => 'R9', 'name' => 'Gone']);
		$this->edit('DELETE', 'rooms', self::id($deleted));

		$schedule = $this->schedule(false);

		self::assertIsList($schedule['floors']);
		self::assertCount(1, $schedule['floors']);
		$floor = $schedule['floors'][0];
		self::assertIsArray($floor);
		self::assertIsList($floor['rooms']);
		self::assertSame(['R1', 'R2', 'T1'], array_column($floor['rooms'], 'code'));
		[$hall, $bedroom, $terrace] = $floor['rooms'];
		self::assertEquals(
			['area' => 3.0, 'perimeter' => 8.0, 'clearHeight' => 2600, 'volume' => 7.8, 'outdoor' => false],
			self::measures($hall),
		);
		self::assertEquals(
			['area' => 12.0, 'perimeter' => 14.0, 'clearHeight' => 2500, 'volume' => 30.0, 'outdoor' => false],
			self::measures($bedroom),
		);
		self::assertEquals(
			['area' => 6.0, 'perimeter' => 10.0, 'clearHeight' => null, 'volume' => null, 'outdoor' => true],
			self::measures($terrace),
		);
		self::assertEquals(['rooms' => 2, 'area' => 15.0, 'perimeter' => 22.0], $floor['indoor']);
		self::assertEquals(['rooms' => 1, 'area' => 6.0, 'perimeter' => 10.0], $floor['outdoor']);
		self::assertEquals($floor['indoor'], $schedule['indoor']);
		self::assertEquals($floor['outdoor'], $schedule['outdoor']);

		// The floor's schedule totals the same here; another user gets nothing.
		$floorSchedule = $this->schedule(true);
		self::assertEquals($schedule['indoor'], $floorSchedule['indoor']);
		self::assertSame(404, $this->request('GET', $this->path(false), authenticatedAs: Fixture::createUser())->getStatusCode());
		self::assertSame(404, $this->request('GET', $this->path(true), authenticatedAs: Fixture::createUser())->getStatusCode());
	}

	public function testMeasuresAnAtticUnderItsRoof(): void
	{
		// The fixture's attic: a 45° gable from 1 m at the eaves over a 10 × 8 m room with a 2.5 m ceiling.
		$this->create('roofs', [
			'shape' => 'Gable',
			'x' => 5000,
			'y' => 4000,
			'width' => 10000,
			'depth' => 8000,
			'eaveHeight' => 1000,
			'pitch' => 45,
			'overhang' => 0,
		]);
		$this->create('rooms', [
			'code' => 'A1',
			'name' => 'Attic',
			'type' => 'Bedroom',
			'polygon' => [[0, 0], [10000, 0], [10000, 8000], [0, 8000]],
			'ceilingHeight' => 2500,
		]);
		$this->create('rooms', [
			'code' => 'B1',
			'name' => 'Hall beside',
			'type' => 'Hallway',
			'polygon' => [[20000, 0], [22000, 0], [22000, 2000], [20000, 2000]],
			'ceilingHeight' => 2500,
		]);

		$floor = $this->schedule(true)['floors'];
		self::assertIsList($floor);
		self::assertIsArray($floor[0]);
		self::assertIsList($floor[0]['rooms']);
		[$attic, $beside] = $floor[0]['rooms'];
		self::assertIsArray($attic);
		self::assertEquals(
			['clearHeight' => 2500, 'minClearHeight' => 1000, 'volume' => 177.5, 'headroomArea' => 76.0],
			array_intersect_key($attic, array_flip(['clearHeight', 'minClearHeight', 'volume', 'headroomArea'])),
		);
		self::assertIsArray($beside);
		self::assertEquals(
			['clearHeight' => 2500, 'minClearHeight' => null, 'volume' => 10.0, 'headroomArea' => null],
			array_intersect_key($beside, array_flip(['clearHeight', 'minClearHeight', 'volume', 'headroomArea'])),
		);
	}

	/** @return array<mixed> */
	private static function measures(mixed $row): array
	{
		self::assertIsArray($row);

		return array_intersect_key($row, array_flip(['area', 'perimeter', 'clearHeight', 'volume', 'outdoor']));
	}

	/** @return array<string, mixed> */
	private function schedule(bool $floor): array
	{
		return $this->jsonBody($this->request('GET', $this->path($floor), authenticatedAs: $this->user));
	}

	private function path(bool $floor): string
	{
		return str_replace(
			['{projectId:number}', '{floorId:number}'],
			[(string) $this->project->id, (string) $this->floor->id],
			($floor ? Routes::ProjectFloorRoomSchedule : Routes::ProjectRoomSchedule)->value,
		);
	}
}
