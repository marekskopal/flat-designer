<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\ProjectFileDto;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Service\Project\ImagePreviewService;
use FlatDesigner\Service\Project\ProjectFileProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\ToolAnnotations;
use RuntimeException;

/** Files uploaded to a project (plans, photos, renders), so an agent can work from them. */
final readonly class FileTools
{
	private const array TextMimeTypes = ['text/plain', 'text/csv', 'application/json'];
	private const int MaxTextBytes = 200000;

	public function __construct(
		private ToolContext $context,
		private ProjectFileProvider $projectFileProvider,
		private ImagePreviewService $imagePreview,
	) {
	}

	/** @return list<ProjectFileDto> */
	#[McpTool(
		name: 'list_project_files',
		description: 'Files uploaded to a project (floor plans, photos, renders, documents): id, filename, mimeType, size in bytes. '
			. 'Read one with get_project_file.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listProjectFiles(int $projectId): array
	{
		return array_map(
			fn (ProjectFile $file): ProjectFileDto => ProjectFileDto::fromEntity($file),
			$this->projectFileProvider->list($this->context->project($projectId)),
		);
	}

	/**
	 * @param int|null $cropX left edge of the region to return, source pixels
	 * @param int|null $cropY top edge of the region, source pixels (y counts down from the top)
	 * @param int|null $cropWidth width of the region, source pixels
	 * @param int|null $cropHeight height of the region, source pixels
	 * @param int $maxSize the longer side of the returned image in pixels at most (64–4096); larger images are scaled down
	 * @param int|null $grid draw a red grid every this many source pixels, labelled with source pixel coordinates
	 * @return list<TextContent|ImageContent>
	 */
	#[McpTool(
		name: 'get_project_file',
		description: 'The content of a project file. An image comes back as an image plus a text line with its source size '
			. '(widthPx × heightPx), the crop and the scale (returned pixels per source pixel). Zoom into a region with '
			. 'cropX/cropY/cropWidth/cropHeight, and add grid to read exact source pixel positions off a plan (for '
			. 'set_floor_underlay points or for tracing walls). Text files (txt, csv, json) come back as text; PDFs are not '
			. 'supported yet.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getProjectFile(
		int $projectId,
		int $fileId,
		?int $cropX = null,
		?int $cropY = null,
		?int $cropWidth = null,
		?int $cropHeight = null,
		int $maxSize = ImagePreviewService::DefaultMaxSize,
		?int $grid = null,
	): array {
		$file = $this->projectFileProvider->find($this->context->project($projectId), $fileId)
			?? throw new RuntimeException(sprintf('File %d is not a file of project %d. Use list_project_files.', $fileId, $projectId));

		if (in_array($file->mimeType, self::TextMimeTypes, true)) {
			if ($file->size > self::MaxTextBytes) {
				throw new RuntimeException(sprintf('File "%s" is larger than %d kB of text.', $file->filename, self::MaxTextBytes / 1000));
			}

			return [new TextContent($this->projectFileProvider->readContent($file))];
		}
		if (!str_starts_with($file->mimeType, 'image/') || $file->mimeType === 'image/svg+xml') {
			throw new RuntimeException(sprintf(
				'File "%s" is %s; only images and text files can be read. Upload a PDF page as an image.',
				$file->filename,
				$file->mimeType,
			));
		}

		$crop = null;
		if ($cropX !== null || $cropY !== null || $cropWidth !== null || $cropHeight !== null) {
			if ($cropX === null || $cropY === null || $cropWidth === null || $cropHeight === null) {
				throw new RuntimeException('A crop needs all of cropX, cropY, cropWidth and cropHeight.');
			}
			$crop = ['x' => $cropX, 'y' => $cropY, 'width' => $cropWidth, 'height' => $cropHeight];
		}

		$preview = $this->imagePreview->render($this->projectFileProvider->readContent($file), $file->mimeType, $crop, $maxSize, $grid);

		return [
			new TextContent([
				'fileId' => $file->id,
				'filename' => $file->filename,
				'widthPx' => $preview['widthPx'],
				'heightPx' => $preview['heightPx'],
				'crop' => $preview['crop'],
				'scale' => round($preview['scale'], 6),
			]),
			new ImageContent(base64_encode($preview['data']), $preview['mimeType']),
		];
	}
}
