<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use const JSON_THROW_ON_ERROR;

/** Minimal glTF binaries for tests: a JSON chunk only, enough for the bounds the app reads. */
final readonly class Glb
{
	/**
	 * One mesh whose positions span `$min` … `$max` (metres) under a node with the given transform fields.
	 *
	 * @param array{float, float, float} $min
	 * @param array{float, float, float} $max
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $extra top-level fields (e.g. extensionsRequired)
	 */
	public static function box(array $min, array $max, array $node = [], array $extra = []): string
	{
		return self::pack([
			'asset' => ['version' => '2.0'],
			'scene' => 0,
			'scenes' => [['nodes' => [0]]],
			'nodes' => [['mesh' => 0, ...$node]],
			'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
			'accessors' => [['componentType' => 5126, 'count' => 8, 'type' => 'VEC3', 'min' => $min, 'max' => $max]],
			...$extra,
		]);
	}

	/** @param array<string, mixed> $json */
	public static function pack(array $json, int $version = 2): string
	{
		$chunk = json_encode($json, JSON_THROW_ON_ERROR);
		$chunk .= str_repeat(' ', (4 - strlen($chunk) % 4) % 4);

		return pack('a4VV', 'glTF', $version, 12 + 8 + strlen($chunk)) . pack('Va4', strlen($chunk), 'JSON') . $chunk;
	}
}
