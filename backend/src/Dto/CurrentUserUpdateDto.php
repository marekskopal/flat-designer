<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\ElectricalSymbolsEnum;
use Izbik\Service\Request\BodyReader;
use Izbik\Service\Request\Exception\InvalidRequestException;

/** The fields a user changes on their own account; null leaves one as it is. */
final readonly class CurrentUserUpdateDto implements ArrayFactoryInterface
{
	public function __construct(public ?string $name, public ?ElectricalSymbolsEnum $electricalSymbols = null)
	{
	}

	public static function fromArray(array $data): static
	{
		$symbols = BodyReader::optionalString($data, 'electricalSymbols');

		return new self(
			name: BodyReader::optionalString($data, 'name'),
			electricalSymbols: $symbols === null ? null : (ElectricalSymbolsEnum::tryFrom($symbols) ?? throw new InvalidRequestException(
				'Field "electricalSymbols" must be "Iec" or "Csn".',
			)),
		);
	}
}
