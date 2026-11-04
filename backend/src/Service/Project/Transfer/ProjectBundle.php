<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use const PATHINFO_EXTENSION;

/**
 * A project exported to move it to another instance or keep it as a backup (`ProjectExporter`): a zip holding
 * `project.json` with the project's files and models beside it, or `project.json`'s content alone, without them.
 *
 * Format 1: `{format, kind, exportedAt, snapshot, pricing: {currency, wastePercent, prices: [{materialCode, surface,
 * pricePerArea, pricePerLength}]}, definitions: [{id, name, description, definition}], files: [{id, filename, mimeType,
 * path}], models: [{id, name, description, license, author, sourceUrl, filename, path}]}`. The snapshot is
 * `ProjectSnapshotService`'s; `definitions` are the project's own (those no item uses too), the materials go by code, and
 * the ids only link entries inside the file. `path` is the zip entry of a file or model; without files both lists are
 * empty (the snapshot's `models` still name the models, matched by their source on import).
 */
final readonly class ProjectBundle
{
	public const int Format = 1;

	public const string Kind = 'izbik-project';

	public const string Manifest = 'project.json';

	/** The entry of a file: its id and extension (the name, which may not suit every file system, is in `files`). */
	public static function filePath(int $id, string $filename): string
	{
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? sprintf('files/%d.%s', $id, $extension) : sprintf('files/%d', $id);
	}

	public static function modelPath(int $id): string
	{
		return sprintf('models/%d.glb', $id);
	}
}
