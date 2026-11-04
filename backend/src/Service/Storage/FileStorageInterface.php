<?php

declare(strict_types=1);

namespace Izbik\Service\Storage;

/** Object storage for uploaded files (S3 in the stack, in-memory in tests). */
interface FileStorageInterface
{
	public function put(string $key, string $body, string $contentType): void;

	public function get(string $key): string;

	/** The object with the content type it was stored with (for backups). */
	public function fetch(string $key): StoredObject;

	/**
	 * Every key in the storage, for backups.
	 *
	 * @return list<string>
	 */
	public function keys(): array;

	/** Best effort: a failure is logged, not thrown, so a missing object never blocks deleting its row. */
	public function delete(string $key): void;
}
