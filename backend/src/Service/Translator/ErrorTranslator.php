<?php

declare(strict_types=1);

namespace Izbik\Service\Translator;

use Izbik\Model\Entity\Enum\LocaleEnum;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * The API's error messages in another language. The backend throws them in English (agents over MCP read them so);
 * `translations/errors.<locale>.json` maps each English message, or its `sprintf` format (`Wall %d is not on this
 * floor.`), to the translation with the same placeholders in the same order. A message matching no entry stays English,
 * as do the parts a format fills in, unless such a part is a message of its own.
 */
final class ErrorTranslator
{
	private const string Directory = __DIR__ . '/../../../translations';

	/** @var array<string, array{exact: array<string, string>, patterns: list<array{regex: string, format: string}>}> */
	private array $catalogues = [];

	public function __construct(private readonly string $directory = self::Directory)
	{
	}

	public function translate(string $message, LocaleEnum $locale, bool $parts = true): string
	{
		if ($locale === LocaleEnum::En || $message === '') {
			return $message;
		}
		$catalogue = $this->catalogue($locale);
		if (isset($catalogue['exact'][$message])) {
			return $catalogue['exact'][$message];
		}
		foreach ($catalogue['patterns'] as $pattern) {
			if (preg_match($pattern['regex'], $message, $matches) !== 1) {
				continue;
			}
			$values = array_slice($matches, 1);
			if ($parts) {
				$values = array_map(fn (string $value): string => $this->translate($value, $locale, parts: false), $values);
			}

			return vsprintf($pattern['format'], $values);
		}

		return $message;
	}

	/**
	 * The `sprintf` placeholders of a format, in order (`%%` included).
	 *
	 * @return list<string>
	 */
	public static function placeholders(string $format): array
	{
		preg_match_all('/%(?:%|[-+ 0#]*\d*(?:\.\d+)?[sdf])/', $format, $matches);

		return $matches[0];
	}

	/** @return array{exact: array<string, string>, patterns: list<array{regex: string, format: string}>} */
	private function catalogue(LocaleEnum $locale): array
	{
		if (isset($this->catalogues[$locale->value])) {
			return $this->catalogues[$locale->value];
		}
		$content = @file_get_contents($this->directory . '/errors.' . $locale->value . '.json');
		$decoded = $content === false ? null : json_decode($content, true);
		$exact = [];
		$patterns = [];
		foreach (is_array($decoded) ? $decoded : [] as $english => $translation) {
			if (!is_string($english) || !is_string($translation)) {
				continue;
			}
			$exact[$english] = $translation;
			if (!str_contains($english, '%')) {
				continue;
			}

			$patterns[] = [
				'regex' => self::regex($english),
				'format' => $translation,
				'literal' => strlen(
					preg_replace('/%\S/', '', $english) ?? '',
				)];
		}
		// The most specific format first, where a longer one's literal text covers a shorter one's.
		usort($patterns, static fn (array $a, array $b): int => $b['literal'] <=> $a['literal']);
		$this->catalogues[$locale->value] = [
			'exact' => $exact,
			'patterns' => array_map(static fn (array $p): array => ['regex' => $p['regex'], 'format' => $p['format']], $patterns),
		];

		return $this->catalogues[$locale->value];
	}

	/** A regex matching what `sprintf($format, …)` writes, capturing each value. */
	private static function regex(string $format): string
	{
		$parts = preg_split('/(%(?:%|[-+ 0#]*\d*(?:\.\d+)?[sdf]))/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);
		$regex = '';
		foreach ($parts === false ? [] : $parts as $part) {
			$regex .= match (true) {
				$part === '%%' => '%',
				preg_match('/^%.*d$/', $part) === 1 => '(-?\d+)',
				preg_match('/^%.*f$/', $part) === 1 => '(-?\d+(?:\.\d+)?)',
				preg_match('/^%.*s$/', $part) === 1 => '(.*?)',
				default => preg_quote($part, '/'),
			};
		}

		return '/^' . $regex . '$/su';
	}
}
