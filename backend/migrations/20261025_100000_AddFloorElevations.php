<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Stacked floors (FD-36): `floors.elevation`, the finished floor level above the project datum, and
 * `floors.slab_thickness`, the slab under the floor, both mm. Each project's floors are stacked from their levels and
 * tallest walls as `FloorStack::stack()` did when this was written: the floor nearest level 0 at the datum (a storey of
 * default walls per level above it), the others on the tallest wall of the floor below, or under the one above, plus a
 * 300 mm slab at level 0 and below and a 250 mm one above.
 */
final class AddFloorElevationsMigration extends Migration
{
	private const int DefaultWallHeight = 2600;

	private const int GroundSlab = 300;

	private const int UpperSlab = 250;

	public function up(): void
	{
		$this->table('floors')
			->addColumn('elevation', Type::Int, default: 0)
			->addColumn('slab_thickness', Type::Int, default: self::GroundSlab)
			->alter();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$rows = $pdo->query(
			'SELECT f.id, f.project_id, f.level, MAX(w.height) AS tallest FROM floors f '
			. 'LEFT JOIN walls w ON w.floor_id = f.id AND w.deleted_at IS NULL GROUP BY f.id, f.project_id, f.level',
		);
		$update = $pdo->prepare('UPDATE floors SET elevation = ?, slab_thickness = ? WHERE id = ?');
		if ($rows === false || $update === false) {
			throw new \RuntimeException('Could not prepare the floor stacking.');
		}

		/** @var array<int, array<int, array{level: int, tallestWall: int}>> $projects */
		$projects = [];
		/** @var array{id: int|string, project_id: int|string, level: int|string, tallest: int|string|null} $row */
		foreach ($rows->fetchAll() as $row) {
			$projects[(int) $row['project_id']][(int) $row['id']] = [
				'level' => (int) $row['level'],
				'tallestWall' => $row['tallest'] === null ? self::DefaultWallHeight : (int) $row['tallest'],
			];
		}
		foreach ($projects as $floors) {
			foreach (self::stack($floors) as $id => [$elevation, $slab]) {
				$update->execute([$elevation, $slab, $id]);
			}
		}
	}

	public function down(): void
	{
		$this->table('floors')
			->dropColumn('elevation')
			->dropColumn('slab_thickness')
			->alter();
	}

	/**
	 * @param array<int, array{level: int, tallestWall: int}> $floors by id
	 * @return array<int, array{int, int}> elevation and slab by id
	 */
	private static function stack(array $floors): array
	{
		$levels = array_unique(array_map(fn (array $f): int => $f['level'], $floors));
		usort($levels, fn (int $a, int $b): int => [abs($a), -$a] <=> [abs($b), -$b]);
		$anchor = $levels[0];

		$result = [];
		/** @var list<array{level: int, elevation: int, slab: int, tallestWall: int}> $placed */
		$placed = [];
		foreach (self::outwards($floors, $anchor) as $id) {
			['level' => $level, 'tallestWall' => $tallestWall] = $floors[$id];
			$slab = $level <= 0 ? self::GroundSlab : self::UpperSlab;
			$elevation = $level === $anchor
				? $anchor * (self::DefaultWallHeight + self::UpperSlab)
				: self::elevation($level, $slab, $tallestWall, $placed);
			$result[$id] = [$elevation, $slab];
			$placed[] = ['level' => $level, 'elevation' => $elevation, 'slab' => $slab, 'tallestWall' => $tallestWall];
		}

		return $result;
	}

	/**
	 * The floor ids outwards from the anchor level: up in ascending order, then down in descending order.
	 *
	 * @param array<int, array{level: int, tallestWall: int}> $floors by id
	 * @return list<int>
	 */
	private static function outwards(array $floors, int $anchor): array
	{
		$order = array_keys($floors);
		usort($order, function (int $a, int $b) use ($floors, $anchor): int {
			$la = $floors[$a]['level'];
			$lb = $floors[$b]['level'];

			return [$la < $anchor, $la < $anchor ? -$la : $la, $a] <=> [$lb < $anchor, $lb < $anchor ? -$lb : $lb, $b];
		});

		return $order;
	}

	/**
	 * Level with a placed floor of the same level, else on the top of the nearest floor below, else under the nearest
	 * floor above.
	 *
	 * @param list<array{level: int, elevation: int, slab: int, tallestWall: int}> $placed
	 */
	private static function elevation(int $level, int $slab, int $tallestWall, array $placed): int
	{
		$below = null;
		$above = null;
		foreach ($placed as $floor) {
			if ($floor['level'] === $level) {
				return $floor['elevation'];
			}
			if ($floor['level'] < $level) {
				$top = [$floor['level'], $floor['elevation'] + $floor['tallestWall']];
				$below = $below === null || $top > $below ? $top : $below;
			} else {
				$underside = [$floor['level'], $floor['elevation'] - $floor['slab']];
				$above = $above === null || $underside < $above ? $underside : $above;
			}
		}

		return match (true) {
			$below !== null => $below[1] + $slab,
			$above !== null => $above[1] - $tallestWall,
			default => 0,
		};
	}
}
