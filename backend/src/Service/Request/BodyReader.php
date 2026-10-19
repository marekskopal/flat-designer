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

	/** @param array<string, mixed> $data */
	public static function number(array $data, string $key): float
	{
		$value = $data[$key] ?? null;
		if (!is_int($value) && !is_float($value)) {
			throw new InvalidRequestException(sprintf('Field "%s" must be a number.', $key));
		}

		return (float) $value;
	}

	/** @param array<string, mixed> $data */
	public static function optionalNumber(array $data, string $key): ?float
	{
		if (($data[$key] ?? null) === null) {
			return null;
		}

		return self::number($data, $key);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<int>
	 */
	public static function intList(array $data, string $key): array
	{
		$value = $data[$key] ?? null;
		$list = [];
		foreach (is_array($value) && array_is_list($value) ? $value : [null] as $item) {
			if (!is_int($item)) {
				throw new InvalidRequestException(sprintf('Field "%s" must be a list of integers.', $key));
			}
			$list[] = $item;
		}

		return $list;
	}

	/** @param array<string, mixed> $data */
	public static function bool(array $data, string $key): bool
	{
		$value = $data[$key] ?? null;
		if (!is_bool($value)) {
			throw new InvalidRequestException(sprintf('Field "%s" must be a boolean.', $key));
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function object(array $data, string $key): array
	{
		$value = $data[$key] ?? null;
		if (!is_array($value) || array_is_list($value) && $value !== []) {
			throw new InvalidRequestException(sprintf('Field "%s" must be an object.', $key));
		}

		/** @var array<string, mixed> $object */
		$object = $value;

		return $object;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	public static function optionalObject(array $data, string $key): ?array
	{
		if (($data[$key] ?? null) === null) {
			return null;
		}

		return self::object($data, $key);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<array<string, mixed>>
	 */
	public static function objectList(array $data, string $key): array
	{
		$value = $data[$key] ?? null;
		if (!is_array($value) || !array_is_list($value)) {
			throw new InvalidRequestException(sprintf('Field "%s" must be a list of objects.', $key));
		}

		$list = [];
		foreach ($value as $item) {
			if (!is_array($item) || array_is_list($item) && $item !== []) {
				throw new InvalidRequestException(sprintf('Field "%s" must be a list of objects.', $key));
			}
			/** @var array<string, mixed> $object */
			$object = $item;
			$list[] = $object;
		}

		return $list;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<string>
	 */
	public static function stringList(array $data, string $key): array
	{
		$value = $data[$key] ?? null;
		$list = [];
		foreach (is_array($value) && array_is_list($value) ? $value : [null] as $item) {
			if (!is_string($item)) {
				throw new InvalidRequestException(sprintf('Field "%s" must be a list of strings.', $key));
			}
			$list[] = $item;
		}

		return $list;
	}

	/**
	 * A list of [x, y] integer pairs (plan points, mm).
	 *
	 * @param array<string, mixed> $data
	 * @return list<array{int, int}>
	 */
	public static function pointList(array $data, string $key): array
	{
		$value = $data[$key] ?? null;
		$list = [];
		foreach (is_array($value) && array_is_list($value) ? $value : [null] as $point) {
			if (!is_array($point) || !array_is_list($point) || count($point) !== 2 || !is_int($point[0]) || !is_int($point[1])) {
				throw new InvalidRequestException(sprintf('Field "%s" must be a list of [x, y] integer points.', $key));
			}
			$list[] = [$point[0], $point[1]];
		}

		return $list;
	}
}
