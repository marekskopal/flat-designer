<?php

declare(strict_types=1);

namespace Izbik\Service\Editor;

use Izbik\Model\Entity\Enum\ElementTypeEnum;
use Izbik\Service\Editor\Element\ElementHandler;
use Izbik\Service\Editor\Element\FloorVoidHandler;
use Izbik\Service\Editor\Element\GroupHandler;
use Izbik\Service\Editor\Element\ItemHandler;
use Izbik\Service\Editor\Element\OpeningHandler;
use Izbik\Service\Editor\Element\PlotHandler;
use Izbik\Service\Editor\Element\RailingHandler;
use Izbik\Service\Editor\Element\RoofHandler;
use Izbik\Service\Editor\Element\RoofWindowHandler;
use Izbik\Service\Editor\Element\RoomHandler;
use Izbik\Service\Editor\Element\SectionLineHandler;
use Izbik\Service\Editor\Element\StairHandler;
use Izbik\Service\Editor\Element\VariantSetHandler;
use Izbik\Service\Editor\Element\WallHandler;

final readonly class ElementHandlers
{
	public function __construct(
		private ItemHandler $itemHandler,
		private WallHandler $wallHandler,
		private OpeningHandler $openingHandler,
		private RoomHandler $roomHandler,
		private GroupHandler $groupHandler,
		private VariantSetHandler $variantSetHandler,
		private PlotHandler $plotHandler,
		private StairHandler $stairHandler,
		private FloorVoidHandler $voidHandler,
		private RailingHandler $railingHandler,
		private RoofHandler $roofHandler,
		private RoofWindowHandler $roofWindowHandler,
		private SectionLineHandler $sectionLineHandler,
	) {
	}

	public function get(ElementTypeEnum $type): ElementHandler
	{
		return match ($type) {
			ElementTypeEnum::Item => $this->itemHandler,
			ElementTypeEnum::Wall => $this->wallHandler,
			ElementTypeEnum::Opening => $this->openingHandler,
			ElementTypeEnum::Room => $this->roomHandler,
			ElementTypeEnum::Group => $this->groupHandler,
			ElementTypeEnum::VariantSet => $this->variantSetHandler,
			ElementTypeEnum::Plot => $this->plotHandler,
			ElementTypeEnum::Stair => $this->stairHandler,
			ElementTypeEnum::FloorVoid => $this->voidHandler,
			ElementTypeEnum::Railing => $this->railingHandler,
			ElementTypeEnum::Roof => $this->roofHandler,
			ElementTypeEnum::RoofWindow => $this->roofWindowHandler,
			ElementTypeEnum::SectionLine => $this->sectionLineHandler,
		};
	}
}
