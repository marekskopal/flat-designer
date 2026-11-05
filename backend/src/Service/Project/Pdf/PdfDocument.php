<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Pdf;

use Izbik\Service\Project\Exception\ProjectException;

/** What `pdfinfo` tells of a PDF: its page count, and the first `PdfRasterizer::MaxPages` pages. */
final readonly class PdfDocument
{
	/** @param list<PdfPage> $pages */
	public function __construct(public int $pageCount, public array $pages)
	{
	}

	/** The page numbered `$number` (from 1), one that can be rendered. */
	public function page(int $number): PdfPage
	{
		foreach ($this->pages as $page) {
			if ($page->number === $number) {
				if ($page->isEmpty()) {
					throw new ProjectException(sprintf('Page %d of the PDF is empty.', $number));
				}

				return $page;
			}
		}
		if ($number >= 1 && $number <= $this->pageCount) {
			throw new ProjectException(sprintf(
				'Only the first %d pages of a PDF can be used; page %d is beyond them (the PDF has %d).',
				PdfRasterizer::MaxPages,
				$number,
				$this->pageCount,
			));
		}

		throw new ProjectException(sprintf('The PDF has no page %d; its pages are 1–%d.', $number, $this->pageCount));
	}
}
