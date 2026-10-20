<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use FlatDesigner\Service\Project\Exception\ProjectException;
use GdImage;

/**
 * Images of project files as an agent gets them over MCP: optionally cropped to a region, scaled down to fit
 * `maxSize` and overlaid with a labelled grid of source pixel coordinates, so positions can be read off a plan.
 */
final readonly class ImagePreviewService
{
	public const int DefaultMaxSize = 1568;
	public const int MinMaxSize = 64;
	public const int MaxMaxSize = 4096;

	/** Grid lines closer than this many output pixels would overlap their labels (a five-digit label is 30 px wide). */
	private const int MinGridSpacing = 36;

	/** gd decodes the whole source into a 4-byte-per-pixel bitmap; above this the worker's memory_limit would be hit. */
	private const int MaxSourcePixels = 30000000;

	/**
	 * @param array{x: int, y: int, width: int, height: int}|null $crop source pixels; null is the whole image
	 * @return array{data: string, mimeType: string, widthPx: int, heightPx: int, crop: array{x: int, y: int, width: int, height: int}, scale: float}
	 *     `widthPx` / `heightPx` are the source image's, `scale` is output pixels per source pixel
	 */
	public function render(string $bytes, string $mimeType, ?array $crop, int $maxSize, ?int $grid): array
	{
		[$width, $height] = ProjectFileProvider::imageSize($bytes, 'The file');
		if ($maxSize < self::MinMaxSize || $maxSize > self::MaxMaxSize) {
			throw new ProjectException(sprintf('maxSize must be between %d and %d.', self::MinMaxSize, self::MaxMaxSize));
		}

		$crop ??= ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height];
		$crop = self::clampCrop($crop, $width, $height);
		$scale = min(1.0, $maxSize / max($crop['width'], $crop['height']));
		$whole = $crop === ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height];

		if ($whole && $scale === 1.0 && $grid === null && in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
			return ['data' => $bytes, 'mimeType' => $mimeType, 'widthPx' => $width, 'heightPx' => $height, 'crop' => $crop, 'scale' => 1.0];
		}
		if (!extension_loaded('gd')) {
			throw new ProjectException('Cropping and scaling images needs the PHP extension gd.', 500);
		}
		if ($width * $height > self::MaxSourcePixels) {
			throw new ProjectException(sprintf(
				'The image is %d × %d px, too large to crop or scale here (at most %d megapixels); upload a smaller copy.',
				$width,
				$height,
				intdiv(self::MaxSourcePixels, 1000000),
			));
		}

		$image = self::resample($bytes, $crop, $scale);
		if ($grid !== null) {
			self::drawGrid($image, $crop, $scale, $grid);
		}
		$photo = $mimeType === 'image/jpeg' && $grid === null;
		$data = self::encode($image, $photo);

		return [
			'data' => $data,
			'mimeType' => $photo ? 'image/jpeg' : 'image/png',
			'widthPx' => $width,
			'heightPx' => $height,
			'crop' => $crop,
			'scale' => $scale,
		];
	}

	/** @param array{x: int, y: int, width: int, height: int} $crop */
	private static function resample(string $bytes, array $crop, float $scale): GdImage
	{
		$source = imagecreatefromstring($bytes);
		$outWidth = max(1, (int) round($crop['width'] * $scale));
		$outHeight = max(1, (int) round($crop['height'] * $scale));
		$image = imagecreatetruecolor($outWidth, $outHeight);
		if ($source === false || $image === false) {
			throw new ProjectException('The file could not be read as an image.');
		}
		imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
		imagecopyresampled($image, $source, 0, 0, $crop['x'], $crop['y'], $outWidth, $outHeight, $crop['width'], $crop['height']);

		return $image;
	}

	/** JPEG for photos, PNG for anything with lines (plans, the grid). */
	private static function encode(GdImage $image, bool $photo): string
	{
		ob_start();
		if ($photo) {
			imagejpeg($image, null, 85);
		} else {
			imagepng($image, null, 6);
		}

		return (string) ob_get_clean();
	}

	/**
	 * @param array{x: int, y: int, width: int, height: int} $crop
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	private static function clampCrop(array $crop, int $width, int $height): array
	{
		// A crop reaching past an edge is cut at it; one lying entirely outside is refused.
		$x = max(0, $crop['x']);
		$y = max(0, $crop['y']);
		$right = min($width, $crop['x'] + $crop['width']);
		$bottom = min($height, $crop['y'] + $crop['height']);
		if ($right - $x < 1 || $bottom - $y < 1) {
			throw new ProjectException(sprintf('The crop lies outside the image (%d × %d px).', $width, $height));
		}

		return ['x' => $x, 'y' => $y, 'width' => $right - $x, 'height' => $bottom - $y];
	}

	/**
	 * Lines every `grid` source pixels, labelled with their source coordinate along the top and left edges; every
	 * fifth line is stronger.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $crop
	 */
	private static function drawGrid(GdImage $image, array $crop, float $scale, int $grid): void
	{
		if ($grid < 1 || $grid * $scale < self::MinGridSpacing) {
			throw new ProjectException(sprintf(
				'A grid of %d px would draw its lines less than %d output pixels apart; use a larger grid or a smaller crop.',
				$grid,
				self::MinGridSpacing,
			));
		}
		$light = (int) imagecolorallocatealpha($image, 230, 40, 40, 80);
		$strong = (int) imagecolorallocatealpha($image, 230, 40, 40, 30);
		$label = (int) imagecolorallocate($image, 190, 0, 0);
		$labelBackground = (int) imagecolorallocatealpha($image, 255, 255, 255, 30);
		$outWidth = imagesx($image);
		$outHeight = imagesy($image);

		for ($value = (int) ceil($crop['x'] / $grid) * $grid; $value <= $crop['x'] + $crop['width']; $value += $grid) {
			$x = (int) round(($value - $crop['x']) * $scale);
			imageline($image, $x, 0, $x, $outHeight - 1, intdiv($value, $grid) % 5 === 0 ? $strong : $light);
			self::label($image, (string) $value, $x + 2, 1, $label, $labelBackground);
		}
		for ($value = (int) ceil($crop['y'] / $grid) * $grid; $value <= $crop['y'] + $crop['height']; $value += $grid) {
			$y = (int) round(($value - $crop['y']) * $scale);
			imageline($image, 0, $y, $outWidth - 1, $y, intdiv($value, $grid) % 5 === 0 ? $strong : $light);
			self::label($image, (string) $value, 1, $y + 2, $label, $labelBackground);
		}
	}

	private static function label(GdImage $image, string $text, int $x, int $y, int $color, int $background): void
	{
		$font = 2;
		imagefilledrectangle($image, $x, $y, $x + strlen($text) * imagefontwidth($font), $y + imagefontheight($font), $background);
		imagestring($image, $font, $x, $y, $text, $color);
	}
}
