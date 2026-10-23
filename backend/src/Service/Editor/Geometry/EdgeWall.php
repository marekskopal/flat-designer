<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;

/**
 * How a wall lies along a room edge a → b: the side of it whose face is on the edge and, for a curved wall, where the
 * edge's ends lie along its centre line (mm, unsorted). Twin of `edgeWall()` / `edgeStretch()` in `plan-geometry.ts`.
 */
final readonly class EdgeWall
{
	/** @param array{float, float}|null $along */
	private function __construct(public WallLine $wall, public OpeningSideEnum $side, private ?array $along)
	{
	}

	/**
	 * The face of the wall on the edge a → b, if any: a straight wall runs parallel to the edge with its centre line half
	 * a thickness (± tolerance) away; a curved one has the edge's ends and middle half a thickness (± tolerance) off its
	 * arc, on one side, beside it (the edge is a piece of the outline along its face, its ends reaching half a thickness
	 * on).
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 */
	public static function of(array $a, array $b, WallLine $wall, float $tolerance): ?self
	{
		$half = $wall->thickness / 2;
		if ($wall->arc() === null) {
			$length = hypot($b[0] - $a[0], $b[1] - $a[1]);
			[$dx, $dy] = $wall->tangent(0.0);
			$parallel = abs((($b[0] - $a[0]) * $dy - ($b[1] - $a[1]) * $dx) / $length) < 1e-3;
			// Distance of a from the centre line, positive on its left.
			$normal = $wall->across($a);
			if (!$parallel || abs(abs($normal) - $half) > $tolerance) {
				return null;
			}

			return new self($wall, $normal > 0 ? OpeningSideEnum::Left : OpeningSideEnum::Right, null);
		}

		$across = array_map(static fn (array $point): float => $wall->across($point), [$a, [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2], $b]);
		foreach ($across as $distance) {
			if (abs(abs($distance) - $half) > $tolerance || $distance > 0 !== $across[0] > 0) {
				return null;
			}
		}
		$along = [$wall->along($a), $wall->along($b)];
		if (max($along) <= -$half || min($along) >= $wall->length() + $half) {
			return null;
		}

		return new self($wall, $across[0] > 0 ? OpeningSideEnum::Left : OpeningSideEnum::Right, $along);
	}

	/**
	 * Where a stretch of the wall's centre line (mm along it) lies on the edge from a along `dir`, `length` long, mm from
	 * a (unsorted): projected, on a curved wall clipped to the edge and placed between its ends. Null when it misses the
	 * edge.
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $dir
	 * @return array{float, float}|null
	 */
	public function stretch(array $a, array $dir, float $length, float $from, float $to): ?array
	{
		if ($this->along === null) {
			$project = static fn (array $point): float => ($point[0] - $a[0]) * $dir[0] + ($point[1] - $a[1]) * $dir[1];

			return [$project($this->wall->point($from, 0.0)), $project($this->wall->point($to, 0.0))];
		}
		[$ta, $tb] = $this->along;
		$lo = max($from, min($ta, $tb));
		$hi = min($to, max($ta, $tb));
		if ($hi <= $lo) {
			return null;
		}

		return [($lo - $ta) / ($tb - $ta) * $length, ($hi - $ta) / ($tb - $ta) * $length];
	}
}
