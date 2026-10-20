<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Service\Editor\Element\MaterialResolver;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Furniture\DefinitionEvaluator;
use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Furniture\ImageParameters;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Project\ProjectFileProvider;

/**
 * The definition and `params` of a `Custom` item (see ItemKindCatalog): `values` for the definition's parameters,
 * `materials` (slot key → material id) for its material slots, and the parts must come out of the definition at the
 * item's size; an `image` parameter's value must be an image file of the item's project. Other kinds have no definition.
 */
final readonly class CustomItemValidator
{
	private const array ParamKeys = ['values', 'materials'];

	public function __construct(
		private DefinitionEvaluator $evaluator,
		private MaterialResolver $materialResolver,
		private ProjectFileProvider $fileProvider,
	) {
	}

	/**
	 * @param array<string, mixed> $params
	 * @param bool $evaluate false when the definition, size and params are those the item already has: a later edit of
	 *     the definition (its parts or material slots) must not block moving or renaming items that no longer fit it
	 * @param mixed $currentValues the `params.values` the item has now: an image file it already shows is not checked
	 *     again (deleted since, the picture is plain), only a newly chosen one
	 */
	public function validate(
		Project $project,
		ItemKindEnum $kind,
		?FurnitureDefinition $definition,
		array $params,
		int $width,
		int $depth,
		int $height,
		bool $evaluate,
		mixed $currentValues = null,
	): void {
		if ($kind !== ItemKindEnum::Custom) {
			if ($definition !== null) {
				throw EditException::invalid('Only a Custom item has a definitionId.');
			}

			return;
		}
		foreach (array_keys($params) as $key) {
			if (!in_array($key, self::ParamKeys, true)) {
				throw EditException::invalid(sprintf('Custom item params take only "values" and "materials", not "%s".', $key));
			}
		}
		if (!$evaluate) {
			// Also when the definition is gone (hard-deleted with its owner): the item can still be moved or renamed.
			return;
		}
		if ($definition === null) {
			throw EditException::invalid('A Custom item needs a definitionId.');
		}

		$body = JsonDecoder::decodeObject($definition->definition);
		$this->checkMaterials($params['materials'] ?? [], $body['materialSlots'] ?? []);
		$values = $params['values'] ?? [];
		if (!is_array($values)) {
			throw EditException::invalid('Custom item "values" must be an object of parameter values.');
		}

		try {
			$this->evaluator->evaluate($body, $width, $depth, $height, $values);
		} catch (DefinitionException $e) {
			throw new EditException(sprintf('Furniture "%s" does not work out: %s', $definition->name, $e->getMessage()), 422, $e);
		}

		$shown = ImageParameters::fileIds($body, $currentValues);
		foreach (array_diff(ImageParameters::fileIds($body, $values), $shown) as $fileId) {
			$file = $this->fileProvider->find($project, $fileId);
			if ($file === null || !$this->fileProvider->isImage($file)) {
				throw EditException::invalid(sprintf('File %d is not an image of this project (see list_project_files).', $fileId));
			}
		}
	}

	private function checkMaterials(mixed $materials, mixed $slots): void
	{
		if (!is_array($materials) || $materials !== [] && array_is_list($materials)) {
			throw EditException::invalid('Custom item "materials" must be an object of material slot key: material id.');
		}

		$keys = [];
		foreach (is_array($slots) ? $slots : [] as $slot) {
			$keys[] = is_array($slot) ? ($slot['key'] ?? null) : null;
		}
		foreach ($materials as $key => $materialId) {
			if (!in_array($key, $keys, true)) {
				throw EditException::invalid(sprintf('The furniture has no material slot "%s".', $key));
			}
			if (!is_int($materialId)) {
				throw EditException::invalid(sprintf('Material of slot "%s" must be a material id.', $key));
			}
			$this->materialResolver->resolve($materialId);
		}
	}
}
