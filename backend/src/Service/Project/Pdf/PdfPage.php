<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Pdf;

/** A page of a PDF as a viewer shows it: its crop box in points (1/72 in), turned by the page's rotation. */
final readonly class PdfPage
{
	public function __construct(public int $number, public float $widthPt, public float $heightPt)
	{
	}

	/** A page with no area (a zero crop box), which cannot be rendered. */
	public function isEmpty(): bool
	{
		return $this->widthPt <= 0.0 || $this->heightPt <= 0.0;
	}

	/** Its pixel size rendered at `$dpi`, as pdftoppm rounds it. */
	public function widthPx(float $dpi): int
	{
		return (int) ceil($this->widthPt * $dpi / 72.0);
	}

	public function heightPx(float $dpi): int
	{
		return (int) ceil($this->heightPt * $dpi / 72.0);
	}
}
