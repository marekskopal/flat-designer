<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

/** How large a project file may be (`IMPORT_MAX_SIZE_MB`, default 500); each file in it is also held to `FILE_MAX_SIZE_MB`. */
final readonly class ProjectImportConfig
{
	private const int DefaultMaxSizeMb = 500;

	public function __construct(public int $maxBytes)
	{
	}

	public static function fromEnv(): self
	{
		$maxMb = (int) getenv('IMPORT_MAX_SIZE_MB');

		return new self(($maxMb > 0 ? $maxMb : self::DefaultMaxSizeMb) * 1024 * 1024);
	}
}
