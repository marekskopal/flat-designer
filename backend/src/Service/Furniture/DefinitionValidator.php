<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Editor\Element\State;
use stdClass;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Checks a furniture definition before it is saved: what `DefinitionEvaluator` reads (by evaluating it at its default
 * `size` with the parameter defaults), plus what only matters for storing and drawing it — no unknown keys, a `size`,
 * known part types with valid options, existing material codes as slot defaults and the size limit.
 */
final readonly class DefinitionValidator
{
	/** What the `definition` TEXT column holds. */
	public const int MaxBytes = 65535;

	private const array Keys = ['format', 'size', 'parameters', 'derived', 'materialSlots', 'parts'];

	private const array ParameterKeys = ['key', 'label', 'type', 'default'];

	/** Keys a parameter of the type takes besides `ParameterKeys`. */
	private const array ParameterTypeKeys = ['int' => ['min', 'max', 'unit'], 'bool' => [], 'select' => ['options']];

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
			self::checkPart($part, sprintf('parts[%d]', $index));
		}

		return new ValidatedDefinition($json, $parts);
	}

	/**
	 * The definition as stored: JSON with the objects kept objects even when empty (PHP decodes `{}` as `[]`). Only the
	 * objects of format 1 are known here; an option holding an object would need adding (the evaluators read `[]` as an
	 * empty object either way).
	 *
	 * @param array<mixed> $definition
	 */
	public static function encode(array $definition): string
	{
		$object = self::object($definition);
		foreach (['size', 'derived'] as $key) {
			if (is_array($definition[$key] ?? null)) {
				$object->{$key} = self::object($definition[$key]);
			}
		}
		foreach (['parameters', 'materialSlots', 'parts'] as $key) {
			if (is_array($definition[$key] ?? null)) {
				$object->{$key} = array_map(self::entry(...), $definition[$key]);
			}
		}

		return json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private static function entry(mixed $entry): mixed
	{
		if (!is_array($entry)) {
			return $entry;
		}
		$object = self::object($entry);
		if (is_array($entry['repeat'] ?? null)) {
			$object->repeat = self::object($entry['repeat']);
		}

		return $object;
	}

	/** @param array<mixed> $array */
	private static function object(array $array): stdClass
	{
		$object = new stdClass();
		foreach ($array as $key => $value) {
			$object->{$key} = $value;
		}

		return $object;
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

	/** @param array<mixed> $parameter */
	private static function checkParameter(array $parameter, string $path): void
	{
		$type = is_string($parameter['type'] ?? null) ? $parameter['type'] : '';
		self::checkKeys($parameter, [...self::ParameterKeys, ...self::ParameterTypeKeys[$type] ?? []], $path);
		self::checkLabel($parameter, 'label', $path);
		self::checkLabel($parameter, 'unit', $path);
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

	/** @param array<mixed> $part */
	private static function checkPart(array $part, string $path): void
	{
		$type = PartTypeEnum::tryFrom(is_string($part['type'] ?? null) ? $part['type'] : '');
		if ($type === null) {
			throw DefinitionException::invalid(
				$path . '.type',
				sprintf('Unknown part type; one of "%s".', implode('", "', PartTypeEnum::values())),
			);
		}

		self::checkLabel($part, 'name', $path);
		foreach ($part as $key => $value) {
			$key = (string) $key;
			if (in_array($key, PartEntry::Keys, true)) {
				continue;
			}
			$error = $type->checkOption($key, $value);
			if ($error !== null) {
				throw DefinitionException::invalid(sprintf('%s.%s', $path, $key), $error);
			}
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
	private static function checkLabel(array $object, string $key, string $path): void
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
	private static function list(array $definition, string $key): array
	{
		$list = $definition[$key] ?? [];

		return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
	}
}
