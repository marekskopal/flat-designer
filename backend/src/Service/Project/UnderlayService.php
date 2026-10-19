<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\UnderlayInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FloorUnderlay;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Model\Repository\FloorUnderlayRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;

/**
 * The plan image under a floor's top view, for tracing. Setting or removing it bumps the project's revision (checked
 * against the caller's `If-Match` like an edit), so open viewers show it, but it is not a logged element change and
 * has no undo.
 */
final readonly class UnderlayService
{
	/** Images the viewer can draw as a texture. */
	public const array ImageMimeTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

	/** mm per pixel of a new underlay that is neither placed nor calibrated: a 2000 px scan covers 20 m. */
	private const float DefaultScale = 10.0;
	private const float MinScale = 0.01;
	private const float MaxScale = 1000.0;
	private const float MinOpacity = 0.05;
	private const int MaxCoordinate = 1000000;

	public function __construct(
		private FloorUnderlayRepository $underlayRepository,
		private ProjectFileProvider $projectFileProvider,
		private ProjectRevisionService $revisionService,
	) {
	}

	public function find(Floor $floor): ?FloorUnderlay
	{
		return $this->underlayRepository->findByFloor($floor->id);
	}

	/**
	 * Sets the floor's underlay; fields left out keep their value (or take the defaults on a new underlay).
	 * `$baseRevision` is the revision the caller saw; a stale one is refused (409) like any other edit.
	 */
	public function set(Floor $floor, UnderlayInputDto $input, ?int $baseRevision = null): FloorUnderlay
	{
		$file = $this->projectFileProvider->find($floor->project, $input->fileId)
			?? throw new ProjectException(sprintf('File %d is not a file of this project.', $input->fileId));
		if (!in_array($file->mimeType, self::ImageMimeTypes, true)) {
			throw new ProjectException(sprintf(
				'File "%s" is %s; an underlay must be an image (PNG, JPEG, WebP or GIF).',
				$file->filename,
				$file->mimeType,
			));
		}

		$underlay = $this->find($floor);
		[$width, $height] = $underlay !== null && $underlay->file->id === $file->id
			? [$underlay->widthPx, $underlay->heightPx]
			: $this->imageSize($file);

		if ($input->points !== null) {
			['x' => $x, 'y' => $y, 'scale' => $scale, 'rotation' => $rotation] = self::placementFromPoints($input->points);
		} else {
			$scale = $input->scale ?? $underlay->scale ?? self::DefaultScale;
			$rotation = $input->rotation ?? $underlay->rotation ?? 0.0;
			// A new image starts with its bottom-left corner on the plan origin.
			$x = $input->x ?? $underlay->x ?? 0;
			$y = $input->y ?? $underlay->y ?? (int) round($height * $scale);
		}
		$opacity = $input->opacity ?? $underlay->opacity ?? 0.5;

		if ($scale < self::MinScale || $scale > self::MaxScale) {
			throw new ProjectException(sprintf('Scale must be between %s and %s mm per pixel.', self::MinScale, self::MaxScale));
		}
		if ($opacity < self::MinOpacity || $opacity > 1.0) {
			throw new ProjectException(sprintf('Opacity must be between %s and 1.', self::MinOpacity));
		}
		if (abs($x) > self::MaxCoordinate || abs($y) > self::MaxCoordinate) {
			throw new ProjectException('The underlay lies too far from the plan origin.');
		}

		// As precise as the database gives it back.
		$scale = round($scale, 9);
		$now = new DateTimeImmutable();
		if ($underlay === null) {
			$underlay = new FloorUnderlay(
				floor: $floor,
				file: $file,
				widthPx: $width,
				heightPx: $height,
				x: $x,
				y: $y,
				scale: $scale,
				rotation: self::normalizeAngle($rotation),
				opacity: $opacity,
			);
			$underlay->createdAt = $now;
		} else {
			$underlay->file = $file;
			$underlay->widthPx = $width;
			$underlay->heightPx = $height;
			$underlay->x = $x;
			$underlay->y = $y;
			$underlay->scale = $scale;
			$underlay->rotation = self::normalizeAngle($rotation);
			$underlay->opacity = $opacity;
		}
		$underlay->updatedAt = $now;
		$this->underlayRepository->persist($underlay);
		$this->revisionService->bump($floor->project, $baseRevision);

		return $underlay;
	}

	/** Removes the floor's underlay; false when it had none. */
	public function remove(Floor $floor, ?int $baseRevision = null): bool
	{
		$underlay = $this->find($floor);
		if ($underlay === null) {
			return false;
		}

		$this->underlayRepository->delete($underlay);
		$this->revisionService->bump($floor->project, $baseRevision);

		return true;
	}

	/**
	 * The placement that puts two image pixels onto two plan points: scale, rotation and the plan position of the
	 * top-left corner.
	 *
	 * @param list<array{px: float, py: float, x: float, y: float}> $points
	 * @return array{x: int, y: int, scale: float, rotation: float}
	 */
	public static function placementFromPoints(array $points): array
	{
		if (count($points) !== 2) {
			throw new ProjectException('Calibration needs exactly two points.');
		}
		[$a, $b] = $points;
		// Image y grows downwards, plan y upwards.
		$pixelDx = $b['px'] - $a['px'];
		$pixelDy = -($b['py'] - $a['py']);
		$planDx = $b['x'] - $a['x'];
		$planDy = $b['y'] - $a['y'];
		$pixelLength = hypot($pixelDx, $pixelDy);
		$planLength = hypot($planDx, $planDy);
		if ($pixelLength < 1.0 || $planLength < 1.0) {
			throw new ProjectException('The two calibration points must lie apart, in the image and in the plan.');
		}

		$scale = $planLength / $pixelLength;
		$angle = atan2($planDy, $planDx) - atan2($pixelDy, $pixelDx);
		$cos = cos($angle);
		$sin = sin($angle);
		$ax = $a['px'] * $scale;
		$ay = -$a['py'] * $scale;

		return [
			'x' => (int) round($a['x'] - ($ax * $cos - $ay * $sin)),
			'y' => (int) round($a['y'] - ($ax * $sin + $ay * $cos)),
			'scale' => $scale,
			'rotation' => self::normalizeAngle(rad2deg($angle)),
		];
	}

	/** @return array{int, int} */
	private function imageSize(ProjectFile $file): array
	{
		$size = getimagesizefromstring($this->projectFileProvider->readContent($file));
		if ($size === false || $size[0] < 1 || $size[1] < 1) {
			throw new ProjectException(sprintf('File "%s" could not be read as an image.', $file->filename));
		}

		return [$size[0], $size[1]];
	}

	private static function normalizeAngle(float $degrees): float
	{
		$degrees = fmod($degrees, 360.0);
		if ($degrees < 0) {
			$degrees += 360.0;
		}

		return round($degrees, 6) >= 360.0 ? 0.0 : round($degrees, 6);
	}
}
