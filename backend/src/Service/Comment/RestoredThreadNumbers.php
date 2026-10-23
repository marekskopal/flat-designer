<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Comment;

use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Service\Project\FloorMatcher;

/**
 * The thread numbers of the restored floors during a version restore (`CommentKeeper`). A restored floor continues
 * after the highest numbering of the floors it stands for. A thread keeps its number unless another one already took
 * it on the floor (two floors meeting on one), and then gets the next after the floor's numbering.
 *
 * @phpstan-import-type FloorNumbering from CommentNumbering
 */
final class RestoredThreadNumbers
{
	/** @var array<int, int> the last thread number given on each restored floor, by its id */
	private array $last = [];

	/** @var array<int, array<int, true>> the numbers given on each restored floor, by its id */
	private array $taken = [];

	/**
	 * @param list<FloorNumbering> $kept the numbering of the floors before the restore
	 * @param list<Floor> $floors the restored floors
	 */
	public function __construct(array $kept, private readonly array $floors)
	{
		foreach ($kept as ['level' => $level, 'floorName' => $floorName, 'lastNumber' => $lastNumber]) {
			$floor = FloorMatcher::like($floors, $level, $floorName);
			if ($floor !== null) {
				$this->last[$floor->id] = max($this->last[$floor->id] ?? 0, $lastNumber);
			}
		}
	}

	/** The number of a thread carried over to the floor. */
	public function number(Floor $floor, ?int $old): int
	{
		$this->last[$floor->id] = max($this->last[$floor->id] ?? 0, $old ?? 0);
		$number = $old;
		if ($number === null || isset($this->taken[$floor->id][$number])) {
			$this->last[$floor->id] += 1;
			$number = $this->last[$floor->id];
		}
		$this->taken[$floor->id][$number] = true;

		return $number;
	}

	/** Sets where each restored floor's numbering continues. */
	public function save(CommentNumbering $numbering): void
	{
		foreach ($this->floors as $floor) {
			if (($this->last[$floor->id] ?? 0) > 0) {
				$numbering->continueAfter($floor, $this->last[$floor->id]);
			}
		}
	}
}
