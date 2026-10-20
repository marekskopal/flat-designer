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

	/** The largest value of each integer component type, which a normalized accessor maps to 1. */
	private const array NormalizedMax = [5120 => 127, 5121 => 255, 5122 => 32767, 5123 => 65535];

	/**
	 * @param array<string, mixed> $gltf
	 * @return array{width: int, depth: int, height: int}
	 */
	public static function size(array $gltf): array
	{
		$scenes = self::list($gltf, 'scenes');
		$scene = $scenes[is_int($gltf['scene'] ?? null) ? $gltf['scene'] : 0] ?? null;
		if (!is_array($scene)) {
			// The viewer draws the default scene; nodes outside any scene are not drawn.
			throw new ModelAssetException('The model has no scene to draw.');
		}

		$box = null;
		foreach (self::list($scene, 'nodes') as $root) {
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
			// Bits 0, 1 and 2 of the corner number pick the max of x, y and z.
			for ($corner = 0; $corner < 8; $corner++) {
				$corners[] = [$bounds[$corner & 1][0], $bounds[$corner >> 1 & 1][1], $bounds[$corner >> 2 & 1][2]];
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
		if ($min === null || $max === null) {
			return null;
		}
		// Quantized models (KHR_mesh_quantization) keep normalized integers; their node scale expects them in -1..1.
		$type = $accessor['componentType'] ?? null;
		$scale = ($accessor['normalized'] ?? false) === true && is_int($type) ? (self::NormalizedMax[$type] ?? null) : null;

		return $scale === null ? [$min, $max] : [self::normalized($min, $scale), self::normalized($max, $scale)];
	}

	/**
	 * @param array{float, float, float} $vector
	 * @return array{float, float, float}
	 */
	private static function normalized(array $vector, int $scale): array
	{
		// Signed types reach one value further down than up, which still reads as -1.
		return [max($vector[0] / $scale, -1.0), max($vector[1] / $scale, -1.0), max($vector[2] / $scale, -1.0)];
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
