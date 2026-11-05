<?php

declare(strict_types=1);

namespace Izbik\Service\Translator;

use Izbik\Model\Entity\Enum\LocaleEnum;

/**
 * The texts the backend writes for a person (the account mail, default names), from `translations/<locale>.json`:
 * nested keys read dotted (`mail.passwordReset.subject`), `{name}` placeholders. A key missing in a language falls back
 * to English, one missing in English is returned as it is.
 */
final class Translator
{
	private const string Directory = __DIR__ . '/../../../translations';

	/** @var array<string, array<string, mixed>> */
	private array $loaded = [];

	public function __construct(private readonly string $directory = self::Directory)
	{
	}

	/** @param array<string, string|int> $params */
	public function translate(string $key, LocaleEnum $locale, array $params = []): string
	{
		$text = $this->lookup($key, $locale) ?? ($locale === LocaleEnum::En ? null : $this->lookup($key, LocaleEnum::En)) ?? $key;

		return strtr($text, array_combine(
			array_map(static fn (string $name): string => '{' . $name . '}', array_keys($params)),
			array_map(static fn (string|int $value): string => (string) $value, array_values($params)),
		));
	}

	private function lookup(string $key, LocaleEnum $locale): ?string
	{
		$value = $this->load($locale);
		foreach (explode('.', $key) as $part) {
			if (!is_array($value) || !array_key_exists($part, $value)) {
				return null;
			}
			$value = $value[$part];
		}

		return is_string($value) ? $value : null;
	}

	/** @return array<string, mixed> */
	private function load(LocaleEnum $locale): array
	{
		if (!isset($this->loaded[$locale->value])) {
			$content = @file_get_contents($this->directory . '/' . $locale->value . '.json');
			$decoded = $content === false ? null : json_decode($content, true);
			/** @var array<string, mixed> $texts */
			$texts = is_array($decoded) ? $decoded : [];
			$this->loaded[$locale->value] = $texts;
		}

		return $this->loaded[$locale->value];
	}
}
