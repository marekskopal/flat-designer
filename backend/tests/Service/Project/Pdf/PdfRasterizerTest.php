<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Project\Pdf;

use Izbik\Dto\PdfPageDto;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\Pdf\PdfDocument;
use Izbik\Service\Project\Pdf\PdfPage;
use Izbik\Service\Project\Pdf\PdfRasterizer;
use Izbik\Tests\Support\Pdf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function array_fill;
use function array_map;
use function getimagesizefromstring;

#[CoversClass(PdfRasterizer::class)]
#[CoversClass(PdfPage::class)]
#[CoversClass(PdfDocument::class)]
#[CoversClass(PdfPageDto::class)]
final class PdfRasterizerTest extends TestCase
{
	public function testThePagesAsShownTurnedByTheirRotation(): void
	{
		$document = (new PdfRasterizer())->document(Pdf::plans());

		self::assertSame(2, $document->pageCount);
		self::assertSame(
			[[1, 595.0, 842.0], [2, 1191.0, 842.0]],
			array_map(static fn (PdfPage $page): array => [$page->number, $page->widthPt, $page->heightPt], $document->pages),
		);
	}

	public function testAPageRendersAt150DpiAsPredicted(): void
	{
		$rasterizer = new PdfRasterizer();
		foreach ($rasterizer->document(Pdf::plans())->pages as $page) {
			$dpi = PdfRasterizer::dpi($page);
			self::assertSame(150.0, $dpi);

			$size = getimagesizefromstring($rasterizer->render(Pdf::plans(), $page->number, $dpi));
			self::assertNotFalse($size);
			self::assertSame('image/png', $size['mime']);
			self::assertSame([$page->widthPx($dpi), $page->heightPx($dpi)], [$size[0], $size[1]]);
		}
	}

	public function testALargePageIsCappedAtTheLongestSideAndThePixelCount(): void
	{
		// A0 at 150 dpi would be 4967 × 7022 px: its longer side is held to 6000.
		$a0 = new PdfPage(1, 2384, 3370);
		$dpi = PdfRasterizer::dpi($a0);
		self::assertLessThanOrEqual(PdfRasterizer::MaxSide, $a0->heightPx($dpi));
		self::assertGreaterThan(PdfRasterizer::MaxSide - 5, $a0->heightPx($dpi));
		self::assertLessThanOrEqual(PdfRasterizer::MaxPixels, $a0->widthPx($dpi) * $a0->heightPx($dpi));

		// A square metre: the pixel count, not the side, holds it.
		$square = new PdfPage(1, 2835, 2835);
		$dpi = PdfRasterizer::dpi($square);
		self::assertLessThanOrEqual(PdfRasterizer::MaxPixels, $square->widthPx($dpi) * $square->heightPx($dpi));
		self::assertLessThan(PdfRasterizer::MaxSide, $square->widthPx($dpi));

		$rendered = (new PdfRasterizer())->render(Pdf::document([['width' => 2384, 'height' => 3370]]), 1, PdfRasterizer::dpi($a0));
		$size = getimagesizefromstring($rendered);
		self::assertNotFalse($size);
		self::assertSame([$a0->widthPx(PdfRasterizer::dpi($a0)), $a0->heightPx(PdfRasterizer::dpi($a0))], [$size[0], $size[1]]);
	}

	public function testAThumbnailHasTheGivenLongerSide(): void
	{
		$size = getimagesizefromstring((new PdfRasterizer())->renderToSide(Pdf::plans(), 2, 240));

		self::assertNotFalse($size);
		self::assertSame(240, $size[0]);
		self::assertLessThan(240, $size[1]);
	}

	public function testPagesBeyondTheLimitAreCountedButNotRead(): void
	{
		$document = (new PdfRasterizer())->document(
			Pdf::document(array_fill(0, PdfRasterizer::MaxPages + 2, ['width' => 100.0, 'height' => 100.0])),
		);

		self::assertSame(PdfRasterizer::MaxPages + 2, $document->pageCount);
		self::assertCount(PdfRasterizer::MaxPages, $document->pages);
		self::assertSame(PdfRasterizer::MaxPages, $document->page(PdfRasterizer::MaxPages)->number);
		self::assertRefused(
			'Only the first 500 pages of a PDF can be used; page 501 is beyond them (the PDF has 502).',
			static fn (): PdfPage => $document->page(501),
		);
		self::assertRefused('The PDF has no page 503; its pages are 1–502.', static fn (): PdfPage => $document->page(503));
	}

	public function testAnEmptyPageIsListedButCannotBeRendered(): void
	{
		$document = new PdfDocument(2, [new PdfPage(1, 595, 842), new PdfPage(2, 0, 0)]);

		self::assertSame(1240, PdfPageDto::fromPage($document->pages[0])->widthPx);
		self::assertNull(PdfPageDto::fromPage($document->pages[1])->widthPx);
		self::assertSame(1, $document->page(1)->number);
		self::assertRefused('Page 2 of the PDF is empty.', static fn (): PdfPage => $document->page(2));
	}

	public function testABrokenFileIsRefused(): void
	{
		try {
			(new PdfRasterizer())->document("%PDF-1.4\nnot really\n");
			self::fail('A broken PDF was read.');
		} catch (ProjectException $e) {
			self::assertStringStartsWith('The PDF could not be read', $e->getMessage());
		}
	}

	/** @param callable(): mixed $call */
	private static function assertRefused(string $message, callable $call): void
	{
		try {
			$call();
			self::fail('Not refused: ' . $message);
		} catch (ProjectException $e) {
			self::assertSame($message, $e->getMessage());
		}
	}
}
