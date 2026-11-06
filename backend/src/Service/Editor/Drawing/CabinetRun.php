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

/**
 * A run of units (cabinets, appliances, any items) laid along one face of a straight wall as one change (one revision,
 * one undo step, a `ChangeBatch`): from `offset` mm along the wall from its start, each unit `gapBefore` after the one
 * before it, its back on the wall's `side` face and its front turned away from the wall; optionally a worktop over the
 * units standing on the floor (`z` 0), `overhang` deeper than the deepest of them and on the highest; all in a new
 * group. The caller has checked access to the floor's project.
 */
final readonly class CabinetRun
{
	public const int MaxUnits = 40;

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
		if ($side !== 'Left' && $side !== 'Right') {
			throw EditException::invalid('Field "side" must be "Left" or "Right".');
		}
		if ($units === [] || count($units) > self::MaxUnits) {
			throw EditException::invalid(sprintf('A cabinet run needs 1 to %d units.', self::MaxUnits));
		}

		return $this->writer->transaction(
			function () use ($user, $floor, $wallId, $side, $offset, $units, $worktop, $groupName, $roomId, $baseRevision): EditResultDto {
				$wall = $this->writer->existing($floor, $this->walls, $wallId);
				assert($wall instanceof Wall);
				if ($wall->sagitta !== 0) {
					throw EditException::invalid('A cabinet run needs a straight wall.');
				}
				$frame = self::frame($wall, $side);

				// Every unit is placed and validated before the revision is bumped, so a bad one changes nothing.
				[$states, $base] = $this->placeUnits($floor, $frame, $offset, $units, $roomId);
				if ($worktop !== null && $base !== null) {
					$states[] = $this->items->normalize($floor, $this->worktop($floor, $worktop, $frame, $base, $roomId), null);
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

	/**
	 * The units' normalised states placed along the face, and the span, depth and height of those standing on the floor
	 * (null when none does).
	 *
	 * @param array{x: float, y: float, dx: float, dy: float, nx: float, ny: float, face: float, length: int} $frame
	 * @param list<array<string, mixed>> $units
	 * @return array{list<array<string, mixed>>, array{from: int, to: int, depth: int, height: int}|null}
	 */
	private function placeUnits(Floor $floor, array $frame, int $offset, array $units, ?int $roomId): array
	{
		$along = $offset;
		$states = [];
		$base = null;
		foreach ($units as $unit) {
			$fields = self::unitFields($unit);
			$gap = $fields['gapBefore'] ?? 0;
			unset($fields['gapBefore']);
			if (!is_int($gap) || $gap < 0) {
				throw EditException::invalid('A unit\'s "gapBefore" must be a whole number of mm, 0 or more.');
			}
			$along += $gap;
			$fields += ['kind' => 'Custom', 'roomId' => $roomId];
			$fields += $this->items->defaults($floor, $fields);
			$width = self::mm($fields['width'] ?? null);
			$depth = self::mm($fields['depth'] ?? null);
			$states[] = $this->items->normalize($floor, self::placed($fields, $frame, $along, $width, $depth), null);
			if (($fields['z'] ?? 0) === 0) {
				$base = [
					'from' => min($base['from'] ?? $along, $along),
					'to' => $along + $width,
					'depth' => max($base['depth'] ?? 0, $depth),
					'height' => max($base['height'] ?? 0, self::mm($fields['height'] ?? null)),
				];
			}
			$along += $width;
		}
		if ($along > $frame['length']) {
			throw EditException::invalid(sprintf('The run ends %d mm along the wall, which is %d mm long.', $along, $frame['length']));
		}

		return [$states, $base];
	}

	/**
	 * The wall's face on `side`: its start point, the unit vector along the wall, the unit normal out of the face into
	 * the room, the face's offset from the centre line and the wall's length, all in mm.
	 *
	 * @return array{x: float, y: float, dx: float, dy: float, nx: float, ny: float, face: float, length: int}
	 */
	private static function frame(Wall $wall, string $side): array
	{
		$length = hypot($wall->endX - $wall->startX, $wall->endY - $wall->startY);
		if ($length < 1) {
			throw EditException::invalid('A cabinet run needs a wall of some length.');
		}
		$dx = ($wall->endX - $wall->startX) / $length;
		$dy = ($wall->endY - $wall->startY) / $length;
		$sign = $side === 'Left' ? 1 : -1;

		return [
			'x' => $wall->startX,
			'y' => $wall->startY,
			'dx' => $dx,
			'dy' => $dy,
			'nx' => -$dy * $sign,
			'ny' => $dx * $sign,
			'face' => $wall->thickness / 2,
			'length' => (int) floor($length),
		];
	}

	/**
	 * The fields with the item placed: its back on the face, `along` mm from the wall start to its start, its front
	 * (local −y at rotation 0, turned counter-clockwise) facing away from the wall.
	 *
	 * @param array<string, mixed> $fields
	 * @param array{x: float, y: float, dx: float, dy: float, nx: float, ny: float, face: float, length: int} $frame
	 * @return array<string, mixed>
	 */
	private static function placed(array $fields, array $frame, int $along, int $width, int $depth): array
	{
		$centreAlong = $along + $width / 2;
		$out = $frame['face'] + $depth / 2;
		$rotation = (int) round(rad2deg(atan2($frame['nx'], -$frame['ny'])));

		return [
			'x' => (int) round($frame['x'] + $frame['dx'] * $centreAlong + $frame['nx'] * $out),
			'y' => (int) round($frame['y'] + $frame['dy'] * $centreAlong + $frame['ny'] * $out),
			'rotation' => ($rotation % 360 + 360) % 360,
		] + $fields;
	}

	/**
	 * The worktop over the run's floor-standing units, in the global Worktop definition unless the run names one.
	 *
	 * @param array<string, mixed> $worktop
	 * @param array{x: float, y: float, dx: float, dy: float, nx: float, ny: float, face: float, length: int} $frame
	 * @param array{from: int, to: int, depth: int, height: int} $base
	 * @return array<string, mixed>
	 */
	private function worktop(Floor $floor, array $worktop, array $frame, array $base, ?int $roomId): array
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
		$fields = [
			'kind' => 'Custom',
			'definitionId' => $definitionId,
			'name' => $worktop['name'] ?? null,
			'width' => $base['to'] - $base['from'],
			'depth' => $base['depth'] + $overhang,
			'height' => $thickness,
			'z' => $base['height'],
			'materialId' => $worktop['materialId'] ?? null,
			'params' => $worktop['params'] ?? [],
			'roomId' => $roomId,
		];
		$fields = array_filter($fields, static fn (mixed $value): bool => $value !== null) + ['roomId' => $roomId];
		$fields += $this->items->defaults($floor, $fields);

		return self::placed($fields, $frame, $base['from'], $base['to'] - $base['from'], $base['depth'] + $overhang);
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
