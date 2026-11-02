<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture\CutList;

use Izbik\Service\Furniture\CutList\FoldingFront;
use Izbik\Service\Furniture\ResolvedPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FoldingFront::class)]
final class FoldingFrontTest extends TestCase
{
	public function testAFoldingFrontIsCutAsTwoPanelsWithAJointBetween(): void
	{
		$panels = FoldingFront::panels(self::front(['opens' => 'fold']));

		self::assertSame([[100, 349], [451, 349]], array_map(static fn (ResolvedPart $p): array => [$p->y, $p->h], $panels));
	}

	public function testTheFoldIsAtMostHalfWay(): void
	{
		self::assertSame(
			[449, 249],
			array_map(static fn (ResolvedPart $p): int => $p->h, FoldingFront::panels(self::front(['opens' => 'fold', 'foldAt' => 250]))),
		);
		self::assertSame(
			[349, 349],
			array_map(static fn (ResolvedPart $p): int => $p->h, FoldingFront::panels(self::front(['opens' => 'fold', 'foldAt' => 600]))),
		);
	}

	public function testOtherPartsAndFrontsTooLowToFoldStayWhole(): void
	{
		$front = self::front(['opens' => 'lift']);
		self::assertSame([$front], FoldingFront::panels($front));

		$low = new ResolvedPart('Flap', 'front', null, 0, 100, 300, 600, 5, 19, null, ['opens' => 'fold']);
		self::assertSame([$low], FoldingFront::panels($low));
	}

	/** @param array<string, mixed> $props */
	private static function front(array $props): ResolvedPart
	{
		return new ResolvedPart('Flap', 'front', null, 0, 100, 300, 600, 700, 19, null, $props);
	}
}
