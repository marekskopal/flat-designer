<?php

declare(strict_types=1);

namespace Izbik\Service\Schedule;

use Izbik\Dto\ElectricalRowDto;
use Izbik\Dto\ElectricalScheduleDto;
use Izbik\Dto\ElectricalTotalsDto;
use Izbik\Dto\FloorElectricalDto;
use Izbik\Dto\ItemDto;
use Izbik\Dto\RoomDto;
use Izbik\Dto\RoomElectricalDto;
use Izbik\Dto\SceneDto;
use Izbik\Model\Entity\Enum\ItemKindEnum;
use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Project;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Service\Editor\Geometry\PlanMath;
use Izbik\Service\Furniture\DefinitionCategoryEnum;
use Izbik\Service\Furniture\DefinitionSchedule;
use Izbik\Service\Furniture\DefinitionTranslations;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\Provider\SceneProvider;
use Izbik\Service\Translator\Translator;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * Electrical schedule of a project or a floor (working copy, shown variants only): the Custom items whose definition has
 * a `category`, and the LED strips as lights (definition 0), per room — the item's `roomId`, otherwise the room whose
 * outline holds its footprint centre — grouped by definition, parameter values and elevation, with the counts per category. The definition's `schedule` settings
 * (`DefinitionSchedule`) say which values count and which parameter multiplies an item. `lighting` links the switches
 * with the lights they control (`LightingCircuits`).
 */
final readonly class ElectricalScheduleService
{
	public function __construct(
		private SceneProvider $sceneProvider,
		private FloorRepository $floorRepository,
		private Translator $translator,
	)
	{
	}

	/** `$locale` names the definitions by their `translations`. */
	public function forProject(Project $project, LocaleEnum $locale = LocaleEnum::En): ElectricalScheduleDto
	{
		return self::schedule(
			array_map(
				fn (Floor $floor): FloorElectricalDto => $this->floor($floor, $locale),
				$this->floorRepository->findByProject($project->id),
			),
		);
	}

	public function forFloor(Floor $floor, LocaleEnum $locale = LocaleEnum::En): ElectricalScheduleDto
	{
		return self::schedule([$this->floor($floor, $locale)]);
	}

	private function floor(Floor $floor, LocaleEnum $locale): FloorElectricalDto
	{
		$scene = $this->sceneProvider->getScene($floor);
		$rooms = $scene->rooms;
		usort($rooms, static fn (RoomDto $a, RoomDto $b): int => strnatcmp($a->code, $b->code));

		$definitions = self::definitions($scene, $locale);
		$outlines = [];
		foreach ($rooms as $room) {
			$outlines[$room->id] = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $room->polygon);
		}
		$hidden = array_flip($scene->hiddenItemIds());
		/** @var array<int, array<string, ElectricalRowDto>> $rows by room id (0 outside every room) and point key */
		$rows = [];
		$switches = [];
		$lights = [];
		/** @var array<int, int> $roomOfItem the switches' and lights' rooms by item id (0 outside every room) */
		$roomOfItem = [];
		$ledStrip = $this->translator->translate('schedules.ledStrip', $locale);
		foreach ($scene->items as $item) {
			$point = isset($hidden[$item->id]) ? null : self::point($item, $definitions, $ledStrip);
			if ($point === null) {
				continue;
			}
			[$category, $definitionId, $name, $values, $units] = $point;
			$roomId = self::roomOf($item, $outlines);
			if ($category === DefinitionCategoryEnum::Switch) {
				$switches[] = $item;
				$roomOfItem[$item->id] = $roomId;
			} elseif ($category === DefinitionCategoryEnum::Light) {
				$lights[] = $item;
				$roomOfItem[$item->id] = $roomId;
			}
			$key = sprintf('%s|%d|%d|%s', $category->value, $definitionId, $item->z, json_encode($values, JSON_THROW_ON_ERROR));
			$row = $rows[$roomId][$key] ?? null;
			$rows[$roomId][$key] = new ElectricalRowDto(
				category: $category,
				definitionId: $definitionId,
				name: $name,
				values: $values === [] ? new stdClass() : $values,
				z: $item->z,
				count: ($row->count ?? 0) + 1,
				units: ($row->units ?? 0) + $units,
				itemIds: [...$row->itemIds ?? [], $item->id],
			);
		}

		$schedule = array_map(
			static fn (RoomDto $room): RoomElectricalDto => self::room($room->id, $room->code, $room->name, $rows[$room->id] ?? []),
			$rooms,
		);
		if (isset($rows[0])) {
			$schedule[] = self::room(null, null, $this->translator->translate('schedules.notInRoom', $locale), $rows[0]);
		}

		return new FloorElectricalDto(
			id: $floor->id,
			name: $floor->name,
			level: $floor->level,
			elevation: $floor->elevation,
			rooms: $schedule,
			totals: ElectricalTotalsDto::sum(array_map(static fn (RoomElectricalDto $r): ElectricalTotalsDto => $r->totals, $schedule)),
			lighting: LightingCircuits::of($switches, $lights, $rooms, $roomOfItem),
		);
	}

	/**
	 * What a shown item counts as: a Custom item by its definition's category, an LED strip (`$ledStrip` its name) as a
	 * light of definition 0; null for anything else.
	 *
	 * @param array<int, array{string, array<mixed>, DefinitionCategoryEnum, DefinitionSchedule}> $definitions
	 * @return array{DefinitionCategoryEnum, int, string, array<string, int|bool|string>, int}|null category, definition id,
	 *     name, values, units
	 */
	private static function point(ItemDto $item, array $definitions, string $ledStrip): ?array
	{
		if ($item->kind === ItemKindEnum::LedStrip) {
			return [DefinitionCategoryEnum::Light, 0, $ledStrip, [], 1];
		}
		$definitionId = $item->definitionId ?? 0;
		$definition = $definitions[$definitionId] ?? null;
		if ($item->kind !== ItemKindEnum::Custom || $definition === null) {
			return null;
		}
		[$name, $body, $category, $schedule] = $definition;
		$given = self::given($item);

		return [$category, $definitionId, $name, self::values($body, $given, $schedule), $schedule->units($body, $given)];
	}

	/**
	 * The scene's definitions that have a category, by id.
	 *
	 * @return array<int, array{string, array<mixed>, DefinitionCategoryEnum, DefinitionSchedule}> name, body, category, settings
	 */
	private static function definitions(SceneDto $scene, LocaleEnum $locale): array
	{
		$definitions = [];
		foreach ($scene->definitions as $definition) {
			$body = JsonDecoder::decodeObject(json_encode($definition->definition, JSON_THROW_ON_ERROR));
			$category = DefinitionCategoryEnum::of($body);
			if ($category === null) {
				continue;
			}

			$definitions[$definition->id] = [
				DefinitionTranslations::name($body, $definition->name, $locale),
				$body,
				$category, DefinitionSchedule::of($body)];
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

	/** @return array<mixed> the item's `params.values` */
	private static function given(ItemDto $item): array
	{
		$params = is_array($item->params) ? $item->params : [];

		return is_array($params['values'] ?? null) ? $params['values'] : [];
	}

	/**
	 * The item's values of the definition's int, bool and select parameters that `schedule.values` lists (all of them
	 * without), the defaults filled in, in the definition's order.
	 *
	 * @param array<mixed> $definition
	 * @param array<mixed> $given
	 * @return array<string, int|bool|string>
	 */
	private static function values(array $definition, array $given, DefinitionSchedule $schedule): array
	{
		$values = [];
		foreach (DefinitionValidator::list($definition, 'parameters') as $parameter) {
			$key = $parameter['key'] ?? null;
			if (
				!is_string($key)
				|| !in_array($parameter['type'] ?? null, DefinitionSchedule::ValueTypes, true)
				|| $schedule->values !== null
				&& !in_array($key, $schedule->values, true)
			) {
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
