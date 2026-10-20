<?php

declare(strict_types=1);

namespace FlatDesigner\Service\ModelAsset;

/**
 * The size of a glTF scene: the bounding box of its default scene from the `min` / `max` that every `POSITION` accessor
 * carries, through the node transforms. glTF lengths are metres (x across, y up, z towards the front); the box comes back
 * in mm.
 */
final readonly class GltfBounds
{
	private const int MaxDepth = 32;

	/**
	 * @param array<string, mixed> $gltf
	 * @return array{width: int, depth: int, height: int}
	 */
	public static function size(array $gltf): array
	{
		$scenes = self::list($gltf, 'scenes');
		$scene = $scenes[is_int($gltf['scene'] ?? null) ? $gltf['scene'] : 0] ?? null;
		$roots = is_array($scene) ? self::list($scene, 'nodes') : array_keys(self::list($gltf, 'nodes'));

		$box = null;
		foreach ($roots as $root) {
			if (is_int($root)) {
				$box = self::nodeBox($gltf, $root, Matrix4::identity(), $box, 0);
			}
		}
		if ($box === null) {
			throw new ModelAssetException('The model has no mesh with positions to draw.');
		}

		return [
			'width' => max(1, (int) round(($box[3] - $box[0]) * 1000)),
			'depth' => max(1, (int) round(($box[5] - $box[2]) * 1000)),
			'height' => max(1, (int) round(($box[4] - $box[1]) * 1000)),
		];
	}

	/**
	 * The box (min x, y, z, max x, y, z) grown by the node's mesh and its children.
	 *
	 * @param array<string, mixed> $gltf
	 * @param list<float> $parent
	 * @param list<float>|null $box
	 * @return list<float>|null
	 */
	private static function nodeBox(array $gltf, int $index, array $parent, ?array $box, int $depth): ?array
	{
		$node = self::list($gltf, 'nodes')[$index] ?? null;
		if (!is_array($node) || $depth > self::MaxDepth) {
			return $box;
		}
		$matrix = Matrix4::multiply($parent, Matrix4::ofNode($node));
		if (is_int($node['mesh'] ?? null)) {
			foreach (self::meshCorners($gltf, $node['mesh']) as $corner) {
				$box = self::grow($box, Matrix4::apply($matrix, $corner));
			}
		}
		foreach (self::list($node, 'children') as $child) {
			if (is_int($child)) {
				$box = self::nodeBox($gltf, $child, $matrix, $box, $depth + 1);
			}
		}

		return $box;
	}

	/**
	 * The corners of the position bounds of every primitive of the mesh.
	 *
	 * @param array<string, mixed> $gltf
	 * @return list<array{float, float, float}>
	 */
	private static function meshCorners(array $gltf, int $meshIndex): array
	{
		$mesh = self::list($gltf, 'meshes')[$meshIndex] ?? null;
		$corners = [];
		foreach (is_array($mesh) ? self::list($mesh, 'primitives') : [] as $primitive) {
			$bounds = self::positionBounds($gltf, $primitive);
			if ($bounds === null) {
				continue;
			}
			[$min, $max] = $bounds;
			foreach ([$min[0], $max[0]] as $x) {
				foreach ([$min[1], $max[1]] as $y) {
					foreach ([$min[2], $max[2]] as $z) {
						$corners[] = [$x, $y, $z];
					}
				}
			}
		}

		return $corners;
	}

	/**
	 * @param array<string, mixed> $gltf
	 * @return array{array{float, float, float}, array{float, float, float}}|null
	 */
	private static function positionBounds(array $gltf, mixed $primitive): ?array
	{
		$attributes = is_array($primitive) && is_array($primitive['attributes'] ?? null) ? $primitive['attributes'] : [];
		$index = $attributes['POSITION'] ?? null;
		$accessor = is_int($index) ? (self::list($gltf, 'accessors')[$index] ?? null) : null;
		$min = is_array($accessor) ? Matrix4::vector($accessor['min'] ?? null) : null;
		$max = is_array($accessor) ? Matrix4::vector($accessor['max'] ?? null) : null;

		return $min === null || $max === null ? null : [$min, $max];
	}

	/**
	 * @param list<float>|null $box
	 * @param array{float, float, float} $point
	 * @return list<float>
	 */
	private static function grow(?array $box, array $point): array
	{
		if ($box === null) {
			return [...$point, ...$point];
		}

		return [
			min($box[0], $point[0]),
			min($box[1], $point[1]),
			min($box[2], $point[2]),
			max($box[3], $point[0]),
			max($box[4], $point[1]),
			max($box[5], $point[2]),
		];
	}

	/**
	 * @param array<mixed> $object
	 * @return list<mixed>
	 */
	private static function list(array $object, string $key): array
	{
		$value = $object[$key] ?? null;

		return is_array($value) && array_is_list($value) ? $value : [];
	}
}
