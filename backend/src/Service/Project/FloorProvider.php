<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;

/** Floors of a project; a project always keeps at least one. */
final readonly class FloorProvider
{
	private const int MaxNameLength = 255;
	private const int MinLevel = -10;
	private const int MaxLevel = 100;

	public function __construct(private FloorRepository $floorRepository, private ProjectRevisionService $revisionService)
	{
	}

	public function create(Project $project, FloorInputDto $input): Floor
	{
		$floor = new Floor(project: $project, name: self::name($input->name), level: self::level($input->level));
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
}
