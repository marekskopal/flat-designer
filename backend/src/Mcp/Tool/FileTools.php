<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\ProjectFileDto;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Service\Project\ImagePreviewService;
use FlatDesigner\Service\Project\ProjectFileProvider;
use FlatDesigner\Service\Upload\UploadTicketService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\ToolAnnotations;
use RuntimeException;

/** Files uploaded to a project (plans, photos, renders), so an agent can work from them, and their upload. */
final readonly class FileTools
{
	public function __construct(
		private ToolContext $context,
		private ProjectFileProvider $projectFileProvider,
		private ImagePreviewService $imagePreview,
		private UploadTicketService $uploadTicketService,
	) {
	}

	/** @param string $content the file's bytes, base64 encoded */
	#[McpTool(
		name: 'upload_project_file',
		description: 'Upload a small file (a plan, photo, poster, JSON note) to a project, with its content as base64. '
			. 'Only for files up to a few hundred kB: the whole content has to be written into the call. For a larger file you '
			. 'can read from a shell, use request_file_upload. The type is read from the content; the size limit is the '
			. 'app\'s upload limit. Returns the file (id for get_project_file, set_floor_underlay or a picture parameter).',
	)]
	public function uploadProjectFile(int $projectId, string $filename, string $content): ProjectFileDto
	{
		return ProjectFileDto::fromEntity($this->projectFileProvider->upload(
			$this->context->user(),
			$this->context->project($projectId),
			$filename,
			UploadContent::decodeBase64($content),
		));
	}

	/** @return array{uploadUrl: string, expiresAt: string, curl: string} */
	#[McpTool(
		name: 'request_file_upload',
		description: 'A one-time URL to upload a file of any size up to the app\'s upload limit to a project, for a file on '
			. 'a disk you can reach from a shell. Send the file as the raw body of a PUT, e.g. with the returned curl '
			. 'command; the answer is the stored file as JSON (id, filename, mimeType, size), or an error saying what was '
			. 'rejected. The URL works once, for 15 minutes; a rejected file may be sent again to the same URL.',
	)]
	public function requestFileUpload(int $projectId, string $filename): array
	{
		[$ticket, $token] = $this->uploadTicketService->createForFile(
			$this->context->user(),
			$this->context->project($projectId),
			$filename,
		);

		return UploadContent::uploadUrl($this->context->baseUrl(), $token, $ticket->expiresAt);
	}

	/** @return ProjectFileDto the deleted file */
	#[McpTool(
		name: 'delete_project_file',
		description: 'Delete a file of a project. A floor underlay laid from it goes too, and items showing it as a picture '
			. 'draw a plain one.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteProjectFile(int $projectId, int $fileId): ProjectFileDto
	{
		$file = $this->findFile($projectId, $fileId);
		$this->projectFileProvider->delete($file);

		return ProjectFileDto::fromEntity($file);
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
		$file = $this->findFile($projectId, $fileId);

		if ($this->projectFileProvider->isText($file)) {
			return [new TextContent($this->projectFileProvider->readText($file))];
		}
		if (!$this->projectFileProvider->isRasterImage($file)) {
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

	private function findFile(int $projectId, int $fileId): ProjectFile
	{
		return $this->projectFileProvider->find($this->context->project($projectId), $fileId)
			?? throw new RuntimeException(sprintf('File %d is not a file of project %d. Use list_project_files.', $fileId, $projectId));
	}
}
