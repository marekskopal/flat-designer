<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Drawing;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Editor\BatchWriter;
use FlatDesigner\Service\Editor\Element\ElementHandler;
use FlatDesigner\Service\Editor\ElementHandlers;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * A run of walls drawn as a polyline of centre-line points: one wall per segment, straight or curved by its
 * `sagittas` entry, created as one change (one revision, one undo step, a `ChangeBatch`). Consecutive walls share their end points, so their
 * corners are mitred. The caller has checked access to the floor's project.
 */
final readonly class WallDrawer
{
	public const int MaxPoints = 200;

	/** Fields shared by every wall of the run. */
	public const array SharedFields = ['name', 'type', 'thickness', 'height', 'materialId', 'upToRoof'];

	private ElementHandler $walls;

	public function __construct(ElementHandlers $handlers, private BatchWriter $writer)
	{
		$this->walls = $handlers->get(ElementTypeEnum::Wall);
	}

	/**
	 * @param list<array{int, int}> $points centre-line points, mm
	 * @param bool $closed whether a last wall runs from the last point back to the first
	 * @param array<string, mixed> $fields any of `SharedFields`; the rest are the wall defaults
	 * @param list<int>|null $sagittas a curve per wall in the order drawn (the closing one last), mm (`Wall::$sagitta`); null: all straight
	 */
	public function drawWalls(
		User $user,
		Floor $floor,
		array $points,
		bool $closed,
		array $fields,
		?int $baseRevision = null,
		?array $sagittas = null,
	): EditResultDto
	{
		$unknown = array_diff(array_keys($fields), self::SharedFields);
		if ($unknown !== []) {
			throw EditException::invalid(sprintf('Unknown wall field(s): %s.', implode(', ', $unknown)));
		}
		$count = count($points);
		if ($count < ($closed ? 3 : 2) || $count > self::MaxPoints) {
			throw EditException::invalid(sprintf(
				'A %s run of walls needs %d to %d points.',
				$closed ? 'closed' : 'open',
				$closed ? 3 : 2,
				self::MaxPoints,
			));
		}

		$segments = self::segments($points, $closed, $sagittas);

		return $this->writer->transaction(function () use ($user, $floor, $segments, $fields, $baseRevision): EditResultDto {
			// Every wall is validated before the revision is bumped, so a bad segment changes nothing.
			$states = array_map(function (array $segment) use ($floor, $fields): array {
				[[$startX, $startY], [$endX, $endY], $sagitta] = $segment;
				$wall = ['startX' => $startX, 'startY' => $startY, 'endX' => $endX, 'endY' => $endY, 'sagitta' => $sagitta] + $fields;

				return $this->walls->normalize($floor, $wall + $this->walls->defaults($floor, $wall), null);
			}, $segments);

			$batch = $this->writer->start($floor, $baseRevision, sprintf('Drew %d wall(s)', count($states)));
			$wall = null;
			foreach ($states as $state) {
				$wall = $this->writer->create($user, $floor, $batch, $this->walls, $state);
			}
			assert($wall !== null);

			return $batch->result($this->walls->state($wall));
		});
	}

	/**
	 * The walls of the run: start, end and sagitta each.
	 *
	 * @param list<array{int, int}> $points
	 * @param list<int>|null $sagittas
	 * @return list<array{array{int, int}, array{int, int}, int}>
	 */
	private static function segments(array $points, bool $closed, ?array $sagittas): array
	{
		$count = count($points);
		$segments = [];
		for ($i = 0; $i < ($closed ? $count : $count - 1); $i++) {
			$segments[] = [$points[$i], $points[($i + 1) % $count], $sagittas[$i] ?? 0];
		}
		if ($sagittas !== null && count($sagittas) !== count($segments)) {
			throw EditException::invalid(sprintf('Give one sagitta per wall of the run: %d.', count($segments)));
		}

		return $segments;
	}
}
