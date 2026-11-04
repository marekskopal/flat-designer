<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use ZipArchive;

/**
 * A project file being imported: its manifest (`project.json`) and, for a zip, the entries it names. An entry is read
 * only by the path the manifest gives and never written to disk, so names in the archive cannot reach the file system;
 * each entry is read once at most, and all of them together within `$maxTotalBytes`, so a manifest naming one entry
 * many times does not get past the import's limit.
 */
final class OpenedBundle
{
	/** @var array<string, true> */
	private array $read = [];

	private int $total = 0;

	/** @param array<mixed> $manifest */
	public function __construct(
		public readonly array $manifest,
		private readonly ?ZipArchive $zip,
		private readonly int $maxEntryBytes,
		private readonly int $maxTotalBytes,
	) {
	}

	/** The entry's bytes; null when the bundle has no such entry, it was read already, or it is larger than allowed. */
	public function entry(string $path): ?string
	{
		$stat = $this->zip?->statName($path);
		if ($this->zip === null || $stat === false || $stat === null || isset($this->read[$path])) {
			return null;
		}
		if ($stat['size'] > $this->maxEntryBytes || $this->total + $stat['size'] > $this->maxTotalBytes) {
			return null;
		}
		$this->read[$path] = true;
		$bytes = $this->zip->getFromName($path, $stat['size']);
		if ($bytes === false || strlen($bytes) !== $stat['size']) {
			return null;
		}
		$this->total += $stat['size'];

		return $bytes;
	}

	public function close(): void
	{
		$this->zip?->close();
	}
}
