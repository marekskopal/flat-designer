<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use DateTimeImmutable;
use DateTimeZone;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/**
 * Body of share link create and update. `floorId` (a floor of the working copy) is read on create only: the
 * shared floor is frozen in the link's version. `expiresAt` is an ISO 8601 date-time, null = never.
 */
final readonly class ShareLinkInputDto implements ArrayFactoryInterface
{
	public function __construct(
		public ?int $floorId,
		public string $name,
		public ShareSettingsDto $settings,
		public ?DateTimeImmutable $expiresAt,
	) {
	}

	public static function fromArray(array $data): static
	{
		return new self(
			floorId: BodyReader::optionalInt($data, 'floorId'),
			name: BodyReader::string($data, 'name'),
			settings: ShareSettingsDto::fromArray(BodyReader::object($data, 'settings')),
			expiresAt: self::parseDateTime(BodyReader::optionalString($data, 'expiresAt')),
		);
	}

	/** An ISO 8601 date-time in the server's time zone (null stays null). */
	public static function parseDateTime(?string $value): ?DateTimeImmutable
	{
		if ($value === null) {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()));
		} catch (\Throwable $e) {
			throw new InvalidRequestException('Field "expiresAt" must be an ISO 8601 date-time.', 400, $e);
		}
	}
}
