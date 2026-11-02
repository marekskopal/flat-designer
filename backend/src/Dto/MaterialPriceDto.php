<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\QuantitySurfaceEnum;
use Izbik\Model\Entity\ProjectMaterialPrice;
use Izbik\Service\Quantities\PriceList;
use Izbik\Service\Request\BodyReader;
use Izbik\Service\Request\Exception\InvalidRequestException;

/** A project's price of a material, or of a surface's default look (`surface`, without a material): per m² and per running metre. */
final readonly class MaterialPriceDto implements ArrayFactoryInterface
{
	public function __construct(
		public ?int $materialId,
		public ?QuantitySurfaceEnum $surface,
		public ?float $pricePerArea,
		public ?float $pricePerLength,
	) {
	}

	public static function fromEntity(ProjectMaterialPrice $price): self
	{
		return new self($price->material?->id, $price->surface, $price->pricePerArea, $price->pricePerLength);
	}

	/** Identifies what the price is for: a material, or a surface's default look. */
	public function key(): string
	{
		return PriceList::key($this->materialId, $this->surface);
	}

	public static function fromArray(array $data): static
	{
		$surface = BodyReader::optionalString($data, 'surface');

		return new self(
			materialId: BodyReader::optionalInt($data, 'materialId'),
			surface: $surface === null ? null : QuantitySurfaceEnum::tryFrom($surface)
				?? throw new InvalidRequestException(sprintf('Unknown surface "%s".', $surface)),
			pricePerArea: BodyReader::optionalNumber($data, 'pricePerArea'),
			pricePerLength: BodyReader::optionalNumber($data, 'pricePerLength'),
		);
	}
}
