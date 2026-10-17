<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use FlatDesigner\Service\Project\Exception\InvalidSnapshotException;

/** Typed access to one JSON object of a decoded snapshot; any mismatch throws `InvalidSnapshotException`. */
final readonly class SnapshotReader
{
	/** @param array<mixed> $data */
	public function __construct(private array $data, private string $path = '')
	{
	}

	public function int(string $key): int
	{
		$value = $this->data[$key] ?? null;

		return is_int($value) ? $value : throw $this->invalid($key, 'an integer');
	}

	public function nullableInt(string $key): ?int
	{
		return ($this->data[$key] ?? null) === null ? null : $this->int($key);
	}

	public function string(string $key): string
	{
		$value = $this->data[$key] ?? null;

		return is_string($value) ? $value : throw $this->invalid($key, 'a string');
	}

	public function nullableString(string $key): ?string
	{
		return ($this->data[$key] ?? null) === null ? null : $this->string($key);
	}

	/** Any JSON value, e.g. a polygon or item params, to be re-encoded as is. */
	public function raw(string $key): mixed
	{
		return $this->data[$key] ?? null;
	}

	public function object(string $key): self
	{
		$value = $this->data[$key] ?? null;

		return is_array($value) ? new self($value, $this->path . '.' . $key) : throw $this->invalid($key, 'an object');
	}

	/** @return list<self> */
	public function objects(string $key): array
	{
		$value = $this->data[$key] ?? null;
		if (!is_array($value) || !array_is_list($value)) {
			throw $this->invalid($key, 'a list');
		}

		$objects = [];
		foreach ($value as $index => $item) {
			if (!is_array($item)) {
				throw $this->invalid($key . '[' . $index . ']', 'an object');
			}
			$objects[] = new self($item, $this->path . '.' . $key . '[' . $index . ']');
		}

		return $objects;
	}

	private function invalid(string $key, string $expected): InvalidSnapshotException
	{
		return new InvalidSnapshotException(sprintf('Snapshot field "%s.%s" must be %s.', $this->path, $key, $expected));
	}
}
