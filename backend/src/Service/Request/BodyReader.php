<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Request;

use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/** Typed access to fields of a decoded JSON body, for `ArrayFactoryInterface::fromArray()`. */
final readonly class BodyReader
{
	/** @param array<string, mixed> $data */
	public static function string(array $data, string $key): string
	{
		$value = $data[$key] ?? null;
		if (!is_string($value)) {
			throw new InvalidRequestException(sprintf('Field "%s" must be a string.', $key));
		}

		return $value;
	}

	/** @param array<string, mixed> $data */
	public static function optionalString(array $data, string $key): ?string
	{
		if (($data[$key] ?? null) === null) {
			return null;
		}

		return self::string($data, $key);
	}

	/** @param array<string, mixed> $data */
	public static function int(array $data, string $key): int
	{
		$value = $data[$key] ?? null;
		if (!is_int($value)) {
			throw new InvalidRequestException(sprintf('Field "%s" must be an integer.', $key));
		}

		return $value;
	}

	/** @param array<string, mixed> $data */
	public static function optionalInt(array $data, string $key): ?int
	{
		if (($data[$key] ?? null) === null) {
			return null;
		}

		return self::int($data, $key);
	}
}
