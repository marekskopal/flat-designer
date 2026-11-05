<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use Izbik\Service\Dxf\Plan\ArcPairer;
use Izbik\Service\Dxf\Plan\CollinearMerger;
use Izbik\Service\Dxf\Plan\EndJoiner;
use Izbik\Service\Dxf\Plan\EntityGroups;
use Izbik\Service\Dxf\Plan\FaceSet;
use Izbik\Service\Dxf\Plan\OpeningFinder;
use Izbik\Service\Dxf\Plan\Placement;
use Izbik\Service\Dxf\Plan\PlanLeftovers;
use Izbik\Service\Dxf\Plan\PlanWalls;
use Izbik\Service\Dxf\Plan\WallPairer;
use function count;
use function sprintf;

/**
 * Reads the walls and openings of a drawing's chosen layers, turns and moves them onto the plan and rounds them to
 * whole millimetres. Nothing is written. The steps: faces of the wall layers (`FaceSet`) paired into wall pieces
 * (`WallPairer`, `ArcPairer` for curved walls), pieces of one wall joined with the gaps between them as openings
 * (`CollinearMerger`), ends joined at corners (`EndJoiner`), the openings' kinds read from the opening layers
 * (`OpeningFinder`), then placed (`Placement`) and written out (`PlanWalls`, `PlanLeftovers`).
 */
final readonly class DxfPlanner
{
	public const int MaxWalls = 2000;

	public const int MaxOpenings = 2000;

	public function plan(DxfDrawing $drawing, DxfPlanOptions $options): DxfPlan
	{
		$warnings = [];
		$units = $options->units ?? $drawing->units;
		if ($units === null || !DxfUnits::isKnown($units)) {
			$warnings[] = 'The drawing does not say its units, so it is read in millimetres. Choose the units if that is wrong.';
			$units = DxfUnits::Millimetres;
		}
		$scale = DxfUnits::scale($units);
		$wallLayers = $drawing->onLayers($options->wallLayers, $scale);
		$openingLayers = $drawing->onLayers($options->openingLayers, $scale);

		$faces = new FaceSet($wallLayers->segments);
		$pieces = new WallPairer()->pair($faces);
		// Checked before the steps whose work grows with the square of the pieces: a wrong layer stops early.
		self::check(count($pieces), self::MaxWalls * 4);
		$merger = new CollinearMerger($faces, $pieces);
		$walls = $merger->walls;
		$arcs = new ArcPairer($wallLayers->arcs);
		self::check(count($walls) + count($arcs->curved), self::MaxWalls);
		EndJoiner::join($walls);
		new OpeningFinder(new EntityGroups($openingLayers->segments, $openingLayers->arcs))->place($walls, $merger->gaps);

		$placement = new Placement($options, $walls, $arcs->curved);
		$out = new PlanWalls($placement, $walls, $arcs->curved);
		if (count($out->openings) > self::MaxOpenings) {
			throw new DxfException(sprintf('The chosen layers make more than %d openings.', self::MaxOpenings));
		}
		$leftovers = new PlanLeftovers($placement, $faces, $arcs->loose, $walls);

		return new DxfPlan(
			$units,
			$out->walls,
			$out->openings,
			$leftovers->lines,
			[...$warnings, ...$out->warnings, ...$leftovers->warnings($out->walls !== [])],
		);
	}

	private static function check(int $count, int $limit): void
	{
		if ($count > $limit) {
			throw new DxfException(
				sprintf('The chosen layers make more than %d walls. Choose only the layers with the walls.', self::MaxWalls),
			);
		}
	}
}
