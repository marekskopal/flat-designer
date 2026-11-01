<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\ElectricalSymbolsEnum;
use stdClass;

/**
 * What a guest opening a share link gets: the link's settings and the frozen floor scene (shaped like `SceneDto`,
 * without a revision), and with `settings.allFloors` the scenes of the floors under it (`stack`, lowest first) and, when
 * the link offers the walk too, of those above it (`above`, for a walk over all floors, FD-102), and the project owner's
 * symbol set for the electrical plan (`electricalSymbols`, FD-64). Nothing else of the project (files, owner) is
 * exposed.
 */
final readonly class SharedViewDto
{
	/**
	 * @param list<stdClass> $stack
	 * @param list<stdClass> $above
	 */
	public function __construct(
		public string $name,
		public string $projectName,
		public ShareSettingsDto $settings,
		public stdClass $scene,
		public array $stack = [],
		public array $above = [],
		public ElectricalSymbolsEnum $electricalSymbols = ElectricalSymbolsEnum::Iec,
	) {
	}
}
