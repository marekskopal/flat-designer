<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Service\Furniture\CutList\FramedFront;
use Izbik\Service\Furniture\ResolvedPart;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FramedFront::class)]
final class FramedFrontTest extends TestCase
{
	private const array Names = ['stile' => 'Front: stile', 'rail' => 'Front: rail', 'panel' => 'Front: panel', 'glass' => 'Front: glass'];

	public function testCutsAGlassFrontAsItsFrameAndPane(): void
	{
		$pieces = FramedFront::pieces(self::front(['style' => 'glass']), false, self::Names);

		self::assertSame(
			[
				['Front: stile', 'board', 40, 700, 19, 'fronts'],
				['Front: stile', 'board', 40, 700, 19, 'fronts'],
				['Front: rail', 'board', 516, 40, 19, 'fronts'],
				['Front: rail', 'board', 516, 40, 19, 'fronts'],
				['Front: glass', 'glass', 516, 620, 4, null],
			],
			array_map(static fn (ResolvedPart $p): array => [$p->name, $p->type, $p->w, $p->h, $p->d, $p->material], $pieces),
		);
	}

	public function testCutsAShakerFrontWholeUnlessItsFrameHasAMaterialOfItsOwn(): void
	{
		$front = self::front(['style' => 'shaker', 'frame' => 60, 'frameMaterial' => 'frames']);
		self::assertSame([$front], FramedFront::pieces($front, false, self::Names));

		$pieces = FramedFront::pieces($front, true, self::Names);
		self::assertSame(
			['frames', 'frames', 'frames', 'frames', 'fronts'],
			array_map(static fn (ResolvedPart $p): ?string => $p->material, $pieces),
		);
		self::assertSame([476, 580, 13], [$pieces[4]->w, $pieces[4]->h, $pieces[4]->d]);
	}

	public function testLeavesAFoldingFrontToItsTwoPanels(): void
	{
		$front = self::front(['style' => 'glass', 'opens' => 'fold']);
		self::assertSame([$front], FramedFront::cut($front, [], [], static fn (string $piece): string => $piece));
	}

	public function testHoldsTheFrameToLeaveTheMiddleAndKeepsFlatFrontsWhole(): void
	{
		self::assertSame(40, FramedFront::width('shaker', null, 100, 700));
		self::assertSame(70, FramedFront::width('shaker', 0, 596, 700));
		self::assertSame(0, FramedFront::width('flat', null, 596, 700));
		$flat = self::front([]);
		self::assertSame([$flat], FramedFront::pieces($flat, true, self::Names));
	}

	/** @param array<string, mixed> $props */
	private static function front(array $props): ResolvedPart
	{
		return new ResolvedPart('Front', 'front', 0, 0, 0, 560, 596, 700, 19, 'fronts', $props, 'parts[0]');
	}
}
