<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Drawing;

use Izbik\Dto\EditResultDto;
use Izbik\Model\Entity\Enum\ElementTypeEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\WallRepository;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Editor\Geometry\RoomDetectErrorEnum;
use Izbik\Service\Editor\Geometry\RoomDetector;
use Izbik\Service\Editor\Geometry\WallLine;
use Izbik\Service\Editor\SceneEditor;

/** Rooms outlined by the walls around a point (`RoomDetector`). The caller has checked access to the floor's project. */
final readonly class RoomOutliner
{
	public function __construct(private WallRepository $wallRepository, private SceneEditor $sceneEditor)
	{
	}

	/**
	 * The inner outline of the walls around (x, y); 422 when the point is on a wall or nothing closes around it.
	 *
	 * @return list<array{int, int}>
	 */
	public function outline(Floor $floor, int $x, int $y): array
	{
		$walls = array_map(WallLine::fromWall(...), $this->wallRepository->findByFloor($floor->id));
		$outline = RoomDetector::detect($walls, $x, $y);
		if ($outline instanceof RoomDetectErrorEnum) {
			throw EditException::invalid($outline->message());
		}

		return $outline;
	}

	/**
	 * A new room with the outline around (x, y), as one change like any other room created.
	 *
	 * @param array<string, mixed> $fields other room fields (name, code, type, …)
	 */
	public function createRoom(User $user, Floor $floor, int $x, int $y, array $fields, ?int $baseRevision = null): EditResultDto
	{
		return $this->sceneEditor->create(
			$user,
			$floor,
			ElementTypeEnum::Room,
			['polygon' => $this->outline($floor, $x, $y)] + $fields,
			$baseRevision,
		);
	}
}
