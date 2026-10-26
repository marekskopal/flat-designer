<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;
use MarekSkopal\ORM\Database\DatabaseInterface;

/** Floors of a project; a project always keeps at least one. */
final readonly class FloorProvider
{
	private const int MaxNameLength = 255;
	private const int MinLevel = -10;
	private const int MaxLevel = 100;

	/** mm; room for the default stacking up to `MaxLevel`. */
	private const int MaxElevation = 500000;
	private const int MaxSlabThickness = 1000;

	public function __construct(
		private FloorRepository $floorRepository,
		private DatabaseInterface $database,
		private ProjectRevisionService $revisionService,
	) {
	}

	/** A missing elevation stacks the floor on the one below (or under the one above), a missing slab is the default. */
	public function create(Project $project, FloorInputDto $input): Floor
	{
		$level = self::level($input->level);
		$slab = self::slabThickness($input->slabThickness ?? FloorStack::defaultSlab($level));
		$floor = new Floor(
			project: $project,
			name: self::name($input->name),
			level: $level,
			elevation: self::elevation($input->elevation ?? FloorStack::elevation($level, $slab, $this->stacked($project))),
			slabThickness: $slab,
		);
		$floor->createdAt = new DateTimeImmutable();
		$floor->updatedAt = $floor->createdAt;
		$this->floorRepository->persist($floor);
		$this->revisionService->bump($project);

		return $floor;
	}

	public function update(Floor $floor, FloorInputDto $input): Floor
	{
		$floor->name = self::name($input->name);
		$floor->level = self::level($input->level);
		$floor->elevation = self::elevation($input->elevation ?? $floor->elevation);
		$floor->slabThickness = self::slabThickness($input->slabThickness ?? $floor->slabThickness);
		$floor->updatedAt = new DateTimeImmutable();
		$this->floorRepository->persist($floor);
		$this->revisionService->bump($floor->project);

		return $floor;
	}

	/**
	 * Deletes the floor with its rooms, walls and items (ON DELETE CASCADE).
	 */
	public function delete(Floor $floor): void
	{
		if (count($this->floorRepository->findByProject($floor->project->id)) <= 1) {
			throw new ProjectException('A project needs at least one floor.', 409);
		}

		$this->floorRepository->delete($floor);
		$this->revisionService->bump($floor->project);
	}

	/**
	 * The project's floors as `FloorStack` sees them.
	 *
	 * @return list<array{level: int, elevation: int, slabThickness: int, tallestWall: int}>
	 */
	private function stacked(Project $project): array
	{
		/** @var array<int, int> $tallest the tallest wall per floor id, of the floors with walls */
		$tallest = [];
		$rows = $this->database->execute(
			'SELECT floor_id, MAX(height) AS tallest FROM walls WHERE deleted_at IS NULL AND floor_id IN '
			. '(SELECT id FROM floors WHERE project_id = ?) GROUP BY floor_id',
			[$project->id],
			cached: false,
		)->fetchAll();
		foreach ($rows as $row) {
			if (is_array($row) && is_numeric($row['floor_id'] ?? null) && is_numeric($row['tallest'] ?? null)) {
				$tallest[(int) $row['floor_id']] = (int) $row['tallest'];
			}
		}

		return array_map(
			fn (Floor $floor): array => [
				'level' => $floor->level,
				'elevation' => $floor->elevation,
				'slabThickness' => $floor->slabThickness,
				'tallestWall' => $tallest[$floor->id] ?? FloorStack::DefaultWallHeight,
			],
			$this->floorRepository->findByProject($project->id),
		);
	}

	private static function name(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MaxNameLength) {
			throw new ProjectException('Floor name is required (at most 255 characters).');
		}

		return $name;
	}

	private static function level(int $level): int
	{
		if ($level < self::MinLevel || $level > self::MaxLevel) {
			throw new ProjectException(sprintf('Level must be between %d and %d.', self::MinLevel, self::MaxLevel));
		}

		return $level;
	}

	private static function elevation(int $elevation): int
	{
		if (abs($elevation) > self::MaxElevation) {
			throw new ProjectException(sprintf('Elevation must be between %d and %d mm.', -self::MaxElevation, self::MaxElevation));
		}

		return $elevation;
	}

	private static function slabThickness(int $thickness): int
	{
		if ($thickness < 0 || $thickness > self::MaxSlabThickness) {
			throw new ProjectException(sprintf('Slab thickness must be between 0 and %d mm.', self::MaxSlabThickness));
		}

		return $thickness;
	}
}
