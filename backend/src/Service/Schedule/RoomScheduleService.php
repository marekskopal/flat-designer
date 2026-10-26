<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Schedule;

use FlatDesigner\Dto\FloorScheduleDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\RoomScheduleDto;
use FlatDesigner\Dto\RoomScheduleRowDto;
use FlatDesigner\Dto\ScheduleTotalDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\RoomRepository;

/** Room schedule of a project or a floor: area, perimeter, clear height and volume per room, indoor and outdoor totals. */
final readonly class RoomScheduleService
{
	public function __construct(private RoomRepository $roomRepository, private FloorRepository $floorRepository)
	{
	}

	public function forProject(Project $project): RoomScheduleDto
	{
		return self::schedule(array_map($this->floor(...), $this->floorRepository->findByProject($project->id)));
	}

	public function forFloor(Floor $floor): RoomScheduleDto
	{
		return self::schedule([$this->floor($floor)]);
	}

	private function floor(Floor $floor): FloorScheduleDto
	{
		$rows = array_map(
			static fn (Room $room): RoomScheduleRowDto => RoomScheduleRowDto::fromRoom(RoomDto::fromEntity($room)),
			$this->roomRepository->findByFloor($floor->id),
		);
		usort($rows, static fn (RoomScheduleRowDto $a, RoomScheduleRowDto $b): int => strnatcmp($a->code, $b->code));

		return new FloorScheduleDto(
			id: $floor->id,
			name: $floor->name,
			level: $floor->level,
			elevation: $floor->elevation,
			rooms: $rows,
			indoor: ScheduleTotalDto::of(self::rows($rows, outdoor: false)),
			outdoor: ScheduleTotalDto::of(self::rows($rows, outdoor: true)),
		);
	}

	/** @param list<FloorScheduleDto> $floors */
	private static function schedule(array $floors): RoomScheduleDto
	{
		$rows = array_merge([], ...array_map(static fn (FloorScheduleDto $f): array => $f->rooms, $floors));

		return new RoomScheduleDto(
			floors: $floors,
			indoor: ScheduleTotalDto::of(self::rows($rows, outdoor: false)),
			outdoor: ScheduleTotalDto::of(self::rows($rows, outdoor: true)),
		);
	}

	/**
	 * @param list<RoomScheduleRowDto> $rows
	 * @return list<RoomScheduleRowDto>
	 */
	private static function rows(array $rows, bool $outdoor): array
	{
		return array_values(array_filter($rows, static fn (RoomScheduleRowDto $r): bool => $r->outdoor === $outdoor));
	}
}
