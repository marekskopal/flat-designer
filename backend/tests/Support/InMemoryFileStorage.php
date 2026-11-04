<?php

declare(strict_types=1);

namespace Izbik\Tests\Support;

use Izbik\Service\Storage\FileStorageInterface;
use Izbik\Service\Storage\StoredObject;
use RuntimeException;

/** Stands in for S3 in tests; `objects` is inspectable. */
final class InMemoryFileStorage implements FileStorageInterface
{
	/** @var array<string, string> */
	public array $objects = [];

	/** @var array<string, string> */
	public array $contentTypes = [];

	public bool $failPuts = false;

	/** @var list<string> keys whose delete fails */
	public array $failingDeletes = [];

	public function put(string $key, string $body, string $contentType): void
	{
		if ($this->failPuts) {
			throw new RuntimeException('Storage is down.');
		}
		$this->objects[$key] = $body;
		$this->contentTypes[$key] = $contentType;
	}

	public function get(string $key): string
	{
		return $this->objects[$key] ?? throw new RuntimeException('No object ' . $key);
	}

	public function fetch(string $key): StoredObject
	{
		return new StoredObject($this->get($key), $this->contentTypes[$key] ?? 'application/octet-stream');
	}

	/** @return list<string> */
	public function keys(): array
	{
		return array_keys($this->objects);
	}

	public function delete(string $key): void
	{
		if (in_array($key, $this->failingDeletes, true)) {
			throw new RuntimeException('Storage is down.');
		}
		unset($this->objects[$key], $this->contentTypes[$key]);
	}

	public function reset(): void
	{
		$this->objects = [];
		$this->contentTypes = [];
		$this->failPuts = false;
		$this->failingDeletes = [];
	}
}
