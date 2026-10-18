<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Service\Editor\Element\MaterialResolver;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Furniture\DefinitionEvaluator;
use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Json\JsonDecoder;

/**
 * The definition and `params` of a `Custom` item (see ItemKindCatalog): `values` for the definition's parameters,
 * `materials` (slot key → material id) for its material slots, and the parts must come out of the definition at the
 * item's size. Other kinds have no definition.
 */
final readonly class CustomItemValidator
{
	private const array ParamKeys = ['values', 'materials'];

	public function __construct(private DefinitionEvaluator $evaluator, private MaterialResolver $materialResolver)
	{
	}

	/**
	 * @param array<string, mixed> $params
	 * @param bool $evaluate false when the definition, size and params are those the item already has: a later edit of
	 *     the definition (its parts or material slots) must not block moving or renaming items that no longer fit it
	 */
	public function validate(
		ItemKindEnum $kind,
		?FurnitureDefinition $definition,
		array $params,
		int $width,
		int $depth,
		int $height,
		bool $evaluate,
	): void {
		if ($kind !== ItemKindEnum::Custom) {
			if ($definition !== null) {
				throw EditException::invalid('Only a Custom item has a definitionId.');
			}

			return;
		}
		if ($definition === null) {
			throw EditException::invalid('A Custom item needs a definitionId.');
		}

		foreach (array_keys($params) as $key) {
			if (!in_array($key, self::ParamKeys, true)) {
				throw EditException::invalid(sprintf('Custom item params take only "values" and "materials", not "%s".', $key));
			}
		}
		if (!$evaluate) {
			return;
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
