<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\ShareLinkDto;
use FlatDesigner\Dto\ShareLinkInputDto;
use FlatDesigner\Dto\ShareSettingsDto;
use FlatDesigner\Mcp\McpUserContext;
use FlatDesigner\Model\Entity\ShareLink;
use FlatDesigner\Service\Project\ShareLinkProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use RuntimeException;
use const JSON_THROW_ON_ERROR;

/**
 * Share links: view-only links to one floor for people without an account. Creating one saves a version, so the
 * link keeps showing the floor as it was then.
 */
final readonly class ShareLinkTools
{
	private const array Clearable = ['camera', 'sectionHeight', 'expiresAt'];

	private const array ViewsSchema = [
		'type' => ['array', 'null'],
		'description' => 'view modes the guest may switch between: "overview" (3D orbit), "top" (plan), "walkthrough" (first person)',
		'items' => ['type' => 'string', 'enum' => ['overview', 'top', 'walkthrough']],
		'default' => null,
	];

	private const array PointSchema = [
		'type' => 'object',
		'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'integer'], 'z' => ['type' => 'integer']],
		'required' => ['x', 'y', 'z'],
	];

	private const array CameraSchema = [
		'type' => ['object', 'null'],
		'description' => 'where the 3D view starts, plan mm (z = height above the floor): camera position and the point it looks at, '
			. 'e.g. {"position": {"x": 1500, "y": -2500, "z": 5500}, "target": {"x": 4500, "y": 3000, "z": 500}}; '
			. 'needs "overview" among the views',
		'properties' => ['position' => self::PointSchema, 'target' => self::PointSchema],
		'required' => ['position', 'target'],
		'default' => null,
	];

	public function __construct(
		private ToolContext $context,
		private ShareLinkProvider $shareLinkProvider,
		private McpUserContext $userContext,
	)
	{
	}

	/** @return list<ShareLinkDto> */
	#[McpTool(
		name: 'list_share_links',
		description: 'List the share links of a project (newest first) with their URL, floor, version, views, camera and expiry.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listShareLinks(int $projectId): array
	{
		return array_map(
			fn (ShareLink $link): ShareLinkDto => $this->toDto($link),
			$this->shareLinkProvider->list($this->context->project($projectId)),
		);
	}

	/**
	 * @param int $floorId floor of the working copy to share
	 * @param string $name what the link shows, e.g. "Kitchen for the carpenter" (at most 100 characters)
	 * @param list<string>|null $views view modes the guest may use (default: ["overview", "top"])
	 * @param string|null $initialView the view the link opens in, one of `views` (default: the first of them)
	 * @param array<string, mixed>|null $camera where the 3D view starts (default: the overview framing of the whole floor)
	 * @param bool $ceilings show ceilings at the start
	 * @param bool $labels show room labels at the start
	 * @param int|null $sectionHeight wall cut at the start, mm above the floor (900–2700); null = no cut
	 * @param string|null $expiresAt ISO 8601 date-time when the link stops working; null = never
	 */
	#[McpTool(
		name: 'create_share_link',
		description: 'Create a view-only link to one floor for people without an account. Saves a version of the project first: the '
			. 'link shows the floor (with its active variants) as it is now; later edits do not reach it.',
	)]
	public function createShareLink(
		int $projectId,
		int $floorId,
		string $name,
		#[Schema(definition: self::ViewsSchema)]
		?array $views = null,
		?string $initialView = null,
		#[Schema(definition: self::CameraSchema)]
		?array $camera = null,
		bool $ceilings = false,
		bool $labels = true,
		?int $sectionHeight = null,
		?string $expiresAt = null,
	): ShareLinkDto {
		$views ??= ['overview', 'top'];
		$settings = ShareSettingsDto::fromArray([
			'views' => $views,
			'initialView' => $initialView ?? $views[0] ?? 'overview',
			'camera' => $camera,
			'ceilings' => $ceilings,
			'labels' => $labels,
			'sectionHeight' => $sectionHeight,
		]);
		$link = $this->shareLinkProvider->create(
			$this->context->user(),
			$this->context->project($projectId),
			new ShareLinkInputDto($floorId, $name, $settings, ShareLinkInputDto::parseDateTime($expiresAt)),
		);

		return $this->toDto($link);
	}

	/**
	 * @param list<string>|null $views view modes the guest may use
	 * @param string|null $initialView the view the link opens in, one of `views`
	 * @param array<string, mixed>|null $camera where the 3D view starts
	 * @param bool|null $ceilings show ceilings at the start
	 * @param bool|null $labels show room labels at the start
	 * @param int|null $sectionHeight wall cut at the start, mm above the floor (900–2700)
	 * @param string|null $expiresAt ISO 8601 date-time when the link stops working
	 * @param list<string>|null $clear fields to reset: "camera" (default framing), "sectionHeight" (no cut), "expiresAt" (never)
	 */
	#[McpTool(
		name: 'update_share_link',
		description: 'Change a share link\'s name, views, start view, camera, start display or expiry; only the fields you pass change. '
			. 'The shared floor and its version stay.',
	)]
	public function updateShareLink(
		int $projectId,
		int $shareLinkId,
		?string $name = null,
		#[Schema(definition: self::ViewsSchema)]
		?array $views = null,
		?string $initialView = null,
		#[Schema(definition: self::CameraSchema)]
		?array $camera = null,
		?bool $ceilings = null,
		?bool $labels = null,
		?int $sectionHeight = null,
		?string $expiresAt = null,
		?array $clear = null,
	): ShareLinkDto {
		$link = $this->find($projectId, $shareLinkId);
		$current = json_decode(json_encode(ShareLinkProvider::readSettings($link), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		assert(is_array($current));
		$changes = ToolContext::fields(
			[
				'views' => $views,
				'initialView' => $initialView,
				'camera' => $camera,
				'ceilings' => $ceilings,
				'labels' => $labels,
				'sectionHeight' => $sectionHeight,
				'expiresAt' => $expiresAt,
			],
			$clear,
			self::Clearable,
		);
		// Cleared: null in $changes; given: the string; absent: the link's own.
		$expiry = array_key_exists('expiresAt', $changes)
			? ShareLinkInputDto::parseDateTime($changes['expiresAt'] === null ? null : $expiresAt)
			: $link->expiresAt;
		unset($changes['expiresAt']);
		/** @var array<string, mixed> $merged */
		$merged = [...$current, ...$changes];
		// Narrowing the views drops a start view that is no longer offered.
		if (
			$views !== null
			&& $initialView === null
			&& is_string($merged['initialView'] ?? null)
			&& !in_array($merged['initialView'], $views, true)
		) {
			$merged['initialView'] = $views[0] ?? null;
		}

		$this->shareLinkProvider->update($link, new ShareLinkInputDto(
			floorId: null,
			name: $name ?? $link->name,
			settings: ShareSettingsDto::fromArray($merged),
			expiresAt: $expiry,
		));

		return $this->toDto($link);
	}

	#[McpTool(
		name: 'delete_share_link',
		description: 'Delete a share link; anyone who has it loses access. The version it showed stays in the history.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	/** @return ShareLinkDto the deleted link */
	public function deleteShareLink(int $projectId, int $shareLinkId): ShareLinkDto
	{
		$link = $this->find($projectId, $shareLinkId);
		$deleted = $this->toDto($link);
		$this->shareLinkProvider->delete($link);

		return $deleted;
	}

	private function find(int $projectId, int $shareLinkId): ShareLink
	{
		return $this->shareLinkProvider->find($this->context->project($projectId), $shareLinkId)
			?? throw new RuntimeException(
				sprintf('Share link %d not found in project %d. Use list_share_links.', $shareLinkId, $projectId),
			);
	}

	private function toDto(ShareLink $link): ShareLinkDto
	{
		return $this->shareLinkProvider->toDto($link, $this->userContext->getBaseUrl());
	}
}
