<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Quantities;

use Izbik\Dto\MaterialDto;
use Izbik\Model\Entity\Enum\MaterialPatternEnum;
use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use Izbik\Service\Quantities\Costing;
use Izbik\Service\Quantities\PriceList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Costing::class)]
final class CostingTest extends TestCase
{
	/** @return iterable<string, array{MaterialPatternEnum, int, int, int|null}> */
	public static function patterns(): iterable
	{
		// 12 m² of floor with 10 % waste: 13.2 m² in whole pieces, or the area itself for a surface not laid in pieces.
		yield 'chevron planks 600 × 90' => [MaterialPatternEnum::Chevron, 600, 90, 245];
		yield 'marble tiles 1200 × 600' => [MaterialPatternEnum::Marble, 1200, 600, 19];
		yield 'zellige 100 × 100' => [MaterialPatternEnum::Zellige, 100, 100, 1320];
		yield 'encaustic 200 × 200' => [MaterialPatternEnum::Encaustic, 200, 200, 330];
		yield 'fluted panel' => [MaterialPatternEnum::Fluted, 30, 30, null];
	}

	#[DataProvider('patterns')]
	public function testCountsTheKitchenPatternsInPieces(MaterialPatternEnum $pattern, int $width, int $height, ?int $pieces): void
	{
		$material = new MaterialDto(
			id: 7,
			code: 'test',
			name: 'Test',
			pattern: $pattern,
			color: '#cccccc',
			roughness: 0.5,
			metalness: 0.0,
			texture: null,
			tileWidth: $width,
			tileHeight: $height,
		);
		$costing = new Costing(PriceList::of([]), [7 => $material], 0.1);

		$line = $costing->line(['surface' => QuantitySurfaceEnum::Floor, 'materialId' => 7, 'amount' => 12000000.0]);

		self::assertSame($pieces, $line->pieces);
	}
}
