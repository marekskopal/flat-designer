<?php

declare(strict_types=1);

namespace FlatDesigner\Service\ModelAsset;

use JsonException;
use const JSON_THROW_ON_ERROR;

/**
 * Reads what the app needs of a glTF binary (`.glb`, glTF 2.0) without loading its meshes: that it is one, that the
 * viewer can draw it (`GltfBounds` gives its size).
 */
final readonly class GlbReader
{
	private const int Magic = 0x46546C67;
	private const int JsonChunk = 0x4E4F534A;

	/** Extensions the viewer cannot decode without extra decoders (wasm) that the app does not ship. */
	private const array Unsupported = ['KHR_draco_mesh_compression', 'KHR_texture_basisu'];

	/** @return array{width: int, depth: int, height: int} the bounding box of the default scene, mm */
	public static function size(string $bytes): array
	{
		$gltf = self::json($bytes);
		$required = is_array($gltf['extensionsRequired'] ?? null) ? array_filter($gltf['extensionsRequired'], is_string(...)) : [];
		$unsupported = array_values(array_intersect($required, self::Unsupported));
		if ($unsupported !== []) {
			throw new ModelAssetException(sprintf(
				'The model needs %s, which the viewer does not decode; export it without that compression.',
				implode(', ', $unsupported),
			));
		}
		if (self::referencesFiles($gltf)) {
			throw new ModelAssetException('The model refers to separate files (a .bin or images); export one self-contained .glb.');
		}

		return GltfBounds::size($gltf);
	}

	/**
	 * Whether a buffer or image lives outside the file (a `uri` that is no data URI), which the viewer cannot fetch.
	 *
	 * @param array<string, mixed> $gltf
	 */
	private static function referencesFiles(array $gltf): bool
	{
		foreach (['buffers', 'images'] as $key) {
			foreach (is_array($gltf[$key] ?? null) ? $gltf[$key] : [] as $entry) {
				$uri = is_array($entry) ? ($entry['uri'] ?? null) : null;
				if (is_string($uri) && !str_starts_with($uri, 'data:')) {
					return true;
				}
			}
		}

		return false;
	}

	/** @return array<string, mixed> the JSON chunk */
	private static function json(string $bytes): array
	{
		$header = strlen($bytes) < 20 ? false : unpack('Vmagic/Vversion/Vlength/VchunkLength/VchunkType', substr($bytes, 0, 20));
		if ($header === false || ($header['magic'] ?? null) !== self::Magic) {
			throw new ModelAssetException('The file is not a glTF binary (.glb); convert a .gltf with its files into one .glb.');
		}
		$version = is_int($header['version'] ?? null) ? $header['version'] : 0;
		if ($version !== 2) {
			throw new ModelAssetException(sprintf('glTF version %d is not supported; export glTF 2.0.', $version));
		}
		$chunkLength = is_int($header['chunkLength'] ?? null) ? $header['chunkLength'] : 0;
		if (
			($header['length'] ?? null) !== strlen($bytes)
			|| ($header['chunkType'] ?? null) !== self::JsonChunk
			|| 20 + $chunkLength > strlen($bytes)
		) {
			throw self::damaged();
		}

		try {
			$json = json_decode(substr($bytes, 20, $chunkLength), true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw self::damaged($e);
		}

		if (!is_array($json)) {
			throw self::damaged();
		}

		$object = [];
		foreach ($json as $key => $value) {
			$object[(string) $key] = $value;
		}

		return $object;
	}

	private static function damaged(?JsonException $previous = null): ModelAssetException
	{
		return new ModelAssetException('The glTF binary is truncated or damaged.', 422, $previous);
	}
}
