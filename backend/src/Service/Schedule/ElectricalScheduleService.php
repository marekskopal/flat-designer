<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Schedule;

use FlatDesigner\Dto\ElectricalRowDto;
use FlatDesigner\Dto\ElectricalScheduleDto;
use FlatDesigner\Dto\ElectricalTotalsDto;
use FlatDesigner\Dto\FloorElectricalDto;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\RoomElectricalDto;
use FlatDesigner\Dto\SceneDto;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Furniture\DefinitionCategoryEnum;
use FlatDesigner\Service\Furniture\DefinitionValidator;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Provider\SceneProvider;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * Electrical schedule of a project or a floor (working copy, shown variants only): the Custom items whose definition has
 * a `category`, per room — the item's `roomId`, otherwise the room whose outline holds its footprint centre — grouped
 * by definition, parameter values and elevation, with the counts per category.
 */
final readonly class ElectricalScheduleService
{
	/** Parameter types whose values tell points apart (a socket's gangs, a switch's rockers). */
	private const array ValueTypes = ['int', 'bool', 'select'];

	private const string Unassigned = 'Not in a room';

	public function __construct(private SceneProvider $sceneProvider, private FloorRepository $floorRepository)
	{
	}

	public function forProject(Project $project): ElectricalScheduleDto
	{
		return self::schedule(array_map($this->floor(...), $this->floorRepository->findByProject($project->id)));
	}

	public function forFloor(Floor $floor): ElectricalScheduleDto
	{
		return self::schedule([$this->floor($floor)]);
	}

	private function floor(Floor $floor): FloorElectricalDto
	{
		$scene = $this->sceneProvider->getScene($floor);
		$rooms = $scene->rooms;
		usort($rooms, static fn (RoomDto $a, RoomDto $b): int => strnatcmp($a->code, $b->code));

		$definitions = self::definitions($scene);
		$outlines = [];
		foreach ($rooms as $room) {
			$outlines[$room->id] = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $room->polygon);
		}
		$hidden = array_flip($scene->hiddenItemIds());
		/** @var array<int, array<string, ElectricalRowDto>> $rows by room id (0 outside every room) and point key */
		$rows = [];
		foreach ($scene->items as $item) {
			$definitionId = $item->definitionId ?? 0;
			$definition = $definitions[$definitionId] ?? null;
			if ($item->kind !== ItemKindEnum::Custom || isset($hidden[$item->id]) || $definition === null) {
				continue;
			}
			[$name, $body, $category] = $definition;
			$values = self::values($body, $item);
			$roomId = self::roomOf($item, $outlines);
			$key = sprintf('%s|%d|%d|%s', $category->value, $definitionId, $item->z, json_encode($values, JSON_THROW_ON_ERROR));
			$row = $rows[$roomId][$key] ?? null;
			$gangs = $values['gangs'] ?? 1;
			$outlets = $category === DefinitionCategoryEnum::Socket ? (is_int($gangs) ? $gangs : 1) : 0;
			$rows[$roomId][$key] = new ElectricalRowDto(
				category: $category,
				definitionId: $definitionId,
				name: $name,
				values: $values === [] ? new stdClass() : $values,
				z: $item->z,
				count: ($row->count ?? 0) + 1,
				outlets: ($row->outlets ?? 0) + $outlets,
				itemIds: [...$row->itemIds ?? [], $item->id],
			);
		}

		$schedule = array_map(
			static fn (RoomDto $room): RoomElectricalDto => self::room($room->id, $room->code, $room->name, $rows[$room->id] ?? []),
			$rooms,
		);
		if (isset($rows[0])) {
			$schedule[] = self::room(null, null, self::Unassigned, $rows[0]);
		}

		return new FloorElectricalDto(
			id: $floor->id,
			name: $floor->name,
			level: $floor->level,
			elevation: $floor->elevation,
			rooms: $schedule,
			totals: ElectricalTotalsDto::sum(array_map(static fn (RoomElectricalDto $r): ElectricalTotalsDto => $r->totals, $schedule)),
		);
	}

	/**
	 * The scene's definitions that have a category, by id.
	 *
	 * @return array<int, array{string, array<mixed>, DefinitionCategoryEnum}> name, body, category
	 */
	private static function definitions(SceneDto $scene): array
	{
		$definitions = [];
		foreach ($scene->definitions as $definition) {
			$body = JsonDecoder::decodeObject(json_encode($definition->definition, JSON_THROW_ON_ERROR));
			$category = DefinitionCategoryEnum::of($body);
			if ($category !== null) {
				$definitions[$definition->id] = [$definition->name, $body, $category];
			}
		}

		return $definitions;
	}

	/** @param array<string, ElectricalRowDto> $rows */
	private static function room(?int $id, ?string $code, string $name, array $rows): RoomElectricalDto
	{
		$order = array_flip(DefinitionCategoryEnum::values());
		$sortKey = static fn (ElectricalRowDto $r): array => [$order[$r->category->value], $r->name, $r->z, json_encode($r->values)];
		$sorted = array_values($rows);
		usort($sorted, static fn (ElectricalRowDto $a, ElectricalRowDto $b): int => $sortKey($a) <=> $sortKey($b));

		return new RoomElectricalDto(id: $id, code: $code, name: $name, rows: $sorted, totals: ElectricalTotalsDto::of($sorted));
	}

	/** @param list<FloorElectricalDto> $floors */
	private static function schedule(array $floors): ElectricalScheduleDto
	{
		return new ElectricalScheduleDto(
			floors: $floors,
			totals: ElectricalTotalsDto::sum(array_map(static fn (FloorElectricalDto $f): ElectricalTotalsDto => $f->totals, $floors)),
		);
	}

	/**
	 * The item's values of the definition's int, bool and select parameters, the defaults filled in.
	 *
	 * @param array<mixed> $definition
	 * @return array<string, int|bool|string>
	 */
	private static function values(array $definition, ItemDto $item): array
	{
		$params = is_array($item->params) ? $item->params : [];
		$given = is_array($params['values'] ?? null) ? $params['values'] : [];
		$values = [];
		foreach (DefinitionValidator::list($definition, 'parameters') as $parameter) {
			$key = $parameter['key'] ?? null;
			if (!is_string($key) || !in_array($parameter['type'] ?? null, self::ValueTypes, true)) {
				continue;
			}
			$value = $given[$key] ?? $parameter['default'] ?? null;
			if (is_int($value) || is_bool($value) || is_string($value)) {
				$values[$key] = $value;
			}
		}

		return $values;
	}

	/**
	 * The item's `roomId` when it is a room of the floor, otherwise the room whose outline holds its footprint centre;
	 * 0 outside every room.
	 *
	 * @param array<int, list<array{float, float}>> $outlines the rooms' polygons by id
	 */
	private static function roomOf(ItemDto $item, array $outlines): int
	{
		if ($item->roomId !== null && isset($outlines[$item->roomId])) {
			return $item->roomId;
		}
		$centre = [(float) $item->x, (float) $item->y];
		foreach ($outlines as $roomId => $outline) {
			if (PlanMath::contains($outline, $centre)) {
				return $roomId;
			}
		}

		return 0;
	}
}
