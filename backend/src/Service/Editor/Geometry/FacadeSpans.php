<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;

/** The wall faces outside every room (FD-88): where the façade bands and the façade quantities go. */
final class FacadeSpans
{
	/**
	 * The façades (FD-88): the stretches of each wall face that border no room, mm along the wall's centre line, within
	 * the face's own extent (mitred at the wall's joints: `WallJoints`). A face bordering an indoor room is covered where
	 * one of the room's edges runs along it (`faces()`); the rest of it, and the faces along open-air rooms (a garden, a
	 * terrace: the house's outer walls), is outside. Twin of `facadeSpans()` in `ceilings.ts`, kept
	 * identical by the `facades` cases of `tests/fixtures/wall-spans.json`.
	 *
	 * @param list<WallDto> $walls
	 * @param list<array{polygon: list<array{float, float}>, outdoor: bool}> $rooms the rooms' outlines, open-air ones flagged
	 * @return list<array{wall: WallDto, side: OpeningSideEnum, spans: list<array{float, float}>}>
	 */
	public static function of(array $walls, array $rooms): array
	{
		$lines = array_map(static fn (WallDto $wall): WallLine => WallLine::fromDto($wall), $walls);
		$byId = array_combine(array_map(static fn (WallDto $wall): int => $wall->id, $walls), $lines);
		$covered = [];
		foreach ($rooms as $room) {
			if ($room['outdoor']) {
				continue;
			}
			foreach (self::coveredBy($room['polygon'], $walls, $byId) as [$key, $span]) {
				$covered[$key][] = $span;
			}
		}
		$joints = WallJoints::of($lines);
		$facades = [];
		foreach ($walls as $k => $wall) {
			$line = $lines[$k];
			if ($line->chord() <= 0.0) {
				continue;
			}
			$ends = $joints[$wall->id] ?? WallJoints::Square;
			$extents = [
				[OpeningSideEnum::Left, -$ends['startLeft'], $line->length() + $ends['endLeft']],
				[OpeningSideEnum::Right, -$ends['startRight'], $line->length() + $ends['endRight']],
			];
			foreach ($extents as [$side, $from, $to]) {
				$spans = Spans::subtract([[$from, $to]], Spans::merge($covered[$wall->id . ':' . $side->value] ?? []));
				if ($spans !== []) {
					$facades[] = ['wall' => $wall, 'side' => $side, 'spans' => $spans];
				}
			}
		}

		return $facades;
	}

	/**
	 * The stretches of wall faces a room's edges run along, mm along each wall's centre line, keyed "wall id:side".
	 *
	 * @param list<array{float, float}> $polygon
	 * @param list<WallDto> $walls
	 * @param array<int, WallLine> $lines by wall id
	 * @return list<array{string, array{float, float}}>
	 */
	private static function coveredBy(array $polygon, array $walls, array $lines): array
	{
		$covered = [];
		$count = count($polygon);
		for ($i = 0; $i < $count; $i++) {
			[$a, $b] = [$polygon[$i], $polygon[($i + 1) % $count]];
			$length = hypot($b[0] - $a[0], $b[1] - $a[1]);
			if ($length <= 0.0) {
				continue;
			}
			$dir = [($b[0] - $a[0]) / $length, ($b[1] - $a[1]) / $length];
			foreach (WallFaceSpans::faces($a, $b, $walls) as $face) {
				$line = $lines[$face['wall']->id];
				$s0 = $line->along([$a[0] + $dir[0] * $face['from'], $a[1] + $dir[1] * $face['from']]);
				$s1 = $line->along([$a[0] + $dir[0] * $face['to'], $a[1] + $dir[1] * $face['to']]);
				$covered[] = [$face['wall']->id . ':' . $face['side']->value, [min($s0, $s1), max($s0, $s1)]];
			}
		}

		return $covered;
	}
}
