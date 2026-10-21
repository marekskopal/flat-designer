<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\Element\State;

/**
 * Checks a furniture definition before it is saved: what `DefinitionEvaluator` reads (by evaluating it at its default
 * `size` with the parameter defaults), plus what only matters for storing and drawing it — no unknown keys, a `size`,
 * known part types with valid options, existing material codes as slot defaults, a known `category` and the size limit.
 */
final readonly class DefinitionValidator
{
	/** What the `definition` TEXT column holds. */
	public const int MaxBytes = 65535;

	private const array Keys = ['format', 'category', 'size', 'parameters', 'derived', 'materialSlots', 'parts'];

	private const array ParameterKeys = ['key', 'label', 'type', 'default'];

	/** Keys a parameter of the type takes besides `ParameterKeys`. */
	private const array ParameterTypeKeys = [
		'int' => ['min', 'max', 'unit'],
		'bool' => [],
		'select' => ['options'],
		'list' => ['fields', 'minItems', 'maxItems'],
		'image' => [],
	];

	private const array SlotKeys = ['key', 'label', 'default'];

	public function __construct(private DefinitionEvaluator $evaluator, private MaterialRepository $materialRepository)
	{
	}

	/**
	 * The definition as it will be stored, with its parts at the default size and parameter values; throws
	 * `DefinitionException` for the first problem found.
	 *
	 * @param array<mixed> $definition
	 */
	public function validate(array $definition): ValidatedDefinition
	{
		$json = self::encode($definition);
		if (strlen($json) > self::MaxBytes) {
			throw new DefinitionException(DefinitionErrorEnum::Limit, 'definition', sprintf('At most %d bytes of JSON.', self::MaxBytes));
		}
		self::checkKeys($definition, self::Keys, '');
		self::checkCategory($definition['category'] ?? null);
		[$width, $depth, $height] = self::size($definition['size'] ?? null);

		$parts = $this->evaluator->evaluate($definition, $width, $depth, $height);

		// The evaluator has checked the shape of these lists.
		foreach (self::list($definition, 'parameters') as $index => $parameter) {
			self::checkParameter($parameter, sprintf('parameters[%d]', $index));
		}
		$slots = self::list($definition, 'materialSlots');
		$codes = $slots === [] ? [] : array_map(
			static fn (Material $material): string => $material->code,
			$this->materialRepository->findAllOrdered(),
		);
		foreach ($slots as $index => $slot) {
			self::checkSlot($slot, sprintf('materialSlots[%d]', $index), $codes);
		}
		foreach (self::list($definition, 'parts') as $index => $part) {
			PartChecker::check($part, sprintf('parts[%d]', $index));
		}
		return new ValidatedDefinition($json, $parts);
	}

	/**
	 * The definition as stored (see `DefinitionEncoder`).
	 *
	 * @param array<mixed> $definition
	 */
	public static function encode(array $definition): string
	{
		return DefinitionEncoder::encode($definition);
	}

	/** @return array{int, int, int} */
	private static function size(mixed $size): array
	{
		if (!is_array($size)) {
			throw DefinitionException::invalid('size', 'Must be the default size {width, depth, height} in mm.');
		}
		self::checkKeys($size, ['width', 'depth', 'height'], 'size');

		$mm = [];
		foreach (['width', 'depth', 'height'] as $key) {
			$value = $size[$key] ?? null;
			if (!is_int($value) || $value < 1 || $value > State::MaxSize) {
				throw DefinitionException::invalid('size.' . $key, sprintf('Must be whole mm from 1 to %d.', State::MaxSize));
			}
			$mm[] = $value;
		}

		return [$mm[0], $mm[1], $mm[2]];
	}

	private static function checkCategory(mixed $category): void
	{
		if ($category !== null && (!is_string($category) || DefinitionCategoryEnum::tryFrom($category) === null)) {
			throw DefinitionException::invalid('category', sprintf('Must be one of %s.', implode(', ', DefinitionCategoryEnum::values())));
		}
	}

	/** @param array<mixed> $parameter a parameter, or with `$field` a field of a list parameter */
	private static function checkParameter(array $parameter, string $path, bool $field = false): void
	{
		$type = is_string($parameter['type'] ?? null) ? $parameter['type'] : '';
		$known = [...self::ParameterKeys, ...self::ParameterTypeKeys[$type] ?? [], ...$field ? ['optional'] : []];
		self::checkKeys($parameter, $known, $path);
		self::checkLabel($parameter, 'label', $path);
		self::checkLabel($parameter, 'unit', $path);
		foreach (self::list($parameter, 'fields') as $index => $definition) {
			self::checkParameter($definition, sprintf('%s.fields[%d]', $path, $index), true);
		}
	}

	/**
	 * @param array<mixed> $slot
	 * @param list<string> $codes of all materials
	 */
	private static function checkSlot(array $slot, string $path, array $codes): void
	{
		self::checkKeys($slot, self::SlotKeys, $path);
		self::checkLabel($slot, 'label', $path);
		$default = $slot['default'] ?? null;
		if ($default !== null && !in_array($default, $codes, true)) {
			throw DefinitionException::invalid($path . '.default', 'Must be the code of a material (see the material list).');
		}
	}

	/**
	 * @param array<mixed> $object
	 * @param list<string> $known
	 */
	private static function checkKeys(array $object, array $known, string $path): void
	{
		foreach (array_keys($object) as $key) {
			if (!in_array($key, $known, true)) {
				throw DefinitionException::invalid(
					ltrim(sprintf('%s.%s', $path, $key), '.'),
					sprintf('Unknown key; known: %s.', implode(', ', $known)),
				);
			}
		}
	}

	/** @param array<mixed> $object */
	public static function checkLabel(array $object, string $key, string $path): void
	{
		$value = $object[$key] ?? null;
		if ($value !== null && (!is_string($value) || mb_strlen($value) > 255)) {
			throw DefinitionException::invalid(sprintf('%s.%s', $path, $key), 'Must be a string of at most 255 characters.');
		}
	}

	/**
	 * @param array<mixed> $definition
	 * @return list<array<mixed>>
	 */
	public static function list(array $definition, string $key): array
	{
		$list = $definition[$key] ?? [];

		return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
	}
}
