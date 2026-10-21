<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** The price list of a project: its currency, the waste allowance for tiles and planks (%) and the prices. Also the body that replaces it. */
final readonly class MaterialPricesDto implements ArrayFactoryInterface
{
	/** @param list<MaterialPriceDto> $prices */
	public function __construct(public string $currency, public int $wastePercent, public array $prices)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			currency: BodyReader::string($data, 'currency'),
			wastePercent: BodyReader::int($data, 'wastePercent'),
			prices: array_map(MaterialPriceDto::fromArray(...), BodyReader::objectList($data, 'prices')),
		);
	}
}
