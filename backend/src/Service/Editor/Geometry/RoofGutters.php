<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Model\Entity\Enum\RoofEdgeEnum;
use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use const ARRAY_FILTER_USE_KEY;

/**
 * A roof's rainwater goods (FD-91): its gutters run along the outer edge of the overhang at the eaves (a gable's front
 * and back, a shed's front, a hip's four sides), where the roof is the top (stopping where another roof of the floor is
 * higher, as the caps do), and its downpipes stand `DownpipeOffset` off the eaves line, the walls' outer face. Without a
 * list (null) the downpipes stand `CornerInset` in from the ends of the front and back eaves; a downpipe whose outlet
 * misses a gutter is left out. Heights are the underside at the overhang's edge. Twin of `roofGutters()` /
 * `roofDownpipes()` (`viewer/engine/rainwater.ts`), kept identical by `backend/tests/fixtures/roof-gutters.json` (run by
 * `RoofGuttersTest` and `roof-details.spec.ts`).
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-type GutterRun array{edge: string, from: Point, to: Point, height: float}
 * @phpstan-type Downpipe array{edge: string, at: Point, outlet: Point, top: float}
 */
final readonly class RoofGutters
{
	/** mm from the eaves line (the walls' outer face) out to a downpipe's centre. */
	public const int DownpipeOffset = 100;

	/** mm in from the eaves' corners, along the front and back, to a default downpipe. */
	public const int CornerInset = 150;

	/** mm: two roofs' undersides this close tie (`RoofCover::Same`). */
	private const float Same = 0.5;

	/**
	 * @param list<RoofGeometry> $roofs the floor's
	 * @return list<GutterRun> in plan
	 */
	public static function runs(array $roofs, int $index): array
	{
		$roof = $roofs[$index];
		$rest = array_values(array_filter($roofs, static fn (int $i): bool => $i !== $index, ARRAY_FILTER_USE_KEY));
		$height = $roof->eaveHeight - $roof->overhang * $roof->slope();
		$runs = [];
		foreach (self::eaves($roof) as $edge => [$from, $to]) {
			$p = $roof->toPlan($from[0], $from[1]);
			$q = $roof->toPlan($to[0], $to[1]);
			foreach (self::topStretches($roof, $rest, $p, $q) as [$a, $b]) {
				$runs[] = ['edge' => $edge, 'from' => self::lerp($p, $q, $a), 'to' => self::lerp($p, $q, $b), 'height' => $height];
			}
		}

		return $runs;
	}

	/**
	 * @param list<array{edge: string, along: int}>|null $downpipes the roof's, null at the corners
	 * @param list<GutterRun> $runs the roof's (`runs()`)
	 * @return list<Downpipe>
	 */
	public static function downpipes(RoofGeometry $roof, ?array $downpipes, array $runs): array
	{
		$halfW = $roof->width / 2;
		$halfD = $roof->depth / 2;
		$overhang = $roof->halfExtent();
		$result = [];
		foreach ($downpipes ?? self::corners($roof) as ['edge' => $edge, 'along' => $along]) {
			$side = RoofEdgeEnum::tryFrom($edge);
			if ($side === null || !$side->isEavesOf($roof->shape) || abs($along) > ($side->alongWidth() ? $halfW : $halfD)) {
				continue;
			}
			[$wall, $outlet] = match ($side) {
				RoofEdgeEnum::Front => [[$along, -$halfD - self::DownpipeOffset], [$along, -$overhang[1]]],
				RoofEdgeEnum::Back => [[$along, $halfD + self::DownpipeOffset], [$along, $overhang[1]]],
				RoofEdgeEnum::Left => [[-$halfW - self::DownpipeOffset, $along], [-$overhang[0], $along]],
				RoofEdgeEnum::Right => [[$halfW + self::DownpipeOffset, $along], [$overhang[0], $along]],
			};
			$outletPlan = $roof->toPlan($outlet[0], $outlet[1]);
			$run = self::runAt($runs, $edge, $outletPlan);
			if ($run !== null) {
				$result[] = ['edge' => $edge, 'at' => $roof->toPlan($wall[0], $wall[1]), 'outlet' => $outletPlan, 'top' => $run['height']];
			}
		}

		return $result;
	}

	/**
	 * The eaves edges of a roof's shape on the overhang's edge, in its frame, keyed by `RoofEdgeEnum`.
	 *
	 * @return array<string, array{Point, Point}>
	 */
	private static function eaves(RoofGeometry $roof): array
	{
		[$a, $b] = $roof->halfExtent();
		$edges = [
			RoofEdgeEnum::Front->value => [[-$a, -$b], [$a, -$b]],
			RoofEdgeEnum::Right->value => [[$a, -$b], [$a, $b]],
			RoofEdgeEnum::Back->value => [[$a, $b], [-$a, $b]],
			RoofEdgeEnum::Left->value => [[-$a, $b], [-$a, -$b]],
		];

		return array_filter(
			$edges,
			static fn (string $edge): bool => RoofEdgeEnum::from($edge)->isEavesOf($roof->shape),
			ARRAY_FILTER_USE_KEY,
		);
	}

	/** @return list<array{edge: string, along: int}> */
	private static function corners(RoofGeometry $roof): array
	{
		if ($roof->shape === RoofShapeEnum::Flat) {
			return [];
		}
		$along = max(0, intdiv($roof->width, 2) - self::CornerInset);
		$edges = $roof->shape === RoofShapeEnum::Shed ? [RoofEdgeEnum::Front] : [RoofEdgeEnum::Front, RoofEdgeEnum::Back];
		$corners = [];
		foreach ($edges as $edge) {
			$corners[] = ['edge' => $edge->value, 'along' => -$along];
			$corners[] = ['edge' => $edge->value, 'along' => $along];
		}

		return $corners;
	}

	/**
	 * The run of an edge an outlet lies on, within a tenth of a mm.
	 *
	 * @param list<GutterRun> $runs
	 * @param Point $point
	 * @return GutterRun|null
	 */
	private static function runAt(array $runs, string $edge, array $point): ?array
	{
		foreach ($runs as $run) {
			$length = hypot($run['to'][0] - $run['from'][0], $run['to'][1] - $run['from'][1]);
			$along = hypot($point[0] - $run['from'][0], $point[1] - $run['from'][1])
				+ hypot($run['to'][0] - $point[0], $run['to'][1] - $point[1]);
			if ($run['edge'] === $edge && $along - $length < 0.1) {
				return $run;
			}
		}

		return null;
	}

	/**
	 * The stretches (0–1 along p → q) where the roof is the top, as `topStretches()` gives the caps'.
	 *
	 * @param list<RoofGeometry> $rest the floor's other roofs
	 * @param Point $p
	 * @param Point $q
	 * @return list<array{float, float}>
	 */
	private static function topStretches(RoofGeometry $roof, array $rest, array $p, array $q): array
	{
		if ($rest === []) {
			return [[0.0, 1.0]];
		}
		$ts = RoofBreaks::along([$roof, ...$rest], $p, $q, []);
		$stretches = [];
		for ($i = 1; $i < count($ts); $i++) {
			$middle = self::lerp($p, $q, ($ts[$i - 1] + $ts[$i]) / 2);
			$own = $roof->undersideAt($middle[0], $middle[1]);
			$higher = RoofGeometry::undersideOf($rest, $middle[0], $middle[1]);
			if ($own === null || $higher !== null && $higher > $own + self::Same) {
				continue;
			}
			$last = count($stretches) - 1;
			if ($last >= 0 && $stretches[$last][1] === $ts[$i - 1]) {
				$stretches[$last][1] = $ts[$i];
			} else {
				$stretches[] = [$ts[$i - 1], $ts[$i]];
			}
		}

		return $stretches;
	}

	/**
	 * @param Point $p
	 * @param Point $q
	 * @return Point
	 */
	private static function lerp(array $p, array $q, float $t): array
	{
		return [$p[0] + ($q[0] - $p[0]) * $t, $p[1] + ($q[1] - $p[1]) * $t];
	}
}
