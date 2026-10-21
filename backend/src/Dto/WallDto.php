<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Service\Json\JsonDecoder;

final readonly class WallDto
{
	/**
	 * @param list<WallFinishDto>|null $leftFinishes bands on the face left of start → end, overriding the room's (null: the room's)
	 * @param list<WallFinishDto>|null $rightFinishes the same on the right face
	 * @param list<OpeningDto> $openings
	 */
	public function __construct(
		public int $id,
		public string $name,
		public WallTypeEnum $type,
		public int $startX,
		public int $startY,
		public int $endX,
		public int $endY,
		public int $thickness,
		public int $height,
		public ?int $materialId,
		public ?array $leftFinishes,
		public ?array $rightFinishes,
		public array $openings,
	) {
	}

	/** @param list<OpeningDto> $openings */
	public static function fromEntity(Wall $wall, array $openings): self
	{
		return new self(
			id: $wall->id,
			name: $wall->name,
			type: $wall->type,
			startX: $wall->startX,
			startY: $wall->startY,
			endX: $wall->endX,
			endY: $wall->endY,
			thickness: $wall->thickness,
			height: $wall->height,
			materialId: $wall->material?->id,
			leftFinishes: self::finishes($wall->leftFinishes),
			rightFinishes: self::finishes($wall->rightFinishes),
			openings: $openings,
		);
	}

	/**
	 * The bands on one face when they override the room's, otherwise null.
	 *
	 * @return list<WallFinishDto>|null
	 */
	public function finishesOn(OpeningSideEnum $side): ?array
	{
		return $side === OpeningSideEnum::Left ? $this->leftFinishes : $this->rightFinishes;
	}

	/** @return list<WallFinishDto>|null */
	private static function finishes(?string $json): ?array
	{
		return $json === null ? null : array_map(
			fn (array $band): WallFinishDto => new WallFinishDto($band['materialId'], $band['bottom'], $band['top']),
			JsonDecoder::decodeWallFinishes($json),
		);
	}
}
