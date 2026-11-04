<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use Izbik\Service\Project\Exception\InvalidSnapshotException;
use Izbik\Service\Project\SnapshotJson;
use Izbik\Service\Project\SnapshotLimits;
use Izbik\Service\Storage\S3Config;
use JsonException;
use ZipArchive;
use const JSON_THROW_ON_ERROR;

/**
 * Opens a project file (`ProjectBundle`): a zip with `project.json`, or that JSON alone, told apart by the first bytes.
 * A zip is checked before anything is read from it: at most `MaxEntries` entries, their declared sizes within the
 * import limit together, `project.json` within `MaxManifestBytes`.
 */
final readonly class ProjectBundleReader
{
	public const int MaxEntries = 2000;

	public const int MaxManifestBytes = 50 * 1024 * 1024;

	public function __construct(private ProjectImportConfig $config, private S3Config $s3Config)
	{
	}

	public function open(string $path): OpenedBundle
	{
		$size = filesize($path);
		if ($size === false || $size === 0) {
			throw new ProjectImportException('The project file is empty.');
		}
		if ($size > $this->config->maxBytes) {
			throw new ProjectImportException(
				sprintf('The project file is larger than %d MB.', intdiv($this->config->maxBytes, 1024 * 1024)),
				413,
			);
		}
		$head = (string) file_get_contents($path, length: 4);
		if ($head === "PK\x03\x04") {
			return $this->openZip($path);
		}
		if ($size > self::MaxManifestBytes) {
			throw new ProjectImportException('The project file is too large for a project without files.', 413);
		}

		return new OpenedBundle(self::manifest((string) file_get_contents($path)), null, $this->s3Config->maxFileSizeBytes, 0);
	}

	private function openZip(string $path): OpenedBundle
	{
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new ProjectImportException('The project file is not a readable zip archive.');
		}

		try {
			if ($zip->numFiles > self::MaxEntries) {
				throw new ProjectImportException(sprintf('A project file may hold at most %d files.', self::MaxEntries));
			}
			$total = 0;
			for ($index = 0; $index < $zip->numFiles; $index++) {
				$stat = $zip->statIndex($index);
				$total += $stat === false ? 0 : $stat['size'];
			}
			if ($total > $this->config->maxBytes) {
				throw new ProjectImportException(
					sprintf('The project file unpacks to more than %d MB.', intdiv($this->config->maxBytes, 1024 * 1024)),
					413,
				);
			}
			$stat = $zip->statName(ProjectBundle::Manifest);
			if ($stat === false) {
				throw new ProjectImportException(
					sprintf('The zip archive holds no %s; it is not a project file.', ProjectBundle::Manifest),
				);
			}
			if ($stat['size'] > self::MaxManifestBytes) {
				throw new ProjectImportException(sprintf('%s is too large.', ProjectBundle::Manifest), 413);
			}
			$json = $zip->getFromName(ProjectBundle::Manifest, $stat['size']);
			if ($json === false) {
				throw new ProjectImportException(sprintf('%s cannot be read.', ProjectBundle::Manifest));
			}

			return new OpenedBundle(self::manifest($json), $zip, $this->s3Config->maxFileSizeBytes, $this->config->maxBytes);
		} catch (ProjectImportException $e) {
			$zip->close();

			throw $e;
		}
	}

	/**
	 * The limits checked before anything is stored: the snapshot's (`SnapshotLimits`), the own definitions and the files
	 * and models.
	 *
	 * @param array<mixed> $manifest
	 */
	private static function checkLimits(array $manifest): void
	{
		try {
			SnapshotLimits::check(SnapshotJson::array($manifest, 'snapshot'));
		} catch (InvalidSnapshotException $e) {
			throw new ProjectImportException($e->getMessage(), previous: $e);
		}

		$count = static fn (string $key): int => is_array($manifest[$key] ?? null) ? count($manifest[$key]) : 0;
		if ($count('definitions') > SnapshotLimits::MaxDefinitions) {
			throw new ProjectImportException(
				sprintf('A project file may hold at most %d furniture definitions.', SnapshotLimits::MaxDefinitions),
			);
		}
		if ($count('files') + $count('models') > SnapshotLimits::MaxAssets) {
			throw new ProjectImportException(sprintf('A project file may hold at most %d files and models.', SnapshotLimits::MaxAssets));
		}
	}

	/** @return array<mixed> */
	private static function manifest(string $json): array
	{
		try {
			$manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new ProjectImportException('The project file is not valid JSON.', previous: $e);
		}

		if (!is_array($manifest) || ($manifest['kind'] ?? null) !== ProjectBundle::Kind) {
			throw new ProjectImportException('This is not an Izbik project file.');
		}
		if (($manifest['format'] ?? null) !== ProjectBundle::Format) {
			throw new ProjectImportException('The project file is of a format this version of Izbik does not know; update it first.');
		}
		if (!is_array($manifest['snapshot'] ?? null)) {
			throw new ProjectImportException('The project file holds no project.');
		}
		self::checkLimits($manifest);

		return $manifest;
	}
}
