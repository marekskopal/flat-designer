<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Storage;

/** Object storage for uploaded files (S3 in the stack, in-memory in tests). */
interface FileStorageInterface
{
	public function put(string $key, string $body, string $contentType): void;

	public function get(string $key): string;

	/** Best effort: a failure is logged, not thrown, so a missing object never blocks deleting its row. */
	public function delete(string $key): void;
}
