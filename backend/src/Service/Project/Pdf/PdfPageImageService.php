<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Pdf;

use Izbik\Model\Entity\ProjectFile;
use Izbik\Model\Entity\User;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\ProjectFileProvider;

/**
 * The pages of a PDF project file: their sizes, a thumbnail, a page as an image (for an agent to read) and a page made
 * into a PNG project file, which is what an underlay lies on (the stored underlay stays an image).
 */
final readonly class PdfPageImageService
{
	public const string MimeType = 'application/pdf';
	public const int MinThumbnailSize = 32;
	public const int MaxThumbnailSize = 1024;

	public function __construct(private PdfRasterizer $rasterizer, private ProjectFileProvider $projectFileProvider)
	{
	}

	public static function isPdf(ProjectFile $file): bool
	{
		return $file->mimeType === self::MimeType;
	}

	public function document(ProjectFile $file): PdfDocument
	{
		return $this->rasterizer->document($this->content($file));
	}

	/**
	 * A page rendered as an underlay would be (`PdfRasterizer::dpi()`). `$check` gets the page and the image's pixel
	 * size before the (slow) rendering, to refuse arguments that do not fit it.
	 *
	 * @param (callable(PdfPage, int, int): void)|null $check
	 * @return array{png: string, page: PdfPage, pages: int}
	 */
	public function render(ProjectFile $file, int $page, ?callable $check = null): array
	{
		$bytes = $this->content($file);
		$document = $this->rasterizer->document($bytes);
		$pdfPage = $document->page($page);
		$dpi = PdfRasterizer::dpi($pdfPage);
		if ($check !== null) {
			$check($pdfPage, $pdfPage->widthPx($dpi), $pdfPage->heightPx($dpi));
		}

		return [
			'png' => $this->rasterizer->render($bytes, $page, $dpi),
			'page' => $pdfPage,
			'pages' => $document->pageCount,
		];
	}

	/** A page with its longer side `$size` pixels long; one run of pdftoppm, no page sizes read first. */
	public function thumbnail(ProjectFile $file, int $page, int $size): string
	{
		if ($size < self::MinThumbnailSize || $size > self::MaxThumbnailSize) {
			throw new ProjectException(sprintf('The size must be between %d and %d.', self::MinThumbnailSize, self::MaxThumbnailSize));
		}
		if ($page < 1 || $page > PdfRasterizer::MaxPages) {
			throw new ProjectException(sprintf('The page must be between 1 and %d.', PdfRasterizer::MaxPages));
		}

		return $this->rasterizer->renderToSide($this->content($file), $page, $size);
	}

	/**
	 * A page as a new PNG file of the PDF's project, named after the PDF and the page ("plan – page 2.png"). It is
	 * made here, not uploaded, so the upload size limit does not apply to it (a detailed large page may exceed it).
	 */
	public function toImageFile(?User $author, ProjectFile $file, int $page): ProjectFile
	{
		$png = $this->render($file, $page)['png'];
		$name = preg_replace('/\.pdf$/i', '', $file->filename) ?? $file->filename;

		return $this->projectFileProvider->upload(
			$author,
			$file->project,
			sprintf('%s – page %d.png', $name, $page),
			$png,
			checkSize: false,
		);
	}

	private function content(ProjectFile $file): string
	{
		if (!self::isPdf($file)) {
			throw new ProjectException(sprintf('File "%s" is %s, not a PDF.', $file->filename, $file->mimeType));
		}

		return $this->projectFileProvider->readContent($file);
	}
}
