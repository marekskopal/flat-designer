<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\ModelAsset;

use FlatDesigner\Service\ModelAsset\GlbReader;
use FlatDesigner\Service\ModelAsset\GltfBounds;
use FlatDesigner\Service\ModelAsset\Matrix4;
use FlatDesigner\Service\ModelAsset\ModelAssetException;
use FlatDesigner\Tests\Support\Glb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlbReader::class)]
#[CoversClass(GltfBounds::class)]
#[CoversClass(Matrix4::class)]
final class GlbReaderTest extends TestCase
{
	public function testSizeIsTheBoundingBoxInMm(): void
	{
		$bytes = Glb::box([-0.25, 0.0, -0.1], [0.25, 0.8, 0.1]);

		self::assertSame(['width' => 500, 'depth' => 200, 'height' => 800], GlbReader::size($bytes));
	}

	public function testNodeTransformsApply(): void
	{
		// Scaled twice along x, then turned a quarter about y: the 1000 mm width becomes the depth.
		$quarter = sqrt(0.5);
		$bytes = Glb::box([-0.25, 0.0, -0.1], [0.25, 0.8, 0.1], ['scale' => [2, 1, 1], 'rotation' => [0, $quarter, 0, $quarter]]);

		self::assertSame(['width' => 200, 'depth' => 1000, 'height' => 800], GlbReader::size($bytes));

		// A matrix scaling everything by 1000: a model authored in mm.
		$matrix = [1000, 0, 0, 0, 0, 1000, 0, 0, 0, 0, 1000, 0, 0, 0, 0, 1];
		$inMm = Glb::box([0.0, 0.0, 0.0], [0.0006, 0.0004, 0.0003], ['matrix' => $matrix]);
		self::assertSame(['width' => 600, 'depth' => 300, 'height' => 400], GlbReader::size($inMm));
	}

	public function testQuantizedPositionsAreNormalized(): void
	{
		// As `gltf-transform optimize` writes it: int16 positions in -1..1, the node scale and offset restore metres.
		$bytes = Glb::pack([
			'asset' => ['version' => '2.0'],
			'extensionsUsed' => ['KHR_mesh_quantization'],
			'extensionsRequired' => ['KHR_mesh_quantization'],
			'scenes' => [['nodes' => [0]]],
			'nodes' => [['mesh' => 0, 'scale' => [0.25, 0.4, 0.1], 'translation' => [0, 0.4, 0]]],
			'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
			'accessors' => [
				[
					'componentType' => 5122,
					'normalized' => true,
					'count' => 8,
					'type' => 'VEC3',
					'min' => [-32767, -32768, -32767],
					'max' => [32767, 32767, 32767],
				]],
		]);

		self::assertSame(['width' => 500, 'depth' => 200, 'height' => 800], GlbReader::size($bytes));
	}

	public function testRejectsWhatTheViewerCannotDraw(): void
	{
		$cases = [
			'not glTF' => "PK\x03\x04 a zip, not a model",
			'glTF 1' => Glb::pack(['asset' => ['version' => '1.0']], 1),
			'truncated' => substr(Glb::box([0.0, 0.0, 0.0], [1.0, 1.0, 1.0]), 0, 40),
			'draco' => Glb::box([0.0, 0.0, 0.0], [1.0, 1.0, 1.0], [], ['extensionsRequired' => ['KHR_draco_mesh_compression']]),
			'no mesh' => Glb::pack(['asset' => ['version' => '2.0'], 'scenes' => [['nodes' => []]]]),
			'no scene' => Glb::pack([
				'asset' => ['version' => '2.0'],
				'nodes' => [['mesh' => 0]],
				'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0]]]]],
				'accessors' => [['componentType' => 5126, 'count' => 8, 'type' => 'VEC3', 'min' => [0, 0, 0], 'max' => [1, 1, 1]]],
			]),
		];
		foreach ($cases as $name => $bytes) {
			try {
				GlbReader::size($bytes);
				self::fail(sprintf('"%s" was accepted.', $name));
			} catch (ModelAssetException $e) {
				self::assertSame(422, $e->getCode(), $name);
			}
		}
	}
}
