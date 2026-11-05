<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Read;

use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfException;
use Izbik\Service\Dxf\DxfLayer;
use Izbik\Service\Dxf\DxfTransform;
use function cos;
use function count;
use function deg2rad;
use function max;
use function sin;
use function sprintf;
use function strtoupper;
use const M_PI;

/**
 * Turns entity records into lines and arcs: lines, polylines (old and light), arcs, circles and hatch boundaries in
 * their object coordinate system, and block inserts expanded (scale, mirror, rotation, arrays; entities on layer `0`
 * in a block take the insert's layer, and remember the innermost block's name). Other entities are counted only.
 *
 * @phpstan-import-type Pair from DxfTokenizer
 * @phpstan-import-type Entity from DxfTokenizer
 * @phpstan-type Block array{x: float, y: float, entities: list<Entity>}
 */
final readonly class DxfEntities
{
	public const int MaxDepth = 8;

	/** Columns × rows of one array insert. */
	public const int MaxArray = 10000;

	/** Entities an insert's block may be expanded into, all inserts together (nested arrays of empty blocks too). */
	public const int MaxExpanded = 2000000;

	/** The record types whose pairs are read; the others are only counted on their layer. */
	public const array Read = ['LINE', 'LWPOLYLINE', 'POLYLINE', 'VERTEX', 'SEQEND', 'ARC', 'CIRCLE', 'INSERT', 'HATCH', 'BLOCK', 'ENDBLK'];

	/** @param array<string, Block> $blocks by upper-case name */
	public function __construct(private DxfGeometry $geometry, private DxfText $text, private array $blocks)
	{
	}

	/** @param Entity $entity */
	public function emit(array $entity, DxfTransform $transform, ?string $parentLayer, ?string $block, int $depth): void
	{
		$pairs = $entity['pairs'];
		$layerName = $this->text->decode(DxfPairs::value($pairs, 8) ?? '0');
		$layer = $this->geometry->layer($layerName === '0' && $parentLayer !== null ? $parentLayer : $layerName);

		if ($entity['type'] === 'LINE') {
			// A line's points are world coordinates already.
			$this->geometry->segment(
				$layer,
				$transform,
				DxfPairs::number($pairs, 10),
				DxfPairs::number($pairs, 20),
				DxfPairs::number($pairs, 11),
				DxfPairs::number($pairs, 21),
				$block,
			);

			return;
		}

		$ocs = DxfTransform::ocs(DxfPairs::number($pairs, 210), DxfPairs::number($pairs, 220), DxfPairs::number($pairs, 230, 1.0));
		if ($ocs === null) {
			$layer->other++;

			return;
		}
		$this->emitFlat($entity, $layer, $transform->after($ocs), $block, $depth);
	}

	/** @param Entity $entity */
	private function emitFlat(array $entity, DxfLayer $layer, DxfTransform $transform, ?string $block, int $depth): void
	{
		$pairs = $entity['pairs'];
		match ($entity['type']) {
			'LWPOLYLINE' => $this->geometry->polyline(
				$layer,
				$transform,
				self::lightVertices($pairs),
				(DxfPairs::int($pairs, 70) & 1) !== 0,
				$block,
			),
			'POLYLINE' => $this->oldPolyline($layer, $transform, $entity, $block),
			'ARC', 'CIRCLE' => $this->geometry->arc($layer, $transform, self::arc($layer, $entity, $block)),
			'INSERT' => $this->insert($layer, $transform, $pairs, $depth),
			'HATCH' => DxfHatch::emit($this->geometry, $layer, $transform, $pairs, $block),
			default => self::skip($layer),
		};
	}

	/** @param Entity $entity */
	private static function arc(DxfLayer $layer, array $entity, ?string $block): DxfArc
	{
		$pairs = $entity['pairs'];
		$circle = $entity['type'] === 'CIRCLE';

		return new DxfArc(
			$layer->name,
			DxfPairs::number($pairs, 10),
			DxfPairs::number($pairs, 20),
			DxfPairs::number($pairs, 40),
			$circle ? 0.0 : deg2rad(DxfPairs::number($pairs, 50)),
			$circle ? 2 * M_PI : deg2rad(DxfPairs::number($pairs, 51)),
			$block,
		);
	}

	private static function skip(DxfLayer $layer): void
	{
		$layer->other++;
	}

	/**
	 * @param list<Pair> $pairs
	 * @return list<array{float, float, float}> x, y and the bulge to the next vertex
	 */
	private static function lightVertices(array $pairs): array
	{
		$vertices = [];
		$last = -1;
		foreach ($pairs as [$code, $value]) {
			if ($code === 10) {
				$vertices[] = [(float) $value, 0.0, 0.0];
				$last++;
			} elseif ($last >= 0 && $code === 20) {
				$vertices[$last] = [$vertices[$last][0], (float) $value, $vertices[$last][2]];
			} elseif ($last >= 0 && $code === 42) {
				$vertices[$last] = [$vertices[$last][0], $vertices[$last][1], (float) $value];
			}
		}

		return $vertices;
	}

	/** @param Entity $entity */
	private function oldPolyline(DxfLayer $layer, DxfTransform $transform, array $entity, ?string $block): void
	{
		$flags = DxfPairs::int($entity['pairs'], 70);
		if (($flags & (16 | 64)) !== 0) {
			// A polygon mesh or a polyface mesh.
			$layer->other++;

			return;
		}
		$vertices = [];
		foreach ($entity['vertices'] ?? [] as $vertex) {
			if ((DxfPairs::int($vertex, 70) & 16) === 0) {
				// Not a spline frame's control point.
				$vertices[] = [DxfPairs::number($vertex, 10), DxfPairs::number($vertex, 20), DxfPairs::number($vertex, 42)];
			}
		}
		$this->geometry->polyline($layer, $transform, $vertices, ($flags & 1) !== 0, $block);
	}

	/** @param list<Pair> $pairs */
	private function insert(DxfLayer $layer, DxfTransform $transform, array $pairs, int $depth): void
	{
		$layer->inserts++;
		$name = $this->text->decode(DxfPairs::value($pairs, 2) ?? '');
		$block = $this->blocks[strtoupper($name)] ?? null;
		if ($block === null || $depth >= self::MaxDepth) {
			return;
		}
		$columns = max(1, DxfPairs::int($pairs, 70, 1));
		$rows = max(1, DxfPairs::int($pairs, 71, 1));
		if ($columns * $rows > self::MaxArray) {
			throw new DxfException(sprintf('An array of block "%s" has more than %d copies.', $name, self::MaxArray));
		}
		$base = new DxfTransform(e: -$block['x'], f: -$block['y']);
		for ($row = 0; $row < $rows; $row++) {
			for ($column = 0; $column < $columns; $column++) {
				$inner = $transform->after(self::placing($pairs, $column, $row))->after($base);
				$this->geometry->expand(count($block['entities']), self::MaxExpanded);
				foreach ($block['entities'] as $entity) {
					$this->emit($entity, $inner, $layer->name, $name, $depth + 1);
				}
			}
		}
	}

	/**
	 * Where an insert puts its block's base point: scaled, turned and moved, a copy of an array along its turned rows
	 * and columns.
	 *
	 * @param list<Pair> $pairs
	 */
	private static function placing(array $pairs, int $column, int $row): DxfTransform
	{
		$rotation = DxfPairs::number($pairs, 50);
		$dx = $column * DxfPairs::number($pairs, 44);
		$dy = $row * DxfPairs::number($pairs, 45);
		$cos = cos(deg2rad($rotation));
		$sin = sin(deg2rad($rotation));

		return DxfTransform::placing(
			DxfPairs::number($pairs, 10) + $cos * $dx - $sin * $dy,
			DxfPairs::number($pairs, 20) + $sin * $dx + $cos * $dy,
			DxfPairs::number($pairs, 41, 1.0),
			DxfPairs::number($pairs, 42, 1.0),
			$rotation,
		);
	}

	/**
	 * Gathers an old-style POLYLINE's VERTEX records into it and drops the attributes after an INSERT.
	 *
	 * @param list<Entity> $records
	 * @return list<Entity>
	 */
	public static function grouped(array $records): array
	{
		$grouped = [];
		$count = count($records);
		for ($i = 0; $i < $count; $i++) {
			$record = $records[$i];
			if ($record['type'] === 'POLYLINE') {
				$record['vertices'] = [];
				while ($i + 1 < $count && $records[$i + 1]['type'] === 'VERTEX') {
					$i++;
					$record['vertices'][] = $records[$i]['pairs'];
				}
			}
			if ($record['type'] !== 'ATTRIB' && $record['type'] !== 'SEQEND' && $record['type'] !== 'VERTEX') {
				$grouped[] = $record;
			}
		}

		return $grouped;
	}
}
