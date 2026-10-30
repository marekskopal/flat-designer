<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Schedule;

use FlatDesigner\Dto\FloorScheduleDto;
use FlatDesigner\Dto\FloorVoidDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\RoomScheduleDto;
use FlatDesigner\Dto\RoomScheduleRowDto;
use FlatDesigner\Dto\ScheduleTotalDto;
use FlatDesigner\Dto\VoidScheduleRowDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FloorVoid;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\FloorVoidRepository;
use FlatDesigner\Model\Repository\RoofRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Service\Editor\Geometry\RoofCover;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Editor\Geometry\RoomUnderRoof;
use FlatDesigner\Service\Json\JsonDecoder;

/**
 * Room schedule of a project or a floor: area, perimeter, clear height and volume per room, the indoor (living), ancillary
 * (garages: indoor, unheated) and outdoor totals.
 * A room under the floor's roofs gets its clear height's range, its volume under the slope and its area with headroom
 * (`RoomUnderRoof`). The floor's voids (FD-90) are listed apart and taken off the areas of the rooms they cut.
 */
final readonly class RoomScheduleService
{
	public function __construct(
		private RoomRepository $roomRepository,
		private FloorRepository $floorRepository,
		private RoofRepository $roofRepository,
		private FloorVoidRepository $voidRepository,
	) {
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
		$cover = new RoofCover(array_map(
			static fn (Roof $roof): RoofGeometry => RoofGeometry::fromRoof($roof),
			$this->roofRepository->findByFloor($floor->id),
		));
		$voids = array_map(
			static fn (FloorVoid $void): FloorVoidDto => FloorVoidDto::fromEntity($void),
			$this->voidRepository->findByFloor($floor->id),
		);
		$rows = array_map(
			static function (Room $room) use ($cover, $voids): RoomScheduleRowDto {
				$dto = RoomDto::fromEntity($room);

				return RoomScheduleRowDto::fromRoom($dto, self::underRoof($room, $cover), VoidArea::of($dto->polygon, $voids) / 1e6);
			},
			$this->roomRepository->findByFloor($floor->id),
		);
		usort($rows, static fn (RoomScheduleRowDto $a, RoomScheduleRowDto $b): int => strnatcmp($a->code, $b->code));

		return new FloorScheduleDto(
			id: $floor->id,
			name: $floor->name,
			level: $floor->level,
			elevation: $floor->elevation,
			rooms: $rows,
			indoor: ScheduleTotalDto::of(self::rows($rows, 'indoor')),
			outdoor: ScheduleTotalDto::of(self::rows($rows, 'outdoor')),
			ancillary: ScheduleTotalDto::of(self::rows($rows, 'ancillary')),
			voids: array_map(VoidScheduleRowDto::fromVoid(...), $voids),
		);
	}

	private static function underRoof(Room $room, RoofCover $cover): ?RoomUnderRoof
	{
		return RoomUnderRoof::measure(
			array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], JsonDecoder::decodePolygon($room->polygon)),
			$room->ceilingHeight,
			$room->floorElevation,
			$room->vaultSpacing !== null,
			$cover,
		);
	}

	/** @param list<FloorScheduleDto> $floors */
	private static function schedule(array $floors): RoomScheduleDto
	{
		$rows = array_merge([], ...array_map(static fn (FloorScheduleDto $f): array => $f->rooms, $floors));

		return new RoomScheduleDto(
			floors: $floors,
			indoor: ScheduleTotalDto::of(self::rows($rows, 'indoor')),
			outdoor: ScheduleTotalDto::of(self::rows($rows, 'outdoor')),
			ancillary: ScheduleTotalDto::of(self::rows($rows, 'ancillary')),
		);
	}

	/**
	 * @param list<RoomScheduleRowDto> $rows
	 * @return list<RoomScheduleRowDto>
	 */
	private static function rows(array $rows, string $total): array
	{
		return array_values(array_filter($rows, static fn (RoomScheduleRowDto $r): bool => match ($total) {
			'outdoor' => $r->outdoor,
			'ancillary' => $r->ancillary,
			default => !$r->outdoor && !$r->ancillary,
		}));
	}
}
