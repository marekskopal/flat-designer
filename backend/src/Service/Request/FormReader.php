<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Request;

/** Reading the fields of a form (multipart) body, where every field arrives as a string, like `BodyReader` does for JSON. */
final readonly class FormReader
{
	/**
	 * An optional integer: its digits as the form sends them, or an integer; an empty field is none.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function optionalInt(array $data, string $key): ?int
	{
		$value = $data[$key] ?? null;
		if (is_string($value) && ctype_digit($value)) {
			return (int) $value;
		}

		return $value === '' ? null : BodyReader::optionalInt($data, $key);
	}
}
