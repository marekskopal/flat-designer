<?php

declare(strict_types=1);

namespace Izbik\Service\Storage;

use RuntimeException;
use stdClass;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Copies every stored object (project files, models) into a directory and back, whatever the storage is (the bundled
 * MinIO or S3): `<directory>/objects/<key>` and `<directory>/objects.json`, mapping each key to its content type. A
 * restore puts back the objects listed there; objects stored since are left as they are (no row points at them).
 */
final readonly class StorageBackup
{
	private const string Manifest = 'objects.json';

	private const string Objects = 'objects';

	/** Keys as the app makes them (`projects/<id>/<hex>.<ext>`, `models/<hex>.glb`): no `..` or absolute paths. */
	private const string KeyPattern = '~^[A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$~';

	public function __construct(private FileStorageInterface $storage)
	{
	}

	/**
	 * @param (callable(string): void)|null $progress called with each key written
	 * @return int the number of objects
	 */
	public function backup(string $directory, ?callable $progress = null): int
	{
		$types = [];
		foreach ($this->storage->keys() as $key) {
			$object = $this->storage->fetch($key);
			$file = $this->path($directory, $key);
			if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) || file_put_contents($file, $object->body) === false) {
				throw new RuntimeException(sprintf('Cannot write %s.', $file));
			}
			$types[$key] = $object->contentType;
			if ($progress !== null) {
				$progress($key);
			}
		}
		$manifest = json_encode($types === [] ? new stdClass() : $types, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (file_put_contents($directory . '/' . self::Manifest, $manifest . "\n") === false) {
			throw new RuntimeException(sprintf('Cannot write %s/%s.', $directory, self::Manifest));
		}

		return count($types);
	}

	/**
	 * @param (callable(string): void)|null $progress called with each key put back
	 * @return int the number of objects
	 */
	public function restore(string $directory, ?callable $progress = null): int
	{
		$manifest = @file_get_contents($directory . '/' . self::Manifest);
		if ($manifest === false) {
			throw new RuntimeException(sprintf('%s has no %s: not a storage backup.', $directory, self::Manifest));
		}
		$types = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
		if (!is_array($types)) {
			throw new RuntimeException(sprintf('%s is not a storage backup manifest.', self::Manifest));
		}

		$restored = 0;
		foreach ($types as $key => $contentType) {
			if (!is_string($key) || !is_string($contentType)) {
				throw new RuntimeException(sprintf('%s lists an entry that is not a key and a content type.', self::Manifest));
			}
			$body = @file_get_contents($this->path($directory, $key));
			if ($body === false) {
				throw new RuntimeException(sprintf('The backup is missing the object %s.', $key));
			}
			$this->storage->put($key, $body, $contentType);
			$restored++;
			if ($progress !== null) {
				$progress($key);
			}
		}

		return $restored;
	}

	private function path(string $directory, string $key): string
	{
		if (preg_match(self::KeyPattern, $key) !== 1) {
			throw new RuntimeException(sprintf('"%s" is not a storage key this app makes.', $key));
		}

		return $directory . '/' . self::Objects . '/' . $key;
	}
}
