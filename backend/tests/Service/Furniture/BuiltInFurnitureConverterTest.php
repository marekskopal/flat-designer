<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Furniture;

use FlatDesigner\Service\Furniture\BuiltInFurnitureConverter;
use FlatDesigner\Service\Furniture\DefinitionEvaluator;
use FlatDesigner\Service\Furniture\FurnitureSeeds;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every distinct built-in furniture item of flat 50 converts to values its definition takes at the item's size, and back. */
#[CoversClass(BuiltInFurnitureConverter::class)]
final class BuiltInFurnitureConverterTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../fixtures/flat50-builtin-furniture.json';

	/** @return iterable<string, array{string, int, int, int, array<mixed>}> */
	public static function items(): iterable
	{
		$items = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture))['items'] ?? [];
		self::assertIsArray($items);
		foreach ($items as $index => $item) {
			self::assertIsArray($item);
			self::assertIsString($item['kind']);
			self::assertIsInt($item['width']);
			self::assertIsInt($item['depth']);
			self::assertIsInt($item['height']);
			self::assertIsArray($item['params']);

			yield sprintf(
				'#%s %s %d',
				$index,
				$item['kind'],
				$item['width'],
			) => [$item['kind'], $item['width'], $item['depth'], $item['height'], $item['params']];
		}
	}

	/** @param array<mixed> $params */
	#[DataProvider('items')]
	public function testConvertsToValuesTheDefinitionTakes(string $kind, int $width, int $depth, int $height, array $params): void
	{
		$target = BuiltInFurnitureConverter::toDefinition($kind, $params, $width, $height);
		self::assertNotNull($target);
		[$name, $values, $materials] = $target;

		$parts = new DefinitionEvaluator()->evaluate(self::definition($name), $width, $depth, $height, $values);
		self::assertGreaterThan(0, count($parts));

		// Back to the built-in params and forward again gives the same values (nothing is lost on the way).
		$back = BuiltInFurnitureConverter::toParams($kind, $values, $materials);
		self::assertSame(
			[$values, $materials],
			array_slice(BuiltInFurnitureConverter::toDefinition($kind, $back, $width, $height) ?? [], 1),
		);
		self::assertTrue(BuiltInFurnitureConverter::carriesBack($kind, $values, $materials));
	}

	public function testBuiltInDefaultsAreWrittenOut(): void
	{
		self::assertSame(
			[
				'Kitchen base cabinet',
				['plinth' => 0, 'fronts' => [['type' => 'door']], 'handle' => 'none', 'handleSide' => 'right', 'blindLeft' => 0, 'blindRight' => 0],
				[],
			],
			BuiltInFurnitureConverter::toDefinition('BaseCabinet', [], 600, 860),
		);
		self::assertSame(
			['Sliding wardrobe', ['doors' => [[], [], [], []], 'plinth' => 60], ['profile' => 7]],
			BuiltInFurnitureConverter::toDefinition('Wardrobe', ['profileMaterialId' => 7], 3600, 2400),
		);
		self::assertSame(['Panel', [], []], BuiltInFurnitureConverter::toDefinition('Box', [], 1200, 1400));
		self::assertNull(BuiltInFurnitureConverter::toDefinition('Sink', [], 780, 320));
	}

	public function testChairKeepsTheBuiltInClamps(): void
	{
		// A back wider than the front drew as wide as the front; a seat height that does not fit drew at 460.
		$target = BuiltInFurnitureConverter::toDefinition('Chair', ['backWidth' => 500, 'seatHeight' => 900], 400, 885);
		self::assertSame(['seatHeight' => 460, 'backWidth' => 400], $target[1] ?? null);
	}

	public function testDefinitionOnlyStateDoesNotCarryBack(): void
	{
		self::assertFalse(BuiltInFurnitureConverter::carriesBack('BaseCabinet', ['plinth' => 100, 'gap' => 2], []));
		self::assertFalse(BuiltInFurnitureConverter::carriesBack('BaseCabinet', ['plinth' => 100], ['fronts' => 3]));
		self::assertTrue(BuiltInFurnitureConverter::carriesBack('Wardrobe', ['plinth' => 60], ['profile' => 3]));
	}

	/** @return array<mixed> */
	private static function definition(string $name): array
	{
		foreach (FurnitureSeeds::files() as $file) {
			$seed = JsonDecoder::decodeObject((string) file_get_contents(FurnitureSeeds::Directory . '/' . $file));
			if (($seed['name'] ?? null) === $name && is_array($seed['definition'] ?? null)) {
				return $seed['definition'];
			}
		}
		self::fail(sprintf('No seed named "%s".', $name));
	}
}
