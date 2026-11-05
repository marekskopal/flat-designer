<?php

declare(strict_types=1);

namespace Izbik\Tests\Support;

use function count;
use function implode;
use function sprintf;
use function strlen;

/** Writes small PDF documents for the tests: pages with a size in points, an optional rotation and drawing operators. */
final class Pdf
{
	/** @param list<array{width: float, height: float, rotate?: int, content?: string}> $pages */
	public static function document(array $pages): string
	{
		$objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
		$kids = [];
		$number = 3;
		$pageObjects = [];
		foreach ($pages as $page) {
			$content = $page['content'] ?? '2 w 20 20 m 100 100 l S';
			$rotate = isset($page['rotate']) ? sprintf(' /Rotate %d', $page['rotate']) : '';
			$pageObjects[$number] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s]%s /Contents %d 0 R >>',
				$page['width'],
				$page['height'],
				$rotate,
				$number + 1,
			);
			$pageObjects[$number + 1] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content);
			$kids[] = sprintf('%d 0 R', $number);
			$number += 2;
		}
		$objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
		$objects += $pageObjects;

		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $id => $object) {
			$offsets[] = strlen($pdf);
			$pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
		}
		$xref = strlen($pdf);
		$pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
	}

	/** An A4 portrait page and an A3 page stored portrait but turned to landscape. */
	public static function plans(): string
	{
		return self::document([
			['width' => 595, 'height' => 842],
			['width' => 842, 'height' => 1191, 'rotate' => 90],
		]);
	}
}
