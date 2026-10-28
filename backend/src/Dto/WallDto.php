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
	 * @param int $sagitta a curved wall: mm from the chord's midpoint to the centre line's arc, + to the left of start → end (0: straight)
	 * @param list<WallFinishDto>|null $leftFinishes bands on the face left of start → end, overriding the room's (null: the room's)
	 * @param list<WallFinishDto>|null $rightFinishes the same on the right face
	 * @param bool $upToRoof the wall rises to the underside of the roofs over it (a gable) instead of stopping at `height`
	 * @param int|null $heightEnd the top's height at the end (a straight slope from `height`); null: level
	 * @param int $baseStart the bottom at the start, mm against the floor's level
	 * @param int $baseEnd the bottom at the end
	 * @param bool $onGround the bottom follows the plots' ground instead
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
		public int $sagitta,
		public int $thickness,
		public int $height,
		public ?int $materialId,
		public ?array $leftFinishes,
		public ?array $rightFinishes,
		public array $openings,
		public bool $upToRoof = false,
		public ?int $heightEnd = null,
		public int $baseStart = 0,
		public int $baseEnd = 0,
		public bool $onGround = false,
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
			sagitta: $wall->sagitta,
			thickness: $wall->thickness,
			height: $wall->height,
			materialId: $wall->material?->id,
			leftFinishes: self::finishes($wall->leftFinishes),
			rightFinishes: self::finishes($wall->rightFinishes),
			openings: $openings,
			upToRoof: $wall->upToRoof,
			heightEnd: $wall->heightEnd,
			baseStart: $wall->baseStart,
			baseEnd: $wall->baseEnd,
			onGround: $wall->onGround,
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
