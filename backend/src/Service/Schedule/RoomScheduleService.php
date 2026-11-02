<?php

declare(strict_types=1);

namespace Izbik\Service\Schedule;

use Izbik\Dto\FloorScheduleDto;
use Izbik\Dto\FloorVoidDto;
use Izbik\Dto\RoomDto;
use Izbik\Dto\RoomScheduleDto;
use Izbik\Dto\RoomScheduleRowDto;
use Izbik\Dto\ScheduleTotalDto;
use Izbik\Dto\VoidScheduleRowDto;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\FloorVoid;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\Roof;
use Izbik\Model\Entity\Room;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Model\Repository\FloorVoidRepository;
use Izbik\Model\Repository\RoofRepository;
use Izbik\Model\Repository\RoomRepository;
use Izbik\Service\Editor\Geometry\RoofCover;
use Izbik\Service\Editor\Geometry\RoofGeometry;
use Izbik\Service\Editor\Geometry\RoomUnderRoof;
use Izbik\Service\Json\JsonDecoder;

/**
 * Room schedule of a project or a floor: area, perimeter, clear height and volume per room, the indoor (living), ancillary
 * (garages: indoor, unheated) and outdoor totals.
 * A room under the floor's roofs gets its clear height's range, its volume under the slope and its area with headroom
 * (`RoomUnderRoof`). The floor's voids are listed apart and taken off the areas of the rooms they cut.
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
