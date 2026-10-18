<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use stdClass;

/**
 * What a guest opening a share link gets: the link's settings and the frozen floor scene (shaped like `SceneDto`,
 * without a revision). Nothing else of the project (files, other floors, owner) is exposed.
 */
final readonly class SharedViewDto
{
	public function __construct(public string $name, public string $projectName, public ShareSettingsDto $settings, public stdClass $scene,)
	{
	}
}
