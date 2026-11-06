<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Drawing;

use Izbik\Dto\EditResultDto;
use Izbik\Model\Entity\Enum\ElementTypeEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\User;
use Izbik\Model\Entity\Wall;
use Izbik\Model\Repository\FurnitureDefinitionRepository;
use Izbik\Service\Editor\BatchWriter;
use Izbik\Service\Editor\Element\ElementHandler;
use Izbik\Service\Editor\ElementHandlers;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Editor\Geometry\WallLine;

/**
 * A run of units (cabinets, appliances, any items) laid along one face of a straight wall as one change (one revision,
 * one undo step, a `ChangeBatch`), each unit's back on the wall's `side` face and its front turned away from the wall.
 * Units standing on the floor (`z` 0) follow one another from `offset` mm along the wall from its start, those above
 * it (wall cabinets) do the same in a row of their own from the same `offset`; `gapBefore` leaves space before a unit
 * in its row. A worktop, if asked for, covers each stretch of counter-height units (`CounterHeight` at most) between
 * taller ones, `overhang` deeper than the deepest of them and on the highest. All go into a new group. The caller has
 * checked access to the floor's project.
 */
final readonly class CabinetRun
{
	public const int MaxUnits = 40;

	/** Floor-standing units at most this high (mm) are under the worktop; taller ones (larders, fridges) break it. */
	public const int CounterHeight = 1000;

	/** Fields a unit may give besides its placement. */
	public const array UnitFields = ['kind', 'definitionId', 'modelId', 'name', 'width', 'depth', 'height', 'z', 'materialId', 'params', 'gapBefore'];

	/** Fields the worktop may give. */
	public const array WorktopFields = ['definitionId', 'name', 'thickness', 'overhang', 'materialId', 'params'];

	/** The global definition a worktop is drawn from unless the run names one. */
	public const string WorktopDefinition = 'Worktop';

	private ElementHandler $items;

	private ElementHandler $groups;

	private ElementHandler $walls;

	public function __construct(ElementHandlers $handlers, private BatchWriter $writer, private FurnitureDefinitionRepository $definitions)
	{
		$this->items = $handlers->get(ElementTypeEnum::Item);
		$this->groups = $handlers->get(ElementTypeEnum::Group);
		$this->walls = $handlers->get(ElementTypeEnum::Wall);
	}

	/**
	 * @param string $side "Left" | "Right" of the wall (from its start to its end): the face the units stand against
	 * @param list<array<string, mixed>> $units in order along the wall, each with `width`
	 * @param array<string, mixed>|null $worktop
	 */
	public function lay(
		User $user,
		Floor $floor,
		int $wallId,
		string $side,
		int $offset,
		array $units,
		?array $worktop,
		string $groupName,
		?int $roomId = null,
		?int $baseRevision = null,
	): EditResultDto {
		self::check($side, $offset, $units);

		return $this->writer->transaction(
			function () use ($user, $floor, $wallId, $side, $offset, $units, $worktop, $groupName, $roomId, $baseRevision): EditResultDto {
				$wall = $this->writer->existing($floor, $this->walls, $wallId);
				assert($wall instanceof Wall);
				if ($wall->sagitta !== 0) {
					throw EditException::invalid('A cabinet run needs a straight wall.');
				}
				$face = new RunFace(WallLine::fromWall($wall), $side === 'Left' ? 1 : -1, $wall->thickness / 2);

				// Every unit is placed and validated before the revision is bumped, so a bad one changes nothing.
				[$states, $stretches] = $this->placeUnits($floor, $face, $offset, $units, $roomId);
				if ($worktop !== null) {
					$states = [...$states, ...$this->worktops($floor, $worktop, $face, $stretches, $roomId)];
				}

				$group = $this->groups->normalize($floor, ['name' => $groupName], null);
				$batch = $this->writer->start(
					$floor,
					$baseRevision,
					sprintf('Laid a run of %d unit(s) as %s', count($units), $this->groups->label($group)),
				);
				$created = $this->writer->create($user, $floor, $batch, $this->groups, $group);
				foreach ($states as $state) {
					$this->writer->create($user, $floor, $batch, $this->items, ['groupId' => $created->id] + $state);
				}

				return $batch->result($this->groups->state($created));
			},
		);
	}

	/** @param list<array<string, mixed>> $units */
	private static function check(string $side, int $offset, array $units): void
	{
		if ($side !== 'Left' && $side !== 'Right') {
			throw EditException::invalid('Field "side" must be "Left" or "Right".');
		}
		if ($units === [] || count($units) > self::MaxUnits) {
			throw EditException::invalid(sprintf('A cabinet run needs 1 to %d units.', self::MaxUnits));
		}
		if ($offset < 0) {
			throw EditException::invalid('A cabinet run starts at an offset of 0 or more along the wall.');
		}
	}

	/**
	 * The normalised worktops over the stretches; refused when there is none to lay them on.
	 *
	 * @param array<string, mixed> $worktop
	 * @param list<array{from: int, to: int, depth: int, height: int}> $stretches
	 * @return list<array<string, mixed>>
	 */
	private function worktops(Floor $floor, array $worktop, RunFace $face, array $stretches, ?int $roomId): array
	{
		if ($stretches === []) {
			throw EditException::invalid('A worktop needs units standing on the floor (z 0) under it.');
		}

		return array_map(
			fn (array $stretch): array => $this->items->normalize($floor, $this->worktop($floor, $worktop, $face, $stretch, $roomId), null),
			$stretches,
		);
	}

	/**
	 * The units' normalised states placed along the face, and the stretches of counter-height floor units (span, depth and
	 * height of each) a worktop covers.
	 *
	 * @param list<array<string, mixed>> $units
	 * @return array{list<array<string, mixed>>, list<array{from: int, to: int, depth: int, height: int}>}
	 */
	private function placeUnits(Floor $floor, RunFace $face, int $offset, array $units, ?int $roomId): array
	{
		// One cursor along the wall for the floor row, one for the row above it.
		$along = ['floor' => $offset, 'upper' => $offset];
		$states = [];
		$stretches = [];
		$stretch = null;
		foreach ($units as $unit) {
			$fields = self::unitFields($unit);
			$gap = $fields['gapBefore'] ?? 0;
			unset($fields['gapBefore']);
			if (!is_int($gap) || $gap < 0) {
				throw EditException::invalid('A unit\'s "gapBefore" must be a whole number of mm, 0 or more.');
			}
			$fields += ['kind' => 'Custom', 'roomId' => $roomId];
			$fields += $this->items->defaults($floor, $fields);
			$width = self::mm($fields['width'] ?? null);
			$depth = self::mm($fields['depth'] ?? null);
			$height = self::mm($fields['height'] ?? null);
			$row = ($fields['z'] ?? 0) === 0 ? 'floor' : 'upper';
			$start = $along[$row] + $gap;
			$states[] = $this->items->normalize($floor, $face->place($fields, $start, $width, $depth), null);
			$along[$row] = $start + $width;
			if ($row !== 'floor') {
				continue;
			}
			if ($height > self::CounterHeight) {
				// A tall unit ends the stretch under the worktop.
				$stretches = self::closed($stretches, $stretch);
				$stretch = null;
				continue;
			}
			$stretch = [
				'from' => $stretch['from'] ?? $start,
				'to' => $start + $width,
				'depth' => max($stretch['depth'] ?? 0, $depth),
				'height' => max($stretch['height'] ?? 0, $height),
			];
		}
		$end = max($along['floor'], $along['upper']);
		if ($end > $face->length()) {
			throw EditException::invalid(sprintf('The run ends %d mm along the wall, which is %d mm long.', $end, $face->length()));
		}

		return [$states, self::closed($stretches, $stretch)];
	}

	/**
	 * @param list<array{from: int, to: int, depth: int, height: int}> $stretches
	 * @param array{from: int, to: int, depth: int, height: int}|null $stretch
	 * @return list<array{from: int, to: int, depth: int, height: int}>
	 */
	private static function closed(array $stretches, ?array $stretch): array
	{
		return $stretch === null ? $stretches : [...$stretches, $stretch];
	}

	/**
	 * The worktop over a stretch of counter-height units, in the global Worktop definition unless the run names one.
	 *
	 * @param array<string, mixed> $worktop
	 * @param array{from: int, to: int, depth: int, height: int} $stretch
	 * @return array<string, mixed>
	 */
	private function worktop(Floor $floor, array $worktop, RunFace $face, array $stretch, ?int $roomId): array
	{
		$unknown = array_diff(array_keys($worktop), self::WorktopFields);
		if ($unknown !== []) {
			throw EditException::invalid(sprintf('Unknown worktop field(s): %s.', implode(', ', $unknown)));
		}
		$definitionId = $worktop['definitionId'] ?? $this->definitions->findGlobalByName(self::WorktopDefinition)?->id;
		if (!is_int($definitionId)) {
			throw EditException::invalid('No worktop definition: give the worktop a definitionId.');
		}
		$thickness = self::mm($worktop['thickness'] ?? 38);
		$overhang = $worktop['overhang'] ?? 20;
		if (!is_int($overhang) || $overhang < 0) {
			throw EditException::invalid('The worktop\'s "overhang" must be a whole number of mm, 0 or more.');
		}
		$width = $stretch['to'] - $stretch['from'];
		$depth = $stretch['depth'] + $overhang;
		$fields = array_filter([
			'kind' => 'Custom',
			'definitionId' => $definitionId,
			'name' => $worktop['name'] ?? null,
			'width' => $width,
			'depth' => $depth,
			'height' => $thickness,
			'z' => $stretch['height'],
			'materialId' => $worktop['materialId'] ?? null,
			'params' => $worktop['params'] ?? [],
		], static fn (mixed $value): bool => $value !== null) + ['roomId' => $roomId];
		$fields += $this->items->defaults($floor, $fields);

		return $face->place($fields, $stretch['from'], $width, $depth);
	}

	/**
	 * @param array<string, mixed> $unit
	 * @return array<string, mixed>
	 */
	private static function unitFields(array $unit): array
	{
		$unknown = array_diff(array_keys($unit), self::UnitFields);
		if ($unknown !== []) {
			throw EditException::invalid(sprintf('Unknown unit field(s): %s.', implode(', ', $unknown)));
		}
		if (!is_int($unit['width'] ?? null)) {
			throw EditException::invalid('Every unit of a cabinet run needs its "width" (mm).');
		}

		return $unit;
	}

	private static function mm(mixed $value): int
	{
		if (!is_int($value) || $value <= 0) {
			throw EditException::invalid('Unit sizes must be whole numbers of mm above 0.');
		}

		return $value;
	}
}
