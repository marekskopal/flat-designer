<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Project\Pdf\PdfPage;
use Izbik\Service\Project\Pdf\PdfRasterizer;

/**
 * A page of a PDF file: its size in points as shown, and the pixel size of the image an underlay of it gets (null for
 * an empty page, which cannot be laid).
 */
final readonly class PdfPageDto
{
	public function __construct(
		public int $page,
		public float $widthPt,
		public float $heightPt,
		public ?int $widthPx,
		public ?int $heightPx,
	) {
	}

	public static function fromPage(PdfPage $page): self
	{
		$dpi = $page->isEmpty() ? null : PdfRasterizer::dpi($page);

		return new self(
			page: $page->number,
			widthPt: round($page->widthPt, 2),
			heightPt: round($page->heightPt, 2),
			widthPx: $dpi === null ? null : $page->widthPx($dpi),
			heightPx: $dpi === null ? null : $page->heightPx($dpi),
		);
	}
}
