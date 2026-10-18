<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\FurnitureDefinitionDto;
use FlatDesigner\Dto\FurnitureDefinitionInputDto;
use FlatDesigner\Dto\FurnitureValidationDto;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Service\Furniture\DefinitionEvaluator;
use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Furniture\DefinitionValidator;
use FlatDesigner\Service\Furniture\FurnitureDefinitionProvider;
use FlatDesigner\Service\Json\JsonDecoder;
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
		. 'Keys: "format": 1; "size": {width, depth, height} of a new item; "parameters": list of {key, label?, type: "int" '
		. '(default, min?, max?, unit?) | "bool" (default) | "select" (default, options: [strings])}; "derived": {name: '
		. 'expression} evaluated in order; "materialSlots": list of {key, label?, default?: material code from list_materials} '
		. '(an item fills them with params.materials, the first slot defaults to the item material); "parts": list of {type, '
		. 'name?, material?: slot key, when?: boolean expression, repeat?: {count: expression, as?: index name (default i)}, '
		. 'x, y, z (min corner), w, h, d (size, must come out > 0) — numbers or expressions — plus the options of the type}. '
		. 'Part types and their options: "board" (grain: "vertical" | "horizontal", edges: list of sides "left", "right", '
		. '"bottom", "top", "back", "front" that get edge banding); "front" (a door or drawer leaf with a bar handle: kind: '
		. '"door" | "drawer", handle: "horizontal" | "vertical" | "none", handleSide: "left" | "right", grain); "cylinder" (rod '
		. 'or round leg along axis: "x" | "y" | "z", default y; diameter = the box\'s smaller side across); "taperedLeg" '
		. '(narrows to bottomSize mm at the floor); "glass"; "led" (color "#rrggbb", intensity). '
		. 'Expressions: numbers, the variables W, D, H (item size), the parameters, derived values and the repeat index; '
		. '+ - * / %, comparisons, == != (same types), && || !, a ? b : c, \'strings\' (for select values), functions min, '
		. 'max, round (half away from zero), floor, ceil, abs, clamp(x, lo, hi). No implicit conversions. Lengths round to '
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
	) {
	}

	/**
	 * @param int|null $projectId with a project: what its items may use (global, the project's own, the project owner's
	 *     library); without: global ones and your library
	 * @return list<array<string, mixed>>
	 */
	#[McpTool(
		name: 'list_furniture_definitions',
		description: 'List furniture definitions (for Custom items) with their scope, default size and parameters; '
			. 'get_furniture_definition shows the parts.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listFurnitureDefinitions(?int $projectId = null): array
	{
		$user = $this->context->user();
		$project = $projectId === null ? null : $this->context->project($projectId);

		return array_map(static function (FurnitureDefinition $definition): array {
			$body = JsonDecoder::decodeObject($definition->definition);

			return [
				'id' => $definition->id,
				'name' => $definition->name,
				'description' => $definition->description,
				'scope' => $definition->scope()->value,
				'userName' => $definition->user?->name,
				'projectId' => $definition->project?->id,
				'size' => $body['size'] ?? null,
				'parameters' => $body['parameters'] ?? [],
				'updatedAt' => $definition->updatedAt->format(DATE_ATOM),
			];
		}, $this->definitionProvider->listVisible($user, $project));
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
			scope: self::scope($scope ?? FurnitureScopeEnum::User->value),
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
		$copy = $this->definitionProvider->copy($user, $this->find($definitionId), self::scope($scope), $projectId);

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

	private function find(int $definitionId): FurnitureDefinition
	{
		return $this->definitionProvider->findReadable($this->context->user(), $definitionId)
			?? throw new RuntimeException(
				sprintf('Furniture definition %d not found. Use list_furniture_definitions to see the available ones.', $definitionId),
			);
	}

	private static function scope(string $scope): FurnitureScopeEnum
	{
		return FurnitureScopeEnum::tryFrom($scope)
			?? throw new RuntimeException('Scope must be "user", "project" or "global".');
	}

	/** @param array<mixed> $size validated: whole mm */
	private static function int(array $size, string $key): int
	{
		$value = $size[$key] ?? null;

		return is_int($value) ? $value : 1;
	}
}
