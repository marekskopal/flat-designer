<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Read;

use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfException;
use Izbik\Service\Dxf\DxfLayer;
use Izbik\Service\Dxf\DxfSegment;
use Izbik\Service\Dxf\DxfTransform;
use function abs;
use function atan2;
use function ceil;
use function count;
use function hypot;
use function max;
use function sprintf;
use const M_PI;

/**
 * Collects the lines and arcs of a drawing in world coordinates, with the layers and what was counted on each. Arcs
 * squeezed into ellipses by an insert's uneven scale become straight pieces; a mirroring transform turns an arc round.
 */
final class DxfGeometry
{
	/** Lines and arcs after the blocks are expanded. */
	public const int MaxGeometry = 500000;

	/** @var array<string, DxfLayer> */
	public array $layers = [];

	/** @var list<DxfSegment> */
	public array $segments = [];

	/** @var list<DxfArc> */
	public array $arcs = [];

	private int $expanded = 0;

	public function layer(string $name): DxfLayer
	{
		return $this->layers[$name] ??= new DxfLayer($name);
	}

	public function segment(DxfLayer $layer, DxfTransform $transform, float $x1, float $y1, float $x2, float $y2, ?string $block): void
	{
		[$ax, $ay] = $transform->apply($x1, $y1);
		[$bx, $by] = $transform->apply($x2, $y2);
		$this->count();
		$layer->lines++;
		$this->segments[] = new DxfSegment($layer->name, $ax, $ay, $bx, $by, $block);
	}

	/** An arc counter-clockwise from `$start` to `$end` (radians) in the transform's source frame. */
	public function arc(DxfLayer $layer, DxfTransform $transform, DxfArc $local): void
	{
		if ($local->radius <= 0) {
			return;
		}
		$start = $local->start;
		$end = $local->end;
		while ($end <= $start) {
			$end += 2 * M_PI;
		}
		if (!$transform->isConformal()) {
			$this->squeezedArc(
				$layer,
				$transform,
				new DxfArc($layer->name, $local->cx, $local->cy, $local->radius, $start, $end, $local->block),
			);

			return;
		}
		[$newStart, $newEnd] = $end - $start >= 2 * M_PI - 1e-9 ? [0.0, 2 * M_PI] : self::turnedAngles($transform, $start, $end);
		[$cx, $cy] = $transform->apply($local->cx, $local->cy);
		$this->count();
		$layer->arcs++;
		$this->arcs[] = new DxfArc($layer->name, $cx, $cy, $local->radius * $transform->scale(), $newStart, $newEnd, $local->block);
	}

	/**
	 * A polyline's edges: straight, or arcs where a vertex has a bulge to the next.
	 *
	 * @param list<array{float, float, float}> $vertices x, y and the bulge to the next vertex
	 */
	public function polyline(DxfLayer $layer, DxfTransform $transform, array $vertices, bool $closed, ?string $block): void
	{
		$count = count($vertices);
		for ($i = 0; $i < ($closed ? $count : $count - 1); $i++) {
			[$x1, $y1, $bulge] = $vertices[$i];
			[$x2, $y2] = $vertices[($i + 1) % $count];
			if (abs($bulge) < 1e-9) {
				$this->segment($layer, $transform, $x1, $y1, $x2, $y2, $block);
				continue;
			}
			$arc = self::bulgeArc($layer->name, $x1, $y1, $x2, $y2, $bulge, $block);
			if ($arc !== null) {
				$this->arc($layer, $transform, $arc);
			}
		}
	}

	/** The arc of a polyline edge with a bulge (the tangent of a quarter of its angle, + counter-clockwise). */
	private static function bulgeArc(string $layer, float $x1, float $y1, float $x2, float $y2, float $bulge, ?string $block): ?DxfArc
	{
		$length = hypot($x2 - $x1, $y2 - $y1);
		if ($length < 1e-9) {
			return null;
		}
		// The centre lies on the chord's left normal for a counter-clockwise arc.
		$along = $length / 2 * (1 - $bulge * $bulge) / (2 * $bulge);
		$cx = ($x1 + $x2) / 2 - ($y2 - $y1) / $length * $along;
		$cy = ($y1 + $y2) / 2 + ($x2 - $x1) / $length * $along;
		$a1 = atan2($y1 - $cy, $x1 - $cx);
		$a2 = atan2($y2 - $cy, $x2 - $cx);
		[$start, $end] = $bulge > 0 ? [$a1, $a2] : [$a2, $a1];

		return new DxfArc($layer, $cx, $cy, hypot($x1 - $cx, $y1 - $cy), $start, $end, $block);
	}

	/** @return array{float, float} */
	private static function turnedAngles(DxfTransform $transform, float $start, float $end): array
	{
		$newStart = $transform->angle($start);
		$newEnd = $transform->angle($end);
		if ($transform->determinant() < 0) {
			// Mirrored: the arc now runs clockwise, so counter-clockwise from its far end.
			[$newStart, $newEnd] = [$newEnd, $newStart];
		}
		while ($newEnd <= $newStart) {
			$newEnd += 2 * M_PI;
		}

		return [$newStart, $newEnd];
	}

	private function squeezedArc(DxfLayer $layer, DxfTransform $transform, DxfArc $arc): void
	{
		$pieces = (int) max(4, ceil(($arc->end - $arc->start) / (M_PI / 16)));
		$points = $arc->samples($pieces);
		for ($i = 1; $i <= $pieces; $i++) {
			$this->segment($layer, $transform, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $arc->block);
		}
	}

	/** Counts entities about to be expanded from a block; too many end the reading. */
	public function expand(int $entities, int $limit): void
	{
		$this->expanded += $entities;
		if ($this->expanded > $limit) {
			throw new DxfException(sprintf('The drawing\'s blocks expand into more than %d entities.', $limit));
		}
	}

	private function count(): void
	{
		if (count($this->segments) + count($this->arcs) >= self::MaxGeometry) {
			throw new DxfException(sprintf('The drawing has more than %d lines and arcs.', self::MaxGeometry));
		}
	}
}
