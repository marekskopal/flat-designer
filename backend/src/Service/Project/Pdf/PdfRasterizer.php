<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Pdf;

use Izbik\Service\Project\Exception\ProjectException;

/**
 * PDF pages as PNG images, through poppler's `pdfinfo` and `pdftoppm` (in the backend image), so a plan that comes as
 * a PDF can lie under a floor and be read by agents. They run as separate processes (argv, no shell) on a temporary
 * copy of the file, with a time limit; poppler reads PDF only (no PostScript interpreter).
 *
 * A page is rendered at `dpi()`: 150 dpi, less for a page so large that its image would be longer than `MaxSide` or
 * hold more than `MaxPixels` (what `ImagePreviewService` still decodes). The underlay image and an agent's preview of
 * the page use it alike, so pixel positions read off the preview are those of the image laid.
 */
final readonly class PdfRasterizer
{
	public const float Dpi = 150.0;
	public const int MaxSide = 6000;
	public const int MaxPixels = 28000000;
	public const int MaxPages = 500;

	private const int TimeoutSeconds = 30;

	/** The page count and the first `MaxPages` pages. */
	public function document(string $bytes): PdfDocument
	{
		$output = $this->withTempFile($bytes, fn (string $path): string => $this->run(
			['pdfinfo', '-box', '-f', '1', '-l', (string) self::MaxPages, $path],
			'The PDF could not be read; it may be damaged or protected by a password.',
		));

		$pages = [];
		$rotations = [];
		$pageCount = 0;
		foreach (explode("\n", $output) as $line) {
			if (preg_match('/^Pages:\s+(\d+)/', $line, $match) === 1) {
				$pageCount = (int) $match[1];
			} elseif (preg_match('/^Page\s+(\d+) CropBox:\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)/', $line, $match) === 1) {
				$pages[(int) $match[1]] = [abs((float) $match[4] - (float) $match[2]), abs((float) $match[5] - (float) $match[3])];
			} elseif (preg_match('/^Page\s+(\d+) rot:\s+(-?\d+)/', $line, $match) === 1) {
				$rotations[(int) $match[1]] = (int) $match[2];
			}
		}
		if ($pages === []) {
			throw new ProjectException('The PDF has no pages.');
		}
		ksort($pages);

		$result = [];
		foreach ($pages as $number => [$width, $height]) {
			// pdftoppm renders the page turned by its rotation.
			$turned = abs(($rotations[$number] ?? 0) % 180) === 90;
			$result[] = new PdfPage($number, $turned ? $height : $width, $turned ? $width : $height);
		}

		return new PdfDocument(max($pageCount, count($result)), $result);
	}

	/** The resolution a page is rendered at for an underlay or a preview. */
	public static function dpi(PdfPage $page): float
	{
		$dpi = self::Dpi;
		$longest = max($page->widthPt, $page->heightPt) / 72.0;
		$area = $page->widthPt * $page->heightPt / 5184.0;
		if ($page->isEmpty()) {
			throw new ProjectException(sprintf('Page %d of the PDF is empty.', $page->number));
		}
		$dpi = min($dpi, (self::MaxSide - 1) / $longest, sqrt(self::MaxPixels / $area) * 0.999);

		return max(1.0, $dpi);
	}

	/** One page (numbered from 1) as PNG bytes, at `$dpi`. */
	public function render(string $bytes, int $page, float $dpi): string
	{
		return $this->renderPage($bytes, $page, ['-r', sprintf('%.4F', $dpi)]);
	}

	/** One page as PNG bytes with its longer side `$side` pixels long (a thumbnail), without reading the page sizes first. */
	public function renderToSide(string $bytes, int $page, int $side): string
	{
		return $this->renderPage($bytes, $page, ['-scale-to', (string) $side]);
	}

	/** @param list<string> $size the size options of pdftoppm */
	private function renderPage(string $bytes, int $page, array $size): string
	{
		return $this->withTempFile($bytes, function (string $path) use ($page, $size): string {
			$out = $path . '-page';

			try {
				$this->run(
					[
						'pdftoppm',
						'-png',
						'-singlefile',
						'-cropbox',
						'-f',
						(string) $page,
						'-l',
						(string) $page,
						...$size,
						$path,
						$out,
					],
					sprintf('Page %d of the PDF could not be rendered.', $page),
				);
				$png = @file_get_contents($out . '.png');
				if ($png === false || $png === '') {
					throw new ProjectException(sprintf('Page %d of the PDF could not be rendered.', $page));
				}

				return $png;
			} finally {
				@unlink($out . '.png');
			}
		});
	}

	/**
	 * @param callable(string): T $use
	 * @return T
	 * @template T
	 */
	private function withTempFile(string $bytes, callable $use): mixed
	{
		$path = tempnam(sys_get_temp_dir(), 'izbik-pdf-');
		if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
			throw new ProjectException('The PDF could not be written to a temporary file.', 500);
		}

		try {
			return $use($path);
		} finally {
			@unlink($path);
		}
	}

	/**
	 * Runs a command and gives its standard output; a failure, or a run longer than `TimeoutSeconds`, is `$failure`.
	 *
	 * @param non-empty-list<string> $command
	 */
	private function run(array $command, string $failure): string
	{
		$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if ($process === false) {
			throw new ProjectException(sprintf('%s could not be started; is poppler-utils installed?', $command[0]), 500);
		}
		[, $stdout, $stderr] = $pipes;
		stream_set_blocking($stdout, false);
		stream_set_blocking($stderr, false);

		$output = '';
		$errors = '';
		$deadline = microtime(true) + self::TimeoutSeconds;
		while (true) {
			$output .= (string) stream_get_contents($stdout);
			$errors .= (string) stream_get_contents($stderr);
			if (feof($stdout) && feof($stderr)) {
				break;
			}
			if (microtime(true) > $deadline) {
				proc_terminate($process, 9);
				fclose($stdout);
				fclose($stderr);
				proc_close($process);

				throw new ProjectException(sprintf('%s The PDF took too long.', $failure));
			}
			$read = [$stdout, $stderr];
			$write = null;
			$except = null;
			@stream_select($read, $write, $except, 0, 200000);
		}
		fclose($stdout);
		fclose($stderr);
		$exitCode = proc_close($process);

		if ($exitCode !== 0) {
			throw new ProjectException(trim($failure . ' ' . self::lastLine($errors)));
		}

		return $output;
	}

	/** The last line poppler wrote to stderr (the reason), without its own noise before it. */
	private static function lastLine(string $errors): string
	{
		$lines = array_values(array_filter(array_map(trim(...), explode("\n", $errors)), static fn (string $line): bool => $line !== ''));

		return $lines === [] ? '' : '(' . mb_substr($lines[count($lines) - 1], 0, 200) . ')';
	}
}
