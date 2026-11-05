<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Dxf;

use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfDrawing;
use Izbik\Service\Dxf\DxfException;
use Izbik\Service\Dxf\DxfLayer;
use Izbik\Service\Dxf\DxfReader;
use Izbik\Service\Dxf\DxfSegment;
use Izbik\Service\Dxf\DxfTransform;
use Izbik\Tests\Support\Dxf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function array_map;
use function rad2deg;
use function round;
use function str_replace;

#[CoversClass(DxfReader::class)]
#[CoversClass(DxfDrawing::class)]
#[CoversClass(DxfLayer::class)]
#[CoversClass(DxfSegment::class)]
#[CoversClass(DxfArc::class)]
#[CoversClass(DxfTransform::class)]
final class DxfReaderTest extends TestCase
{
	public function testLinesPolylinesAndTheHeadersUnits(): void
	{
		$drawing = DxfReader::read(Dxf::document([
			Dxf::line('Walls', 0, 0, 3000, 0),
			Dxf::rectangle('Walls', 0, 100, 200, 400),
		], units: 5, layers: ['Walls' => 0, 'Frozen' => 1]));

		self::assertSame(5, $drawing->units);
		self::assertSame(
			[[0.0, 0.0, 3000.0, 0.0], [0.0, 100.0, 200.0, 100.0], [200.0, 100.0, 200.0, 400.0], [200.0, 400.0, 0.0, 400.0], [0.0, 400.0, 0.0, 100.0]],
			self::lines($drawing),
		);
		self::assertSame(5, $drawing->layers['Walls']->lines);
		self::assertTrue($drawing->layers['Frozen']->hidden);
		self::assertFalse($drawing->layers['Walls']->hidden);
	}

	public function testABulgeIsAnArc(): void
	{
		// A half circle counter-clockwise from (0, 0) to (1000, 0): round (500, 0), below the chord.
		$drawing = DxfReader::read(Dxf::document([Dxf::polyline('Walls', [[0, 0, 1.0], [1000, 0]])]));

		self::assertSame([[500.0, 0.0, 500.0, 180.0, 360.0]], self::arcs($drawing));
		self::assertSame([], $drawing->segments);
	}

	public function testAMirroredInsertTurnsItsArcsRound(): void
	{
		$door = [Dxf::line('0', 0, 0, 0, 800), Dxf::arc('0', 0, 0, 800, 0, 90)];
		$drawing = DxfReader::read(Dxf::document(
			[Dxf::insert('Doors', 'DOOR-80', 5000, 1000, -1.0), Dxf::insert('Doors', 'DOOR-80', 0, 0, 1.0, 1.0, 90)],
			['DOOR-80' => $door],
		));

		// Mirrored: the swing now runs from straight up round to the left.
		self::assertSame([[5000.0, 1000.0, 800.0, 90.0, 180.0], [0.0, 0.0, 800.0, 90.0, 180.0]], self::arcs($drawing));
		self::assertSame([[5000.0, 1000.0, 5000.0, 1800.0], [0.0, 0.0, -800.0, 0.0]], self::lines($drawing));
		// Layer 0 in the block takes the insert's layer; the block's name stays with its entities for the opening finder.
		self::assertSame(['Doors', 'Doors'], array_map(static fn (DxfArc $arc): string => $arc->layer, $drawing->arcs));
		self::assertSame('DOOR-80', $drawing->arcs[0]->block);
		self::assertSame(2, $drawing->layers['Doors']->inserts);
	}

	public function testAnUpsideDownObjectCoordinateSystemMirrorsX(): void
	{
		$drawing = DxfReader::read(Dxf::document([Dxf::arc('Doors', 100, 0, 50, 0, 90, [0.0, 0.0, -1.0])]));

		self::assertSame([[-100.0, 0.0, 50.0, 90.0, 180.0]], self::arcs($drawing));
	}

	public function testPaperSpaceAndEntitiesNotReadAreLeftOut(): void
	{
		$paper = Dxf::line('Sheet', 0, 0, 100, 0);
		$paper[] = [67, 1];
		$drawing = DxfReader::read(Dxf::document([$paper, Dxf::text('Rooms', 0, 0, 'Kitchen')]));

		self::assertSame([], $drawing->segments);
		self::assertSame(1, $drawing->layers['Rooms']->other);
	}

	public function testWindowsLineEndingsAndEscapedNames(): void
	{
		$body = Dxf::document([Dxf::line('Zdi\U+0161', 0, 0, 10, 0)], newline: "\r\n");
		$drawing = DxfReader::read($body);

		self::assertSame(['Zdiš'], array_map(static fn (DxfLayer $layer): string => $layer->name, array_values($drawing->layers)));
		self::assertSame([[0.0, 0.0, 10.0, 0.0]], self::lines($drawing));
	}

	public function testNamesInTheDrawingsCodepage(): void
	{
		$body = str_replace('$ACADVER', "\$DWGCODEPAGE\n  3\nANSI_1250\n  9\n\$ACADVER", Dxf::document([Dxf::line("Zd\xEC", 0, 0, 10, 0)]));

		self::assertArrayHasKey('Zdě', DxfReader::read($body)->layers);
	}

	public function testAByteOrderMarkIsSkipped(): void
	{
		$drawing = DxfReader::read("\xEF\xBB\xBF" . Dxf::document([Dxf::line('Walls', 0, 0, 10, 0)]));

		self::assertSame([[0.0, 0.0, 10.0, 0.0]], self::lines($drawing));
	}

	public function testNestedArraysOfBlocksCannotExpandWithoutEnd(): void
	{
		// Each level a 100 × 100 array of the one below: 10^8 copies of a block of nothing at the third level.
		$array = static fn (string $block): array => [
			[0, 'INSERT'],
			[8, '0'],
			[2, $block],
			[10, 0],
			[20, 0],
			[30, 0],
			[70, 100],
			[71, 100],
			[44, 10],
			[45, 10],
		];
		$blocks = ['EMPTY' => [Dxf::text('0', 0, 0, 'x')], 'LEVEL1' => [$array('EMPTY')], 'LEVEL2' => [$array('LEVEL1')]];

		try {
			DxfReader::read(Dxf::document([$array('LEVEL2')], $blocks));
			self::fail('Expanded the arrays');
		} catch (DxfException $e) {
			self::assertStringContainsString('expand into more than', $e->getMessage());
		}
	}

	public function testDwgAndBinaryDxfAreRefused(): void
	{
		foreach (['AC1032' . "\0\0" => 'DWG', "AutoCAD Binary DXF\r\n\x1A\0" => 'binary DXF', "hello\nworld\n" => 'not an ASCII DXF'] as $body => $message) {
			try {
				DxfReader::read($body);
				self::fail('Read ' . $message);
			} catch (DxfException $e) {
				self::assertStringContainsString($message, $e->getMessage());
			}
		}
	}

	public function testAFileWithoutEntitiesIsNoDrawing(): void
	{
		try {
			DxfReader::read("  0\nSECTION\n  2\nHEADER\n  0\nENDSEC\n  0\nEOF\n");
			self::fail('Read a file without entities');
		} catch (DxfException $e) {
			self::assertStringContainsString('no ENTITIES', $e->getMessage());
		}
	}

	/** @return list<array{float, float, float, float}> */
	private static function lines(DxfDrawing $drawing): array
	{
		return array_map(
			static fn (DxfSegment $segment): array => [
				round($segment->x1, 6) + 0.0,
				round($segment->y1, 6) + 0.0,
				round(
					$segment->x2,
					6,
				) + 0.0, round(
					$segment->y2,
					6,
				) + 0.0],
			$drawing->segments,
		);
	}

	/** @return list<array{float, float, float, float, float}> centre, radius and angles in degrees */
	private static function arcs(DxfDrawing $drawing): array
	{
		return array_map(static function (DxfArc $arc): array {
			$start = rad2deg($arc->start);
			$end = rad2deg($arc->end);
			while ($start < 0) {
				$start += 360;
				$end += 360;
			}

			return [round($arc->cx, 6) + 0.0, round($arc->cy, 6) + 0.0, round($arc->radius, 6), round($start, 6), round($end, 6)];
		}, $drawing->arcs);
	}
}
