<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Translator;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Service\Translator\ErrorTranslator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every message the API may answer with (the literal of a `new …Exception(…)`, `new …Response(…)`, a static factory,
 * a sentence constant or an import's `warnings[]` in `src/`, MCP aside) has a translation in each
 * `translations/errors.<locale>.json`, with the same placeholders, and every entry there is still such a message.
 */
#[CoversNothing]
final class ErrorCatalogueTest extends TestCase
{
	/** Messages a user never reads: start-up faults, storage internals, programming errors (all 5xx). */
	private const array Internal = [
		'JsonStrategy not found in container.',
		'CorsMiddleware not found in container.',
		'AuthorizationMiddleware not found in container.',
		'ErrorTranslator not found in container.',
		'Cannot write %s.',
		'Cannot write %s/%s.',
		'Cannot create a temporary file.',
		'Cannot read the exported project.',
		'Cannot create the temporary directory %s.',
		'Cannot add %s to the archive.',
		'AUTHORIZATION_TOKEN_KEY must be at least %d characters.',
		'Threads are numbered in a transaction, which keeps the numbering locked.',
		'Unexpected floor row.',
		'No furniture definition replaces the item kind "%s".',
		'The global definition "%s" is missing.',
		'Furniture seed %s is missing.',
		'Cannot prepare a statement for the furniture definitions: %s',
		'Geometry not read.',
		'Failed to store object "%s',
		'Failed to read object "%s',
		'Failed to list the bucket "%s',
		'Failed to ensure S3 bucket "%s',
		'The backup is missing the object %s.',
	];

	public function testEveryMessageHasATranslationAndEveryTranslationAMessage(): void
	{
		$messages = array_values(array_diff(self::messages(), self::Internal));
		foreach (LocaleEnum::cases() as $locale) {
			if ($locale === LocaleEnum::En) {
				continue;
			}
			$catalogue = self::catalogue($locale);
			self::assertSame([], array_values(array_diff($messages, array_keys($catalogue))), 'untranslated, ' . $locale->value);
			self::assertSame([], array_values(array_diff(array_keys($catalogue), $messages)), 'no longer thrown, ' . $locale->value);
			foreach ($catalogue as $english => $translation) {
				self::assertSame(ErrorTranslator::placeholders($english), ErrorTranslator::placeholders($translation), $english);
			}
		}
	}

	/** @return list<string> */
	private static function messages(): array
	{
		$source = realpath(__DIR__ . '/../../../src');
		self::assertIsString($source);
		$messages = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			assert($file instanceof SplFileInfo);
			$path = $file->getPathname();
			if ($file->getExtension() !== 'php' || str_starts_with($path, $source . '/Mcp/')) {
				continue;
			}
			$code = (string) file_get_contents($path);
			preg_match_all(
				'/(?:new\s+\\\\?[\w\\\\]*(?:Exception|Response|self)\s*\(|::\w+\()\s*(?:sprintf\(\s*)?(\'(?:[^\'\\\\]|\\\\.)*\')/',
				$code,
				$thrown,
			);
			preg_match_all('/const string \w+ = (\'[A-Z][a-z](?:[^\'\\\\]|\\\\.)* (?:[^\'\\\\]|\\\\.)*\.\');/', $code, $constants);
			preg_match_all('/warnings\[\] = (?:sprintf\(\s*)?(\'(?:[^\'\\\\]|\\\\.)*\')/', $code, $warnings);
			foreach ([...$thrown[1], ...$constants[1], ...$warnings[1]] as $literal) {
				$text = str_replace(["\\'", '\\\\'], ["'", '\\'], substr($literal, 1, -1));
				if (preg_match('/^(?:[A-Z]|%d )/', $text) !== 1 || !str_contains($text, ' ')) {
					continue;
				}
				// A prefix the code appends a detail to.
				if (str_ends_with($text, ' ') || str_ends_with($text, '"')) {
					$text .= '%s';
				}
				$messages[$text] = true;
			}
		}

		return array_keys($messages);
	}

	/** @return array<string, string> */
	private static function catalogue(LocaleEnum $locale): array
	{
		$decoded = json_decode((string) file_get_contents(__DIR__ . '/../../../translations/errors.' . $locale->value . '.json'), true);
		self::assertIsArray($decoded);
		$catalogue = [];
		foreach ($decoded as $english => $translation) {
			self::assertIsString($translation);
			$catalogue[(string) $english] = $translation;
		}

		return $catalogue;
	}
}
