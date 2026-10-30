<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Railings (FD-90): balustrades along a polyline. */
final readonly class RailingTools
{
	private const array Clearable = ['spacing', 'materialId', 'infillMaterialId'];

	private const string Railing = 'A railing runs along a polyline in plan: a void\'s or gallery\'s edge, a terrace, the top of a '
		. 'retaining wall (baseZ). Posts stand at its corners and at most postSpacing apart under the handrail, the style fills '
		. 'between them; the walkthrough does not pass it. A stair\'s own railing is the stair\'s (update_stair railing, railingStyle).';

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param list<list<int>> $points the railing's line as [[x, y], …] in mm (plan coordinates), 2 points at least; repeat the
	 *   first point last to close it round a void
	 * @param string|null $name e.g. "Gallery balustrade" (default "Railing")
	 * @param int|null $height mm from its base to the top of the handrail, 300–2000 (default 1000)
	 * @param int|null $baseZ mm its base stands above (+) or below (−) the floor's level, e.g. on a wall's top (default 0)
	 * @param string|null $style "Balusters" (vertical bars, default) | "Rods" (horizontal bars) | "Glass" (a glass pane) | "Panel"
	 *   (a solid panel) | "Cable" (horizontal steel cables)
	 * @param int|null $spacing mm, the clear gap between balusters, or the pitch of rods and cables, 20–500 (default 100, rods 120)
	 * @param int|null $postSpacing mm, the farthest apart posts stand, 300–3000 (default 1200)
	 * @param string|null $handrail "Round" (default) | "Square" | "Flat" (a cap board) | "None"
	 * @param int|null $materialId posts, handrail, balusters, rods, from list_materials (default brushed steel)
	 * @param int|null $infillMaterialId a glass pane's or panel's, from list_materials (default clear glass, or the railing's material)
	 */
	#[McpTool(name: 'add_railing', description: 'Add a railing. ' . self::Railing)]
	public function addRailing(
		int $projectId,
		int $floorId,
		array $points,
		?string $name = null,
		?int $height = null,
		?int $baseZ = null,
		?string $style = null,
		?int $spacing = null,
		?int $postSpacing = null,
		?string $handrail = null,
		?int $materialId = null,
		?int $infillMaterialId = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Railing,
			ToolContext::fields([
				'points' => $points,
				'name' => $name,
				'height' => $height,
				'baseZ' => $baseZ,
				'style' => $style,
				'spacing' => $spacing,
				'postSpacing' => $postSpacing,
				'handrail' => $handrail,
				'materialId' => $materialId,
				'infillMaterialId' => $infillMaterialId,
			]),
		);
	}

	/**
	 * @param list<list<int>>|null $points new line as [[x, y], …] in mm
	 * @param string|null $style "Balusters" | "Rods" | "Glass" | "Panel" | "Cable"
	 * @param string|null $handrail "Round" | "Square" | "Flat" | "None"
	 * @param list<string>|null $clear fields to set to none: "spacing" (the style's), "materialId", "infillMaterialId"
	 */
	#[McpTool(name: 'update_railing', description: 'Change a railing. Only the given fields change.')]
	public function updateRailing(
		int $projectId,
		int $floorId,
		int $railingId,
		?string $name = null,
		?array $points = null,
		?int $height = null,
		?int $baseZ = null,
		?string $style = null,
		?int $spacing = null,
		?int $postSpacing = null,
		?string $handrail = null,
		?int $materialId = null,
		?int $infillMaterialId = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Railing,
			$railingId,
			ToolContext::fields(
				[
					'name' => $name,
					'points' => $points,
					'height' => $height,
					'baseZ' => $baseZ,
					'style' => $style,
					'spacing' => $spacing,
					'postSpacing' => $postSpacing,
					'handrail' => $handrail,
					'materialId' => $materialId,
					'infillMaterialId' => $infillMaterialId,
				],
				$clear,
				self::Clearable,
			),
		);
	}

	#[McpTool(
		name: 'delete_railing',
		description: 'Delete a railing (can be undone).',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteRailing(int $projectId, int $floorId, int $railingId): EditResultDto
	{
		return $this->editor->delete(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Railing,
			$railingId,
		);
	}
}
