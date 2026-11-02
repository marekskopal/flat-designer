<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\BoardDirectionEnum;
use Izbik\Model\Entity\Enum\DoorFrameStyleEnum;
use Izbik\Model\Entity\Enum\DoorHandleEnum;
use Izbik\Model\Entity\Enum\DoorLeafStyleEnum;
use Izbik\Model\Entity\Enum\GarageDoorStyleEnum;
use Izbik\Model\Entity\Enum\OpeningHingeEnum;
use Izbik\Model\Entity\Enum\OpeningSideEnum;
use Izbik\Model\Entity\Enum\OpeningTypeEnum;
use Izbik\Model\Entity\Enum\ShutterStyleEnum;
use Izbik\Model\Entity\Opening;

final readonly class OpeningDto
{
	public function __construct(
		public int $id,
		public string $name,
		public OpeningTypeEnum $type,
		public int $offset,
		public int $width,
		public int $height,
		public int $sillHeight,
		public ?int $leafWidth,
		public ?OpeningHingeEnum $hinge,
		public ?OpeningSideEnum $side,
		public bool $entrance,
		public bool $walkable,
		public DoorFrameStyleEnum $frameStyle,
		public ?DoorHandleEnum $handle,
		public int $mullions,
		public int $transoms,
		public ?int $sillBoard,
		public DoorLeafStyleEnum $leafStyle,
		public int $leafPanels,
		public ?int $panelHeight,
		public ?ShutterStyleEnum $shutters,
		public ?int $shutterMaterialId,
		public bool $shuttersClosed,
		public ?int $depth,
		public bool $revealLining = false,
		public ?int $frameMaterialId = null,
		public ?int $outsideFrameMaterialId = null,
		public ?int $leafMaterialId = null,
		public BoardDirectionEnum $boardDirection = BoardDirectionEnum::Vertical,
		public ?GarageDoorStyleEnum $garageDoorStyle = null,
		public int $fixedPanes = 0,
		public ?int $inset = null,
	) {
	}

	public static function fromEntity(Opening $opening): self
	{
		return new self(
			id: $opening->id,
			name: $opening->name,
			type: $opening->type,
			offset: $opening->offset,
			width: $opening->width,
			height: $opening->height,
			sillHeight: $opening->sillHeight,
			leafWidth: $opening->leafWidth,
			hinge: $opening->hinge,
			side: $opening->side,
			entrance: $opening->entrance,
			walkable: $opening->walkable,
			frameStyle: $opening->frameStyle,
			handle: $opening->handle,
			mullions: $opening->mullions,
			transoms: $opening->transoms,
			sillBoard: $opening->sillBoard,
			leafStyle: $opening->leafStyle,
			leafPanels: $opening->leafPanels,
			panelHeight: $opening->panelHeight,
			shutters: $opening->shutters,
			shutterMaterialId: $opening->shutterMaterial?->id,
			shuttersClosed: $opening->shuttersClosed,
			depth: $opening->depth,
			revealLining: $opening->revealLining,
			frameMaterialId: $opening->frameMaterial?->id,
			outsideFrameMaterialId: $opening->outsideFrameMaterial?->id,
			leafMaterialId: $opening->leafMaterial?->id,
			boardDirection: $opening->boardDirection,
			garageDoorStyle: $opening->garageDoorStyle,
			fixedPanes: $opening->fixedPanes,
			inset: $opening->inset,
		);
	}
}
