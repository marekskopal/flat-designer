<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Body of saved view create and update: its floor (of the working copy), name and settings. */
final readonly class SavedViewInputDto implements ArrayFactoryInterface
{
	public function __construct(public int $floorId, public string $name, public SavedViewSettingsDto $settings)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			floorId: BodyReader::int($data, 'floorId'),
			name: BodyReader::string($data, 'name'),
			settings: SavedViewSettingsDto::fromArray(BodyReader::object($data, 'settings')),
		);
	}
}
