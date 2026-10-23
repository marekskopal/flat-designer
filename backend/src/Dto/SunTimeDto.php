<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/** A moment to place the viewer's sun at: the 21st of `month` (1–12) at `hour` (0–24, fractional) clock time on site. */
final readonly class SunTimeDto implements ArrayFactoryInterface
{
	public function __construct(public int $month, public float $hour)
	{
		if ($month < 1 || $month > 12) {
			throw new InvalidRequestException('Sun month must be 1–12.', 422);
		}
		if ($hour < 0 || $hour > 24) {
			throw new InvalidRequestException('Sun hour must be 0–24.', 422);
		}
	}

	public static function fromArray(array $data): static
	{
		return new self(month: BodyReader::int($data, 'month'), hour: BodyReader::number($data, 'hour'));
	}

	/**
	 * The `sun` field of settings; null when missing or null (the default afternoon sun).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function optional(array $data): ?self
	{
		$sun = BodyReader::optionalObject($data, 'sun');

		return $sun === null ? null : self::fromArray($sun);
	}

	/**
	 * The `sun` field of settings from an agent's month and hour: given together, or one of them with the other taken
	 * from `$current` (the settings' present sun, when they are updated).
	 *
	 * @param array<mixed>|null $current the present sun as decoded settings JSON
	 * @return array{month: int, hour: float}|null
	 */
	public static function fields(?int $month, ?float $hour, ?array $current = null): ?array
	{
		if ($month === null && $hour === null) {
			return null;
		}
		if ($current !== null) {
			$month ??= is_int($current['month'] ?? null) ? $current['month'] : null;
			$hour ??= is_numeric($current['hour'] ?? null) ? (float) $current['hour'] : null;
		}
		if ($month === null || $hour === null) {
			throw new InvalidRequestException('Give the sun\'s month and hour together.', 422);
		}
		$sun = new self($month, $hour);

		return ['month' => $sun->month, 'hour' => $sun->hour];
	}
}
