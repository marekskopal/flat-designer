<?php

declare(strict_types=1);

namespace Izbik\Mcp\Tool;

use Izbik\Dto\CutListDto;
use Izbik\Dto\FurnitureDefinitionDto;
use Izbik\Dto\FurnitureDefinitionInputDto;
use Izbik\Dto\FurnitureValidationDto;
use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\FurnitureDefinition;
use Izbik\Service\Furniture\CutList\CutListService;
use Izbik\Service\Furniture\DefinitionEvaluator;
use Izbik\Service\Furniture\DefinitionException;
use Izbik\Service\Furniture\DefinitionValidator;
use Izbik\Service\Furniture\FurnitureDefinitionProvider;
use Izbik\Service\Json\JsonDecoder;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use RuntimeException;
use const DATE_ATOM;

/**
 * Furniture definitions: custom furniture as data (parameters and parts), drawn by `Custom` items (add_item with
 * definitionId). Global ones are managed by admins; a user has a library; a project may have its own.
 */
final readonly class FurnitureTools
{
	/** The format of a definition (format 1), for agents writing one; FurnitureToolsTest checks it names every part type. */
	public const string Format = 'A furniture definition, JSON format 1. Lengths are mm; the parts\' frame has its origin at the item\'s '
		. 'left-bottom-back corner: x along the width (0..W), y up (0..H), z towards the front (0..D). '
		. 'Keys: "format": 1; "catalogue"?: "kitchen" | "storage" | "seating" | "tables" | "bedroom" | "bathroom" | "lighting" | '
		. '"electrical" | "decor" | "building" | "outdoor" | "roof" (where the editor\'s Add library files it; none: Other); "category"?: "light" | "socket" | "switch" | "data" | "plumbing" (what get_electrical_schedule '
		. 'counts its items as); "schedule"?: {count?: key of an int parameter multiplying the item, e.g. a socket\'s gangs or a data '
		. 'socket\'s ports; values?: [keys of the int/bool/select parameters the schedule lists, all without]} (with a category); '
		. '"translations"?: {"cs": {name?, description?, parameters?: {parameter key, or list.field: label}, options?: {select '
		. 'key: {option: label}}, slots?: {slot key: label}, parts?: {part name: name}}} (its texts for people in other languages; the '
		. 'definition itself is English); "size": {width, depth, height} of a new '
		. 'item; "parameters": list of {key, label?, type: "int" '
		. '(default, min?, max?, unit?) | "bool" (default) | "select" (default, options: [strings]) | "list" (fields: list of '
		. 'int/bool/select fields like parameters, each with default or optional: true, default: [items], minItems?, maxItems?; '
		. 'the value is a list of records, e.g. fronts [{type, height}]) | "image" (no default; the item\'s value is the id of an '
		. 'image file of its project, see list_project_files, or null)}; "derived": {name: '
		. 'expression} evaluated in order; "materialSlots": list of {key, label?, default?: material code from list_materials} '
		. '(an item fills them with params.materials; the first slot, and a slot without a default, take the item material); '
		. 'sides a parameter names "left" / "right" (an L worktop\'s returnSide, an island worktop\'s leftEnd / rightEnd, a '
		. 'handleSide) are seen from the item\'s front: left is its local x = 0, whatever the item\'s rotation; '
		. '"parts": list of {type, '
		. 'name?, material?: slot key (or {"expr": …} giving one per copy), when?: boolean expression, repeat?: {count: expression, '
		. 'as?: index name (default i)} or '
		. '{over: list expression, as?: item name (default item; its index is <as>_index)}, x, y, z (min corner), w, h, d '
		. '(size, must come out > 0) — numbers or expressions — plus the options of the type, each a value or {"expr": '
		. '"…"} evaluated per copy}. '
		. 'Part types and their options: "board" (grain: "vertical" | "horizontal", edges: list of sides "left", "right", '
		. '"bottom", "top", "back", "front" that get edge banding, shade: 0–1 darker (0.3 for a plinth), backWidth: mm at the '
		. 'back with taper: "both" | "left" | "right", e.g. a chair seat or the top of a wardrobe with a slanted end); "front" (a door or drawer leaf with a bar handle: kind: '
		. '"door" | "drawer", style: "flat" (default) | "shaker" (a frame round a recessed panel) | "fluted" | "glass" (a frame round '
		. 'clear glass) | "reeded" (a frame round reeded glass), frame: frame width mm (0: the style\'s own, 70 shaker, 40 glass), '
		. 'frameMaterial: material slot of the frame (unfilled and without a default: the front\'s), handle: "horizontal" | '
		. '"vertical" (bars, handleLength mm, 0: by the front) | "knob" | "edge" (an edge pull on the top edge, the bottom one '
		. 'out of reach) | "gola" (a handleless channel) | "push" (push-to-open) | "none", handleSide: "left" | "right", '
		. 'handlePosition: "auto" (default: near the top within reach, else the bottom; a tall door from below reach height '
		. 'to well above it at reach height on its handle edge) | "top" | "bottom" (that edge whatever the height, e.g. the '
		. 'pull at the split of two doors; an edge pull then sits at a door\'s handle side) | "side" (at reach height on the '
		. 'handle edge), handleMaterial: material slot of the handle (unfilled and without a default: black; also the gola '
		. 'channel\'s colour), grain, edges; '
		. 'it opens in the viewer by opens: "swing" (default for a door: on its hinge edge "left" | "right", default the side '
		. 'away from handleSide; hingeType "concealed" (default: cup hinges, which carry the door forward and in as it opens, '
		. 'angle default 110, at most 155 for a wide-angle hinge) | "butt" (a plain hinge on the edge, angle default 105, at '
		. 'most 180); a front inside a swinging assembly may give its hingeType) | "slide" (default for a drawer: travel mm out, default 3/4 '
		. 'of its depth into the item) | "lift" (a flap turning up about its top edge, angle default 100) | "fold" (a bi-fold '
		. 'flap folding foldAt mm below its top, default and at most half way; the cut list lists its two panels) | "parallel" '
		. '(a flap rising travel mm upright, default its height + 10) | "none" (fixed); softClose: true damps its closing); "block" (a solid box not cut from sheet: stone, concrete, metal, a solid timber bar, fabric; grain, shade; the cut list counts it among the other parts); "cylinder" (rod '
		. 'or round leg along axis: "x" | "y" | "z", default y; diameter = the box\'s smaller side across; radius mm rounds the edges of its ends, e.g. a pouf); "taperedLeg" '
		. '(narrows to bottomSize mm at the floor); "glass"; "led" (color "#rrggbb", intensity); "appliance" (built-in oven or '
		. 'microwave front: kind: "oven" | "microwave"); "slidingDoor" (a framed leaf: profile: material slot of the frame, '
		. 'default aluminium, mirror: true for a mirror infill, profileWidth mm; place each leaf on its track yourself); '
		. '"cushion" (upholstery: seat or back cushion, mattress, duvet, pillow; its edges rounded by radius mm, default 40, 0 square, '
		. 'drawn with at most half its smallest side); "picture" (a print, poster or photo: the box with the image file given by '
		. 'image (usually {"expr": "<image parameter>"}) on its front face, cropped to fill it; plain without one); "light" (a '
		. 'light source at the box centre: kind "point" | "spot" (shines down within angle degrees, default 60), lumens (default '
		. '800), kelvin (colour temperature, default 2700), shadow: true to cast shadows (costly; the viewer allows only a few), '
		. 'bulb: "sphere" (a bare bulb filling the box) | "disc" (a downlight\'s lens on the box bottom) | "none" (inside a shade), '
		. 'lit only while the viewer\'s lights are on); "cable" (a pendant cable as thick as the box\'s smaller side across, from '
		. 'the box\'s bottom centre up to its top centre (the ceiling hook), then along path: points {x, z} (parts frame, e.g. '
		. '{"expr": "<list parameter>"}) to the ceiling rose (rose: its diameter, mm), sagging sag mm between them; ceiling: true (default) runs it on the '
		. 'real ceiling of the item\'s room, also a vaulted one, so set the item\'s roomId); every '
		. 'visible part may lean back by tilt degrees around tiltY / tiltZ (mm, default its bottom-back edge) and turn by turn '
		. 'degrees counter-clockwise seen from above around a vertical line at turnX / turnZ (mm, default its back-left edge), '
		. 'e.g. a slanted end panel; "assembly" (no part itself: '
		. 'its "parts" are placed in its box and see its size as AW, AH, AD; nests 4 levels deep, e.g. a front slot repeated '
		. 'over fronts with its leaves repeated by f.count; with opens "swing" | "slide" | "lift" | "parallel" (and hinge, hingeType, '
		. 'angle, travel, softClose as on a front) all its parts move as one leaf, e.g. a drawer: the front with its drawer box '
		. 'boards inside the carcass; no part inside it may open on its own: opens "none" at most). '
		. 'Expressions: numbers, the variables W, D, H (item size), the parameters, derived values and the repeat index; '
		. '+ - * / %, comparisons, == != (same types, or anything with null), && || !, a ? b : c, a ?? b (b when a is null), '
		. '\'strings\' (for select values), null, list[i] (from 0) and record.field (lists and records come from list '
		. 'parameters, not literals), functions min, max, round (half away from '
		. 'zero), floor, ceil, abs, clamp(x, lo, hi), len(list), sum(list), contains(list, value), field(list of records, '
		. '\'name\'), share(sizes, total, gap?) (each null size gets an equal share of what is left, e.g. front heights), '
		. 'offsets(sizes, gap?) (where each starts), hash(n, seed?) (a fixed pseudo-random number 0 ≤ x < 1 for whole numbers, '
		. 'e.g. book spines: w "24 + round(hash(i) * 12)", material {"expr": "hash(i, 1) < 0.5 ? \'red\' : \'blue\'"}), '
		. 'sqrt, hypot(a, b), sin, cos, tan (of degrees) and atan2(y, x) (in degrees), e.g. a slanted end panel\'s length '
		. '"hypot(D, W - backWidth)" and turn "atan2(W - backWidth, D)". No implicit '
		. 'conversions; null fails arithmetic. Lengths round to '
		. 'whole mm. Limits: 200 part entries, 500 parts, 100 repeats, 64 KB. Example part: {"type": "board", "name": "Shelf", '
		. '"repeat": {"count": "shelves"}, "x": "t", "y": "i * (H - t) / (shelves - 1)", "z": 0, "w": "W - 2*t", "h": "t", '
		. '"d": "D", "material": "carcass", "grain": "horizontal"}.';

	private const array DefinitionSchema = ['type' => 'object', 'description' => self::Format, 'additionalProperties' => true];

	private const array OptionalDefinitionSchema = [
		'type' => ['object', 'null'],
		'description' => 'replaces the whole definition; ' . self::Format,
		'additionalProperties' => true,
		'default' => null,
	];

	private const array ValuesSchema = [
		'type' => ['object', 'null'],
		'description' => 'parameter values to evaluate with (default: the parameter defaults), e.g. {"shelves": 6}',
		'additionalProperties' => true,
		'default' => null,
	];

	public function __construct(
		private ToolContext $context,
		private FurnitureDefinitionProvider $definitionProvider,
		private DefinitionValidator $validator,
		private DefinitionEvaluator $evaluator,
		private CutListService $cutListService,
	) {
	}

	/**
	 * @param int|null $projectId with a project: what its items may use (global, the project's own, the project owner's
	 *     library); without: global ones and your library
	 * @param string|null $catalogue only the definitions filed under it (kitchen, storage, seating, tables, bedroom, bathroom,
	 *     lighting, electrical, decor, building, outdoor, roof); null: all
	 * @return list<array<string, mixed>>
	 */
	#[McpTool(
		name: 'list_furniture_definitions',
		description: 'List furniture definitions (for Custom items) with their scope, catalogue, default size, parameters and '
			. 'material slots (the keys params.materials fills, with their default material codes); '
			. 'get_furniture_definition shows the parts.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listFurnitureDefinitions(?int $projectId = null, ?string $catalogue = null): array
	{
		$user = $this->context->user();
		$project = $projectId === null ? null : $this->context->project($projectId);

		$listed = array_map(static function (FurnitureDefinition $definition): array {
			$body = JsonDecoder::decodeObject($definition->definition);

			return [
				'id' => $definition->id,
				'name' => $definition->name,
				'description' => $definition->description,
				'scope' => $definition->scope()->value,
				'userName' => $definition->user?->name,
				'projectId' => $definition->project?->id,
				'catalogue' => is_string($body['catalogue'] ?? null) ? $body['catalogue'] : null,
				'size' => $body['size'] ?? null,
				'parameters' => $body['parameters'] ?? [],
				'materialSlots' => self::slots($body['materialSlots'] ?? null),
				'updatedAt' => $definition->updatedAt->format(DATE_ATOM),
			];
		}, $this->definitionProvider->listVisible($user, $project));

		return $catalogue === null
			? $listed
			: array_values(array_filter($listed, static fn (array $row): bool => $row['catalogue'] === $catalogue));
	}

	/** @return list<array{key: mixed, default: mixed}> a definition's material slots: key and default material code */
	private static function slots(mixed $slots): array
	{
		if (!is_array($slots)) {
			return [];
		}

		$listed = [];
		foreach ($slots as $slot) {
			$slot = is_array($slot) ? $slot : [];
			$listed[] = ['key' => $slot['key'] ?? null, 'default' => $slot['default'] ?? null];
		}

		return $listed;
	}

	#[McpTool(
		name: 'get_furniture_definition',
		description: 'One furniture definition with its whole JSON, whether you may change it and how many items use it.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getFurnitureDefinition(int $definitionId): FurnitureDefinitionDto
	{
		return $this->definitionProvider->toDto($this->context->user(), $this->find($definitionId), withUsage: true);
	}

	/**
	 * @param string $name e.g. "Bookcase with doors"
	 * @param array<string, mixed> $definition the definition JSON (see the schema)
	 * @param string|null $description one line on what it is
	 * @param string|null $scope "user" (your library, default), "project" (needs projectId) or "global" (admins)
	 * @param int|null $projectId the project of a project definition
	 */
	#[McpTool(
		name: 'create_furniture_definition',
		description: 'Create a furniture definition (validate_furniture_definition first to see its parts). Then add it to a '
			. 'floor with add_item(kind: "Custom", definitionId).',
	)]
	public function createFurnitureDefinition(
		string $name,
		#[Schema(definition: self::DefinitionSchema)]
		array $definition,
		?string $description = null,
		?string $scope = null,
		?int $projectId = null,
	): FurnitureDefinitionDto {
		$user = $this->context->user();
		$created = $this->definitionProvider->create($user, new FurnitureDefinitionInputDto(
			name: $name,
			description: $description ?? '',
			scope: ToolContext::scope($scope ?? FurnitureScopeEnum::User->value),
			projectId: $projectId,
			definition: $definition,
		));

		return $this->definitionProvider->toDto($user, $created);
	}

	/**
	 * @param string|null $name new name
	 * @param string|null $description new description
	 * @param array<string, mixed>|null $definition the new definition JSON (replaces it whole)
	 */
	#[McpTool(
		name: 'update_furniture_definition',
		description: 'Change a furniture definition; only the given fields change. Every item drawn from it changes with it, in '
			. 'all projects — see itemCount of get_furniture_definition.',
	)]
	public function updateFurnitureDefinition(
		int $definitionId,
		?string $name = null,
		?string $description = null,
		#[Schema(definition: self::OptionalDefinitionSchema)]
		?array $definition = null,
	): FurnitureDefinitionDto {
		$user = $this->context->user();
		$current = $this->find($definitionId);
		$updated = $this->definitionProvider->update($user, $current, new FurnitureDefinitionInputDto(
			name: $name ?? $current->name,
			description: $description ?? $current->description,
			scope: null,
			projectId: null,
			definition: $definition ?? JsonDecoder::decodeObject($current->definition),
		));

		return $this->definitionProvider->toDto($user, $updated);
	}

	#[McpTool(
		name: 'delete_furniture_definition',
		description: 'Delete a furniture definition: it is no longer offered, but items already drawn from it keep it.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	/** @return FurnitureDefinitionDto the deleted definition */
	public function deleteFurnitureDefinition(int $definitionId): FurnitureDefinitionDto
	{
		$user = $this->context->user();
		$definition = $this->find($definitionId);
		$this->definitionProvider->delete($user, $definition);

		return $this->definitionProvider->toDto($user, $definition);
	}

	/**
	 * @param string $scope "user" (into your library), "project" (needs projectId) or "global" (admins)
	 * @param int|null $projectId the target project of scope "project"
	 */
	#[McpTool(
		name: 'copy_furniture_definition',
		description: 'Copy a furniture definition elsewhere (e.g. a global one into a project to adapt it); the copy is independent.',
	)]
	public function copyFurnitureDefinition(int $definitionId, string $scope, ?int $projectId = null): FurnitureDefinitionDto
	{
		$user = $this->context->user();
		$copy = $this->definitionProvider->copy($user, $this->find($definitionId), ToolContext::scope($scope), $projectId);

		return $this->definitionProvider->toDto($user, $copy);
	}

	/**
	 * @param array<string, mixed> $definition the definition JSON to check
	 * @param int|null $width item width to evaluate at, mm (default: the definition's size)
	 * @param int|null $depth item depth, mm
	 * @param int|null $height item height, mm
	 * @param array<string, mixed>|null $values parameter values
	 */
	#[McpTool(
		name: 'validate_furniture_definition',
		description: 'Check a furniture definition without saving it: either valid with its parts (mm, in the parts\' frame) at '
			. 'the default size or the given one, or the first error with its path in the JSON (e.g. "parts[2].w").',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function validateFurnitureDefinition(
		#[Schema(definition: self::DefinitionSchema)]
		array $definition,
		?int $width = null,
		?int $depth = null,
		?int $height = null,
		#[Schema(definition: self::ValuesSchema)]
		?array $values = null,
	): FurnitureValidationDto {
		try {
			$parts = $this->validator->validate($definition)->parts;
			if ($width !== null || $depth !== null || $height !== null || $values !== null) {
				$size = is_array($definition['size'] ?? null) ? $definition['size'] : [];
				$parts = $this->evaluator->evaluate(
					$definition,
					$width ?? self::int($size, 'width'),
					$depth ?? self::int($size, 'depth'),
					$height ?? self::int($size, 'height'),
					$values ?? [],
				);
			}

			return FurnitureValidationDto::valid($parts);
		} catch (DefinitionException $e) {
			return FurnitureValidationDto::invalid($e);
		}
	}

	/** @param int|null $floorId one floor only (default: the whole project) */
	#[McpTool(
		name: 'get_cut_list',
		description: 'The cut list of the Custom furniture of a project or floor (shown variants only) for the carpenter: '
			. 'boards (board and front parts) grouped by material, thickness, length along the grain, width and banded edges '
			. 'with counts; other parts (legs, rods, glass, LEDs); board area (m²) and edge banding (m) per material; items '
			. 'whose definition does not work out.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getCutList(int $projectId, ?int $floorId = null): CutListDto
	{
		return $floorId === null
			? $this->cutListService->forProject($this->context->project($projectId))
			: $this->cutListService->forFloor($this->context->floor($projectId, $floorId));
	}

	private function find(int $definitionId): FurnitureDefinition
	{
		return $this->definitionProvider->findReadable($this->context->user(), $definitionId)
			?? throw new RuntimeException(
				sprintf('Furniture definition %d not found. Use list_furniture_definitions to see the available ones.', $definitionId),
			);
	}

	/** @param array<mixed> $size validated: whole mm */
	private static function int(array $size, string $key): int
	{
		$value = $size[$key] ?? null;

		return is_int($value) ? $value : 1;
	}
}
