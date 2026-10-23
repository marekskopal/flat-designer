<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use stdClass;

/**
 * What a guest opening a share link gets: the link's settings and the frozen floor scene (shaped like `SceneDto`,
 * without a revision), and with `settings.allFloors` the scenes of the floors under it (`stack`, lowest first). Nothing
 * else of the project (files, the floors above, owner) is exposed.
 */
final readonly class SharedViewDto
{
	/** @param list<stdClass> $stack */
	public function __construct(
		public string $name,
		public string $projectName,
		public ShareSettingsDto $settings,
		public stdClass $scene,
		public array $stack = [],
	) {
	}
}
