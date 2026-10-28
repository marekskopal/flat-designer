<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\PlotRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\Geometry\Ground;
use FlatDesigner\Service\Editor\Geometry\WallBottom;
use FlatDesigner\Service\Editor\Geometry\WallLine;

/**
 * A wall's bottom (FD-87): on a base (`baseStart` → `baseEnd`) or on the ground of the floor's plots (`onGround`).
 * Its top stands over the base, and the openings' sills on the bottom.
 */
final readonly class WallBase
{
	/** How far a wall's bottom may lie above or below the floor's level, mm (as a plot's ground). */
	public const int Limit = 10000;

	/** The least a wall's top stands over its base at its ends, mm. */
	private const int MinOverBase = 50;

	public function __construct(private PlotRepository $plotRepository)
	{
	}

	/** A base field; missing in changes logged before site walls: on the floor's level. */
	public static function read(FieldReader $fields, string $key): int
	{
		return $fields->raw($key) === null ? 0 : $fields->int($key, -self::Limit, self::Limit);
	}

	/**
	 * A wall on a base keeps its top over it at both ends; on the ground, the viewer stops it at its top.
	 *
	 * @param array{height: int, heightEnd: int|null, baseStart: int, baseEnd: int, onGround: bool, ...} $state
	 */
	public static function check(array $state): void
	{
		if ($state['onGround']) {
			return;
		}
		$overStart = $state['height'] - $state['baseStart'];
		$overEnd = ($state['heightEnd'] ?? $state['height']) - $state['baseEnd'];
		if (min($overStart, $overEnd) < self::MinOverBase) {
			throw EditException::invalid(
				sprintf('The wall\'s top must stand at least %d mm over its base at both ends.', self::MinOverBase),
			);
		}
	}

	/**
	 * The slope and the base of a wall turned round (start and end swapped), so they stay where they are on the plan:
	 * each is swapped unless the request sets it anew (undo, redo).
	 *
	 * @param array{height: int, heightEnd: int|null, baseStart: int, baseEnd: int, ...} $next
	 * @return array{int, int|null, int, int} height, heightEnd, baseStart, baseEnd
	 */
	public static function turnedRound(Wall $wall, array $next): array
	{
		[$height, $heightEnd] = [$next['height'], $next['heightEnd']];
		if ($heightEnd !== null && [$height, $heightEnd] === [$wall->height, $wall->heightEnd]) {
			[$height, $heightEnd] = [$heightEnd, $height];
		}
		[$baseStart, $baseEnd] = [$next['baseStart'], $next['baseEnd']];
		if ([$baseStart, $baseEnd] === [$wall->baseStart, $wall->baseEnd]) {
			[$baseStart, $baseEnd] = [$baseEnd, $baseStart];
		}

		return [$height, $heightEnd, $baseStart, $baseEnd];
	}

	/** An opening placed anew on the wall stands on its bottom. */
	public function checkSill(Wall $wall, WallLine $line, int $offset, int $width, int $sillHeight): void
	{
		$base = ['baseStart' => $wall->baseStart, 'baseEnd' => $wall->baseEnd, 'onGround' => $wall->onGround];
		self::sill($this->bottom($base, $line, $wall->floor->id), $offset, $width, $sillHeight);
	}

	/**
	 * A wall set on a new base or the ground, or moved along the ground, keeps its openings' sills on it.
	 *
	 * @param array{startX: int, startY: int, endX: int, endY: int, sagitta: int, baseStart: int, baseEnd: int, onGround: bool, ...} $next
	 * @param list<Opening> $openings
	 * @param array<int, int> $offsets by opening id, after the change
	 */
	public function checkSills(Wall $wall, array $next, WallLine $line, array $openings, array $offsets): void
	{
		$sameBase = [$next['baseStart'], $next['baseEnd'], $next['onGround']] === [$wall->baseStart, $wall->baseEnd, $wall->onGround];
		$sameLine = [$next['startX'], $next['startY'], $next['endX'], $next['endY'], $next['sagitta']]
			=== [$wall->startX, $wall->startY, $wall->endX, $wall->endY, $wall->sagitta];
		if ($sameBase && ($sameLine || !$next['onGround'])) {
			return;
		}
		$bottom = $this->bottom($next, $line, $wall->floor->id);
		foreach ($openings as $opening) {
			self::sill($bottom, $offsets[$opening->id] ?? $opening->offset, $opening->width, $opening->sillHeight);
		}
	}

	/** @param array{baseStart: int, baseEnd: int, onGround: bool, ...} $wall */
	private function bottom(array $wall, WallLine $line, int $floorId): WallBottom
	{
		$ground = $wall['onGround'] ? Ground::of($this->plotRepository->findByFloor($floorId)) : null;

		return new WallBottom($line, $wall['baseStart'], $wall['baseEnd'], $ground);
	}

	private static function sill(WallBottom $bottom, int $offset, int $width, int $sillHeight): void
	{
		$lowest = $bottom->highestOver($offset, $offset + $width);
		if ($sillHeight < $lowest) {
			throw EditException::invalid(sprintf('The sill is under the wall\'s bottom over the opening (%d mm).', $lowest));
		}
	}
}
