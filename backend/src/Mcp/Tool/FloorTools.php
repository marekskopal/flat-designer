<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\ElectricalScheduleDto;
use FlatDesigner\Dto\RoomScheduleDto;
use FlatDesigner\Service\Editor\Catalog\ItemKindCatalog;
use FlatDesigner\Service\Provider\SceneProvider;
use FlatDesigner\Service\Schedule\ElectricalScheduleService;
use FlatDesigner\Service\Schedule\RoomScheduleService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class FloorTools
{
	public function __construct(
		private ToolContext $context,
		private SceneProvider $sceneProvider,
		private ItemKindCatalog $catalog,
		private RoomScheduleService $roomScheduleService,
		private ElectricalScheduleService $electricalScheduleService,
	) {
	}

	/** @return array<string, mixed> */
	#[McpTool(
		name: 'get_floor',
		description: 'Everything on one floor: rooms (polygon outline, area m², ceiling height, floor material, wall finishes), '
			. 'walls (centre line, thickness, height, type; leftFinishes / rightFinishes are a face\'s own finish bands in place of the room\'s; upToRoof rises to the roof) with their openings (doors/windows: offset from wall start, width, '
			. 'height, sill), items (kind, footprint centre x/y, elevation z, size, rotation, room, group, material, params), '
			. 'groups (items forming one piece of furniture, see create_group; a group with variantSetId is a variant) and '
			. 'variantSets (alternatives of which activeGroupId is shown; hiddenItemIds lists the items of the other variants) and '
			. 'definitions (the furniture definitions of the Custom items, see get_furniture_definition) and underlay (the '
			. 'plan image under the top view, see set_floor_underlay) and models (the 3D models of the Model items, see list_models) and stairs (from this floor '
			. 'up, climbing stairRise mm, see add_stair) and stairsBelow (the floor below\'s, cutting their voids through this one) and '
			. 'roofs (over this floor, with their windows, see add_roof). '
			. 'All in mm. Material ids refer to list_materials.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getFloor(int $projectId, int $floorId): array
	{
		$scene = $this->sceneProvider->getScene($this->context->floor($projectId, $floorId));

		return [
			'floor' => $scene->floor,
			'revision' => $scene->revision,
			'rooms' => $scene->rooms,
			'walls' => $scene->walls,
			'items' => $scene->items,
			'groups' => $scene->groups,
			'variantSets' => $scene->variantSets,
			'hiddenItemIds' => $scene->hiddenItemIds(),
			'definitions' => $scene->definitions,
			'underlay' => $scene->underlay,
			'models' => $scene->models,
			'stairs' => $scene->stairs,
			'stairRise' => $scene->stairRise,
			'stairsBelow' => $scene->stairsBelow,
			'roofs' => $scene->roofs,
		];
	}

	#[McpTool(
		name: 'get_room_schedule',
		description: 'Room schedule of a project, or of one floor with floorId: per room the net floor area (m², inside the '
			. 'wall faces), perimeter (m), clear height (mm, null for open-air rooms) and volume (m³), with indoor and outdoor '
			. '(terrace, garden) totals per floor and for the whole project. A room under a roof has minClearHeight (mm, at '
			. 'the eaves; clearHeight is then the highest), a volume under the slope and headroomArea (m² with at least '
			. '1.2 m of clear height); both are null for other rooms.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getRoomSchedule(int $projectId, ?int $floorId = null): RoomScheduleDto
	{
		return $floorId === null
			? $this->roomScheduleService->forProject($this->context->project($projectId))
			: $this->roomScheduleService->forFloor($this->context->floor($projectId, $floorId));
	}

	#[McpTool(
		name: 'get_electrical_schedule',
		description: 'Electrical schedule of a project, or of one floor with floorId, for the electrician: per room the lights, '
			. 'sockets, switches, data sockets and plumbing points (Custom items whose furniture definition has a category; '
			. 'shown variants only), each row the points of one definition with the same parameter values (e.g. gangs) at the '
			. 'same elevation z (mm, bottom edge), with the counts per category and the sockets\' outlets per room, floor and '
			. 'project. An item belongs to its roomId, otherwise to the room holding its footprint centre; points outside every '
			. 'room are listed under a room with id null.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getElectricalSchedule(int $projectId, ?int $floorId = null): ElectricalScheduleDto
	{
		return $floorId === null
			? $this->electricalScheduleService->forProject($this->context->project($projectId))
			: $this->electricalScheduleService->forFloor($this->context->floor($projectId, $floorId));
	}

	/** @return list<array{kind: string, description: string, width: int, depth: int, height: int, z: int, params: string, structural: bool}> */
	#[McpTool(
		name: 'list_item_kinds',
		description: 'Item kinds you can add, each with a description, the default size (width × depth × height) and elevation z '
			. 'a new item gets, and the params it understands. Custom-made furniture (cabinets, worktops, wardrobes, chairs, '
			. 'tables) is not a kind: add a furniture definition (kind "Custom", see list_furniture_definitions).',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listItemKinds(): array
	{
		return $this->catalog->all();
	}
}
