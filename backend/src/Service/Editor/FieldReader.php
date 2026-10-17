<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use BackedEnum;
use FlatDesigner\Service\Editor\Exception\EditException;

/** Validating access to the fields of an element's state; every violation is a 422 naming the field. */
final readonly class FieldReader
{
	/** @param array<string, mixed> $data */
	public function __construct(private array $data)
	{
	}

	public function int(string $key, int $min, int $max): int
	{
		$value = $this->data[$key] ?? null;
		if (!is_int($value) || $value < $min || $value > $max) {
			throw EditException::invalid(sprintf('Field "%s" must be a whole number of mm between %d and %d.', $key, $min, $max));
		}

		return $value;
	}

	public function nullableInt(string $key, int $min, int $max): ?int
	{
		return ($this->data[$key] ?? null) === null ? null : $this->int($key, $min, $max);
	}

	/** An id referring to another row (room, material, wall). */
	public function nullableId(string $key): ?int
	{
		$value = $this->data[$key] ?? null;
		if ($value !== null && (!is_int($value) || $value < 1)) {
			throw EditException::invalid(sprintf('Field "%s" must be an id or null.', $key));
		}

		return $value;
	}

	/** Trimmed, non-empty unless `$allowEmpty`. */
	public function string(string $key, int $maxLength, bool $allowEmpty = false): string
	{
		$value = $this->data[$key] ?? null;
		$value = is_string($value) ? trim($value) : null;
		if ($value === null || !$allowEmpty && $value === '' || mb_strlen($value) > $maxLength) {
			throw EditException::invalid(sprintf('Field "%s" must be a text of 1 to %d characters.', $key, $maxLength));
		}

		return $value;
	}

	/**
	 * @param class-string<E> $enum
	 * @return E
	 * @template E of BackedEnum
	 */
	public function enum(string $key, string $enum): BackedEnum
	{
		$value = $this->data[$key] ?? null;
		$case = is_string($value) ? $enum::tryFrom($value) : null;
		if ($case === null) {
			$allowed = array_map(fn (BackedEnum $c): string => (string) $c->value, $enum::cases());

			throw EditException::invalid(sprintf('Field "%s" must be one of: %s.', $key, implode(', ', $allowed)));
		}

		return $case;
	}

	/**
	 * @param class-string<E> $enum
	 * @return E|null
	 * @template E of BackedEnum
	 */
	public function nullableEnum(string $key, string $enum): ?BackedEnum
	{
		return ($this->data[$key] ?? null) === null ? null : $this->enum($key, $enum);
	}

	public function raw(string $key): mixed
	{
		return $this->data[$key] ?? null;
	}
}
