<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture;

use Izbik\Model\Entity\Enum\LocaleEnum;

/**
 * A definition's texts in other languages (the definition itself is English): `translations` maps a language other
 * than `en` to `{name, description, parameters, options, slots, parts}`, each optional. `parameters` names a parameter
 * by its key (a list's field by `list.field`), `options` a select's options by parameter and value, `slots` the
 * material slots by key and `parts` the parts by their English name (for the cut list). The evaluators do not read it;
 * the frontend shows the texts (`localizeDefinition()`), the cut list names the parts.
 */
final readonly class DefinitionTranslations
{
	private const array Keys = ['name', 'description', 'parameters', 'options', 'slots', 'parts'];

	private const int MaxText = 255;

	private const int MaxDescription = 2000;

	/** @param array<mixed> $definition */
	public static function check(array $definition): void
	{
		$translations = $definition['translations'] ?? null;
		if ($translations === null) {
			return;
		}
		if (!is_array($translations) || array_is_list($translations)) {
			throw DefinitionException::invalid('translations', 'Must be an object of language: texts.');
		}
		$languages = array_map(
			static fn (LocaleEnum $l): string => $l->value,
			array_filter(LocaleEnum::cases(), static fn (LocaleEnum $l): bool => $l !== LocaleEnum::En),
		);
		foreach ($translations as $language => $texts) {
			$path = 'translations.' . $language;
			if (!in_array($language, $languages, true)) {
				throw DefinitionException::invalid(
					$path,
					sprintf('The language must be one of %s (the definition itself is English).', implode(', ', $languages)),
				);
			}
			if (!is_array($texts) || array_is_list($texts) && $texts !== []) {
				throw DefinitionException::invalid($path, 'Must be an object {name, description, parameters, options, slots, parts}.');
			}
			foreach (array_keys($texts) as $key) {
				if (!in_array($key, self::Keys, true)) {
					throw DefinitionException::invalid($path . '.' . $key, sprintf('Unknown key; known: %s.', implode(', ', self::Keys)));
				}
			}
			self::checkText($texts['name'] ?? null, $path . '.name', self::MaxText);
			self::checkText($texts['description'] ?? null, $path . '.description', self::MaxDescription);
			self::checkTexts($texts['parameters'] ?? null, $path . '.parameters', DefinitionTextKeys::parameters($definition));
			self::checkTexts($texts['slots'] ?? null, $path . '.slots', DefinitionTextKeys::slots($definition));
			self::checkTexts(
				$texts['parts'] ?? null,
				$path . '.parts',
				DefinitionTextKeys::parts(DefinitionValidator::list($definition, 'parts')),
			);
			self::checkOptions($texts['options'] ?? null, $path . '.options', $definition);
		}
	}

	/**
	 * The part's name in the language, else as it is.
	 *
	 * @param array<mixed> $definition
	 */
	public static function partName(array $definition, string $name, LocaleEnum $locale): string
	{
		$translations = $definition['translations'] ?? null;
		$texts = is_array($translations) ? ($translations[$locale->value] ?? null) : null;
		$parts = is_array($texts) ? ($texts['parts'] ?? null) : null;
		$translated = is_array($parts) ? ($parts[$name] ?? null) : null;

		return is_string($translated) && $translated !== '' ? $translated : $name;
	}

	private static function checkText(mixed $text, string $path, int $max): void
	{
		if ($text !== null && (!is_string($text) || trim($text) === '' || mb_strlen($text) > $max)) {
			throw DefinitionException::invalid($path, sprintf('Must be a text of 1 to %d characters.', $max));
		}
	}

	/** @param list<string> $known */
	private static function checkTexts(mixed $texts, string $path, array $known): void
	{
		if ($texts === null) {
			return;
		}
		if (!is_array($texts) || (array_is_list($texts) && $texts !== [])) {
			throw DefinitionException::invalid($path, 'Must be an object of key: text.');
		}
		foreach ($texts as $key => $text) {
			if (!in_array((string) $key, $known, true)) {
				throw DefinitionException::invalid($path . '.' . $key, 'The definition has no such key.');
			}
			self::checkText($text, $path . '.' . $key, self::MaxText);
		}
	}

	/** @param array<mixed> $definition */
	private static function checkOptions(mixed $options, string $path, array $definition): void
	{
		if ($options === null) {
			return;
		}
		if (!is_array($options) || (array_is_list($options) && $options !== [])) {
			throw DefinitionException::invalid($path, 'Must be an object of select parameter: {option: text}.');
		}
		$selects = DefinitionTextKeys::selects($definition);
		foreach ($options as $key => $texts) {
			if (!isset($selects[(string) $key])) {
				throw DefinitionException::invalid($path . '.' . $key, 'The definition has no select parameter of that key.');
			}
			self::checkTexts($texts, $path . '.' . $key, $selects[(string) $key]);
		}
	}
}
