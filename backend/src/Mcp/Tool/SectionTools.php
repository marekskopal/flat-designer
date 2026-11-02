<?php

declare(strict_types=1);

namespace Izbik\Mcp\Tool;

use Izbik\Dto\EditResultDto;
use Izbik\Model\Entity\Enum\ElementTypeEnum;
use Izbik\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Section lines: cuts through the building, from which the viewer draws the building sections. */
final readonly class SectionTools
{
	private const string Section = 'A section line is drawn on a floor\'s plan; the viewer\'s export draws the building section along it '
		. 'through that floor and the floors below (walls, slabs, roofs, stairs and the ground cut and filled, what lies beyond in '
		. 'elevation, level marks), looking to the LEFT of start → end (swap the ends to look the other way). Name it with a letter '
		. '("A" gives "Section A–A").';

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param int|null $startX mm (plan coordinates); without the four ends, the line runs west to east through the middle of the floor's walls, looking north
	 * @param int|null $startY mm
	 * @param int|null $endX mm, at least 500 mm from the start
	 * @param int|null $endY mm
	 * @param string|null $name e.g. "A" (default: the next free letter on the floor)
	 * @param int|null $depth mm drawn beyond the cut, 100–100000 (default: everything)
	 */
	#[McpTool(name: 'add_section_line', description: 'Add a section line, a cut through the building. ' . self::Section)]
	public function addSectionLine(
		int $projectId,
		int $floorId,
		?int $startX = null,
		?int $startY = null,
		?int $endX = null,
		?int $endY = null,
		?string $name = null,
		?int $depth = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::SectionLine,
			ToolContext::fields(
				['startX' => $startX, 'startY' => $startY, 'endX' => $endX, 'endY' => $endY, 'name' => $name, 'depth' => $depth],
			),
		);
	}

	/**
	 * @param int|null $depth mm drawn beyond the cut, 100–100000
	 * @param list<string>|null $clear fields to set to none: "depth" (everything beyond the cut)
	 */
	#[McpTool(name: 'update_section_line', description: 'Change a section line. Only the given fields change.')]
	public function updateSectionLine(
		int $projectId,
		int $floorId,
		int $sectionId,
		?string $name = null,
		?int $startX = null,
		?int $startY = null,
		?int $endX = null,
		?int $endY = null,
		?int $depth = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::SectionLine,
			$sectionId,
			ToolContext::fields(
				['name' => $name, 'startX' => $startX, 'startY' => $startY, 'endX' => $endX, 'endY' => $endY, 'depth' => $depth],
				$clear,
				['depth'],
			),
		);
	}

	#[McpTool(
		name: 'delete_section_line',
		description: 'Delete a section line (can be undone).',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteSectionLine(int $projectId, int $floorId, int $sectionId): EditResultDto
	{
		return $this->editor->delete(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::SectionLine,
			$sectionId,
		);
	}
}
