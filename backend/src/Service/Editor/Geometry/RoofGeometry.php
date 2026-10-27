<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use FlatDesigner\Model\Entity\Roof;
use const M_PI;

/**
 * The planes of a roof over its rectangle and the heights (mm above the floor's level) of its underside and top at a
 * plan point. Twin of `viewer/engine/roof-geometry.ts`, kept identical by `tests/fixtures/roof-geometry.json`.
 *
 * The roof's own frame: s along the ridge (`rotation` counter-clockwise from plan x), t across it towards the back, both
 * from the centre (x, y). The underside is `eaveHeight` along the eaves line (the rectangle's edges) and rises at
 * `pitch` inwards; past the eaves the planes go on down over the overhang. Every pitched roof's underside is the lowest
 * of its planes, so a point's height needs no lookup of the plane under it.
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-type RoofPlane array{polygon: list<Point>, gradient: Point, height: float}
 * @phpstan-type LocalPlane array{polygon: list<Point>, gs: float, gt: float, base: float}
 */
final readonly class RoofGeometry
{
	/** mm, how far off an edge a point still counts as on it. */
	private const float Epsilon = 1e-6;

	public function __construct(
		public RoofShapeEnum $shape,
		public int $x,
		public int $y,
		public int $rotation,
		public int $width,
		public int $depth,
		public int $eaveHeight,
		public int $pitch,
		public int $overhang,
		public int $vergeOverhang,
		public int $thickness,
	) {
	}

	public static function fromRoof(Roof $roof): self
	{
		return new self(
			$roof->shape,
			$roof->x,
			$roof->y,
			$roof->rotation,
			$roof->width,
			$roof->depth,
			$roof->eaveHeight,
			$roof->pitch,
			$roof->overhang,
			$roof->vergeOverhang,
			$roof->thickness,
		);
	}

	/**
	 * The highest underside over a point of the roofs covering it (where roofs meet, the space reaches the higher);
	 * null where none does.
	 *
	 * @param list<self> $roofs
	 */
	public static function undersideOf(array $roofs, float $x, float $y): ?float
	{
		$highest = null;
		foreach ($roofs as $roof) {
			$height = $roof->undersideAt($x, $y);
			if ($height !== null && ($highest === null || $height > $highest)) {
				$highest = $height;
			}
		}

		return $highest;
	}

	/** The rise of the planes per mm inwards; 0 for a flat roof. */
	public function slope(): float
	{
		return $this->shape->isPitched() ? tan($this->pitch * M_PI / 180) : 0.0;
	}

	/**
	 * Half the roof's size in its own frame, overhangs included: along the ridge and across it.
	 *
	 * @return Point
	 */
	public function halfExtent(): array
	{
		$along = $this->shape === RoofShapeEnum::Gable || $this->shape === RoofShapeEnum::Shed ? $this->vergeOverhang : $this->overhang;

		return [$this->width / 2 + $along, $this->depth / 2 + $this->overhang];
	}

	/**
	 * A plan point in the roof's frame: [s, t].
	 *
	 * @return Point
	 */
	public function toLocal(float $x, float $y): array
	{
		[$cos, $sin] = $this->axes();
		$dx = $x - $this->x;
		$dy = $y - $this->y;

		return [$dx * $cos + $dy * $sin, -$dx * $sin + $dy * $cos];
	}

	/**
	 * A point of the roof's frame in plan.
	 *
	 * @return Point
	 */
	public function toPlan(float $s, float $t): array
	{
		[$cos, $sin] = $this->axes();

		return [$this->x + $s * $cos - $t * $sin, $this->y + $s * $sin + $t * $cos];
	}

	public function covers(float $x, float $y): bool
	{
		[$s, $t] = $this->toLocal($x, $y);
		[$halfS, $halfT] = $this->halfExtent();

		return abs($s) <= $halfS + self::Epsilon && abs($t) <= $halfT + self::Epsilon;
	}

	/** Height of the underside at a plan point; null where the roof does not reach. */
	public function undersideAt(float $x, float $y): ?float
	{
		if (!$this->covers($x, $y)) {
			return null;
		}
		[$s, $t] = $this->toLocal($x, $y);

		return $this->eaveHeight + $this->inwards($s, $t) * $this->slope();
	}

	/** Height of the top of the covering at a plan point (the thickness is square to the slope); null where the roof does not reach. */
	public function topAt(float $x, float $y): ?float
	{
		$underside = $this->undersideAt($x, $y);

		return $underside === null ? null : $underside + $this->verticalThickness();
	}

	/** The thickness measured upright. */
	public function verticalThickness(): float
	{
		return $this->shape->isPitched() ? $this->thickness / cos($this->pitch * M_PI / 180) : (float) $this->thickness;
	}

	/** Height of the underside at the ridge (a shed's top edge; a flat roof's eaves). */
	public function ridgeHeight(): float
	{
		$rise = match ($this->shape) {
			RoofShapeEnum::Gable => $this->depth / 2,
			RoofShapeEnum::Hip => min($this->width, $this->depth) / 2,
			RoofShapeEnum::Shed => $this->depth,
			RoofShapeEnum::Flat => 0,
		};

		return $this->eaveHeight + $rise * $this->slope();
	}

	/**
	 * The index in `planes()` of the plane over a plan point; null where the roof does not reach. On a hip line the front
	 * or back plane, on a gable's ridge the back one.
	 */
	public function planeAt(float $x, float $y): ?int
	{
		[$s, $t] = $this->toLocal($x, $y);

		return $this->localPlaneAt($s, $t);
	}

	/** `planeAt()` of a point in the roof's frame. */
	public function localPlaneAt(float $s, float $t): ?int
	{
		[$halfS, $halfT] = $this->halfExtent();
		if (abs($s) > $halfS + self::Epsilon || abs($t) > $halfT + self::Epsilon) {
			return null;
		}

		return match ($this->shape) {
			RoofShapeEnum::Gable => $t < 0 ? 0 : 1,
			RoofShapeEnum::Hip => $this->depth / 2 - abs($t) <= $this->width / 2 - abs($s) ? ($t < 0 ? 0 : 2) : ($s > 0 ? 1 : 3),
			RoofShapeEnum::Shed, RoofShapeEnum::Flat => 0,
		};
	}

	/**
	 * The planes in plan, overhangs included: each outline counter-clockwise, starting at its eaves (a gable or shed:
	 * front then back; a hip: front, right, back, left), and the underside's height over them as `height` at the roof's
	 * centre plus `gradient` (mm of rise per mm in plan x and y) times the offset from it.
	 *
	 * @return list<RoofPlane>
	 */
	public function planes(): array
	{
		[$cos, $sin] = $this->axes();

		return array_map(fn (array $plane): array => [
			'polygon' => array_map(fn (array $p): array => $this->toPlan($p[0], $p[1]), $plane['polygon']),
			'gradient' => [$plane['gs'] * $cos - $plane['gt'] * $sin, $plane['gs'] * $sin + $plane['gt'] * $cos],
			'height' => $plane['base'],
		], $this->localPlanes());
	}

	/**
	 * The planes in the roof's own frame: outline, the underside's rise per mm along s and t, and its height at the centre.
	 *
	 * @return list<LocalPlane>
	 */
	public function localPlanes(): array
	{
		[$a, $b] = $this->halfExtent();
		$k = $this->slope();
		$e = (float) $this->eaveHeight;
		$halfD = $this->depth / 2;

		return match ($this->shape) {
			RoofShapeEnum::Gable => [
				self::plane([[-$a, -$b], [$a, -$b], [$a, 0.0], [-$a, 0.0]], 0.0, $k, $e + $halfD * $k),
				self::plane([[$a, $b], [-$a, $b], [-$a, 0.0], [$a, 0.0]], 0.0, -$k, $e + $halfD * $k),
			],
			RoofShapeEnum::Hip => $this->hipPlanes($a, $b, $k, $e),
			RoofShapeEnum::Shed => [
				self::plane([[-$a, -$b], [$a, -$b], [$a, $b], [-$a, $b]], 0.0, $k, $e + $halfD * $k),
			],
			RoofShapeEnum::Flat => [
				self::plane([[-$a, -$b], [$a, -$b], [$a, $b], [-$a, $b]], 0.0, 0.0, $e),
			],
		};
	}

	/**
	 * A hip roof's four planes; the ridge runs along the longer side and shrinks to the apex of a pyramid over a square,
	 * leaving the front and back (or the sides) triangles. The overhang is the same all round, so the hip lines of the
	 * outline with it are those of the eaves line.
	 *
	 * @return list<LocalPlane>
	 */
	private function hipPlanes(float $a, float $b, float $k, float $e): array
	{
		$ks = max($a - $b, 0.0);
		$kt = max($b - $a, 0.0);
		$halfW = $this->width / 2;
		$halfD = $this->depth / 2;

		return [
			self::plane([[-$a, -$b], [$a, -$b], [$ks, -$kt], [-$ks, -$kt]], 0.0, $k, $e + $halfD * $k),
			self::plane([[$a, -$b], [$a, $b], [$ks, $kt], [$ks, -$kt]], -$k, 0.0, $e + $halfW * $k),
			self::plane([[$a, $b], [-$a, $b], [-$ks, $kt], [$ks, $kt]], 0.0, -$k, $e + $halfD * $k),
			self::plane([[-$a, $b], [-$a, -$b], [-$ks, -$kt], [-$ks, $kt]], $k, 0.0, $e + $halfW * $k),
		];
	}

	/**
	 * A plane, its outline without repeated points (a ridge shrunk to an apex).
	 *
	 * @param list<Point> $polygon
	 * @return LocalPlane
	 */
	private static function plane(array $polygon, float $gs, float $gt, float $base): array
	{
		$distinct = [];
		foreach ($polygon as $point) {
			$last = $distinct === [] ? null : $distinct[count($distinct) - 1];
			if ($last === null || abs($last[0] - $point[0]) > self::Epsilon || abs($last[1] - $point[1]) > self::Epsilon) {
				$distinct[] = $point;
			}
		}

		return ['polygon' => $distinct, 'gs' => $gs, 'gt' => $gt, 'base' => $base];
	}

	/** How far a point of the roof's frame lies inside the eaves, along the slope of the plane over it (negative over the overhang). */
	private function inwards(float $s, float $t): float
	{
		$halfW = $this->width / 2;
		$halfD = $this->depth / 2;

		return match ($this->shape) {
			RoofShapeEnum::Gable => $halfD - abs($t),
			RoofShapeEnum::Hip => min($halfD - abs($t), $halfW - abs($s)),
			RoofShapeEnum::Shed => $t + $halfD,
			RoofShapeEnum::Flat => 0.0,
		};
	}

	/** @return array{float, float} cos and sin of the rotation */
	private function axes(): array
	{
		$angle = $this->rotation * M_PI / 180;

		return [cos($angle), sin($angle)];
	}
}
