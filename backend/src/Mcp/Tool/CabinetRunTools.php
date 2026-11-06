<?php

declare(strict_types=1);

namespace Izbik\Mcp\Tool;

use Izbik\Dto\EditResultDto;
use Izbik\Service\Editor\Drawing\CabinetRun;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/** A kitchen (or any) run of units laid along a wall in one go, instead of placing each item by hand. */
final readonly class CabinetRunTools
{
	private const array UnitsSchema = [
		'type' => 'array',
		'description' => 'the units in order from the wall start towards its end',
		'items' => [
			'type' => 'object',
			'required' => ['width'],
			'properties' => [
				'definitionId' => ['type' => 'integer', 'description' => 'furniture definition of a Custom unit (list_furniture_definitions)'],
				'kind' => ['type' => 'string', 'description' => 'item kind (default Custom)'],
				'modelId' => ['type' => 'integer', 'description' => 'a Model unit\'s model'],
				'name' => ['type' => 'string'],
				'width' => ['type' => 'integer', 'description' => 'mm along the wall'],
				'depth' => ['type' => 'integer', 'description' => 'mm out of the wall (default the definition\'s)'],
				'height' => ['type' => 'integer', 'description' => 'mm (default the definition\'s)'],
				'z' => ['type' => 'integer', 'description' => 'bottom elevation, mm (default 0; e.g. 1450 for wall cabinets)'],
				'materialId' => ['type' => 'integer'],
				'params' => ['type' => 'object', 'description' => 'as add_item: {values, materials}'],
				'gapBefore' => ['type' => 'integer', 'description' => 'mm left free before this unit (default 0)'],
			],
		],
	];

	private const array WorktopSchema = [
		'type' => ['object', 'null'],
		'description' => 'a worktop over the units standing on the floor (z 0): from the first to the last of them, on the highest',
		'properties' => [
			'definitionId' => ['type' => 'integer', 'description' => 'default the global Worktop definition'],
			'name' => ['type' => 'string'],
			'thickness' => ['type' => 'integer', 'description' => 'mm (default 38)'],
			'overhang' => ['type' => 'integer', 'description' => 'mm in front of the deepest unit (default 20)'],
			'materialId' => ['type' => 'integer'],
			'params' => ['type' => 'object', 'description' => 'as add_item: {values, materials}'],
		],
	];

	public function __construct(private ToolContext $context, private CabinetRun $run)
	{
	}

	/**
	 * @param int $wallId straight wall the units stand against (from get_floor)
	 * @param string $side "Left" | "Right" of the wall walking from its start to its end: the face the units stand against, the room's side
	 * @param int $offset mm along the wall from its start (centre line) to the first unit's start
	 * @param list<array<string, mixed>> $units
	 * @param array<string, mixed>|null $worktop
	 * @param string|null $groupName the run's group (default "Cabinet run")
	 * @param int|null $roomId room of every unit
	 */
	#[McpTool(
		name: 'add_cabinet_run',
		description: 'Lay a run of units (base, wall or tall cabinets, appliances, any items) along one face of a straight wall as '
			. 'one change (one undo step, listed in `batch`): each unit\'s back on the face, its front turned away from the wall, '
			. 'one after another from `offset`; wall cabinets in the same call with their own `z` (or call again for the upper '
			. 'row with the same offset); optionally a worktop over the floor-standing units. All go into a new group. Corners: '
			. 'two runs on the two walls, the first ending (or the second starting) where the other run\'s depth leaves room, '
			. 'or a blind-corner unit.',
	)]
	public function addCabinetRun(
		int $projectId,
		int $floorId,
		int $wallId,
		string $side,
		int $offset,
		#[Schema(definition: self::UnitsSchema)]
		array $units,
		#[Schema(definition: self::WorktopSchema)]
		?array $worktop = null,
		?string $groupName = null,
		?int $roomId = null,
	): EditResultDto {
		return $this->run->lay(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			$wallId,
			$side,
			$offset,
			$units,
			$worktop,
			$groupName ?? 'Cabinet run',
			$roomId,
		);
	}
}
