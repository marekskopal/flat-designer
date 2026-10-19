<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\RoomScheduleController;
use FlatDesigner\Dto\RoomScheduleRowDto;
use FlatDesigner\Dto\ScheduleTotalDto;
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
