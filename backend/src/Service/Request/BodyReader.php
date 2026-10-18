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
}
