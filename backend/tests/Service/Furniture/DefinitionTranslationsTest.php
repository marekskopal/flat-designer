<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Furniture;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Service\Furniture\DefinitionException;
use Izbik\Service\Furniture\DefinitionTextKeys;
use Izbik\Service\Furniture\DefinitionTranslations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinitionTranslations::class)]
#[CoversClass(DefinitionTextKeys::class)]
final class DefinitionTranslationsTest extends TestCase
{
	private const array Definition = [
		'format' => 1,
		'parameters' => [
			['key' => 'handle', 'type' => 'select', 'options' => ['bar', 'none'], 'default' => 'bar'],
			['key' => 'fronts', 'type' => 'list', 'fields' => [['key' => 'type', 'type' => 'select', 'options' => ['door', 'drawer'], 'default' => 'door']]],
		],
		'materialSlots' => [['key' => 'body']],
		'parts' => [
			['type' => 'board', 'name' => 'Side'],
			['type' => 'assembly', 'name' => 'Drawer', 'parts' => [['type' => 'board', 'name' => 'Drawer bottom']]],
		],
	];

	public function testTextsForWhatTheDefinitionHasAreAccepted(): void
	{
		$this->expectNotToPerformAssertions();
		DefinitionTranslations::check(self::Definition + [
			'translations' => [
				'cs' => [
					'name' => 'Skříňka',
					'description' => 'Spodní skříňka.',
					'parameters' => ['handle' => 'Úchytka', 'fronts' => 'Čela', 'fronts.type' => 'Druh'],
					'options' => ['handle' => ['bar' => 'madlo', 'none' => 'žádná'], 'fronts.type' => ['drawer' => 'zásuvka']],
					'slots' => ['body' => 'Korpus'],
					'parts' => ['Side' => 'Bok', 'Drawer bottom' => 'Dno zásuvky'],
				]]]);
		DefinitionTranslations::check(self::Definition);
	}

	/** @return iterable<string, array{array<mixed>, string}> */
	public static function wrongTranslations(): iterable
	{
		yield 'English' => [['en' => ['name' => 'Cabinet']], 'translations.en'];
		yield 'another language' => [['de' => ['name' => 'Schrank']], 'translations.de'];
		yield 'an unknown key' => [['cs' => ['title' => 'Skříňka']], 'translations.cs.title'];
		yield 'an empty name' => [['cs' => ['name' => ' ']], 'translations.cs.name'];
		yield 'a parameter it lacks' => [['cs' => ['parameters' => ['width' => 'Šířka']]], 'translations.cs.parameters.width'];
		yield 'an option it lacks' => [['cs' => ['options' => ['handle' => ['knob' => 'knoflík']]]], 'translations.cs.options.handle.knob'];
		yield 'options of a non-select' => [['cs' => ['options' => ['fronts' => ['a' => 'b']]]], 'translations.cs.options.fronts'];
		yield 'a slot it lacks' => [['cs' => ['slots' => ['legs' => 'Nohy']]], 'translations.cs.slots.legs'];
		yield 'a part it lacks' => [['cs' => ['parts' => ['Top' => 'Víko']]], 'translations.cs.parts.Top'];
	}

	/** @param array<mixed> $translations */
	#[DataProvider('wrongTranslations')]
	public function testTextsForWhatTheDefinitionLacksAreRefused(array $translations, string $path): void
	{
		$this->expectException(DefinitionException::class);
		$this->expectExceptionMessageMatches('/^' . preg_quote($path, '/') . ':/');
		DefinitionTranslations::check(self::Definition + ['translations' => $translations]);
	}

	public function testAPartIsNamedInTheLanguageIfTranslated(): void
	{
		$definition = self::Definition + ['translations' => ['cs' => ['parts' => ['Side' => 'Bok']]]];

		self::assertSame('Bok', DefinitionTranslations::partName($definition, 'Side', LocaleEnum::Cs));
		self::assertSame('Drawer bottom', DefinitionTranslations::partName($definition, 'Drawer bottom', LocaleEnum::Cs));
		self::assertSame('Side', DefinitionTranslations::partName($definition, 'Side', LocaleEnum::En));
	}
}
