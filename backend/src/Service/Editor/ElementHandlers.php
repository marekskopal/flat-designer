<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\Element\ElementHandler;
use FlatDesigner\Service\Editor\Element\GroupHandler;
use FlatDesigner\Service\Editor\Element\ItemHandler;
use FlatDesigner\Service\Editor\Element\OpeningHandler;
use FlatDesigner\Service\Editor\Element\PlotHandler;
use FlatDesigner\Service\Editor\Element\RoomHandler;
use FlatDesigner\Service\Editor\Element\VariantSetHandler;
use FlatDesigner\Service\Editor\Element\WallHandler;

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
		};
	}
}
