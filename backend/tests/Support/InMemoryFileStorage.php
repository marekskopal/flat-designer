<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use FlatDesigner\Service\Storage\FileStorageInterface;
use RuntimeException;

/** Stands in for S3 in tests; `objects` is inspectable. */
final class InMemoryFileStorage implements FileStorageInterface
{
	/** @var array<string, string> */
	public array $objects = [];

	public bool $failPuts = false;

	public function put(string $key, string $body, string $contentType): void
	{
		if ($this->failPuts) {
			throw new RuntimeException('Storage is down.');
		}
		$this->objects[$key] = $body;
	}

	public function get(string $key): string
	{
		return $this->objects[$key] ?? throw new RuntimeException('No object ' . $key);
	}

	public function delete(string $key): void
	{
		unset($this->objects[$key]);
	}

	public function reset(): void
	{
		$this->objects = [];
		$this->failPuts = false;
	}
}
