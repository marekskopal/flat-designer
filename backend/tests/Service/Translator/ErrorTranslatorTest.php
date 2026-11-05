<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Translator;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Service\Translator\ErrorTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorTranslator::class)]
final class ErrorTranslatorTest extends TestCase
{
	private string $directory;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/izbik-errors-' . uniqid();
		mkdir($this->directory);
		file_put_contents($this->directory . '/errors.cs.json', (string) json_encode([
			'Floor not found.' => 'Podlaží nebylo nalezeno.',
			'Wall %d is not on this floor.' => 'Stěna %d není na tomto podlaží.',
			'Field "%s" must be a string.' => 'Pole „%s“ musí být text.',
			'Field "%s" must be a list of strings.' => 'Pole „%s“ musí být seznam textů.',
			'Waste must be between 0 and %d %%.' => 'Prořez musí být mezi 0 a %d %%.',
			'The flight would turn %.0f°.' => 'Rameno by se otočilo o %.0f°.',
			'Furniture "%s" does not work out: %s' => 'Nábytek „%s“ nevychází: %s',
		]));
	}

	protected function tearDown(): void
	{
		$files = glob($this->directory . '/*');
		array_map('unlink', $files === false ? [] : $files);
		rmdir($this->directory);
	}

	public function testAMessageOrItsFormatIsTranslated(): void
	{
		$translator = new ErrorTranslator($this->directory);

		self::assertSame('Podlaží nebylo nalezeno.', $translator->translate('Floor not found.', LocaleEnum::Cs));
		self::assertSame('Stěna -12 není na tomto podlaží.', $translator->translate('Wall -12 is not on this floor.', LocaleEnum::Cs));
		self::assertSame('Prořez musí být mezi 0 a 50 %.', $translator->translate('Waste must be between 0 and 50 %.', LocaleEnum::Cs));
		self::assertSame('Rameno by se otočilo o 345°.', $translator->translate('The flight would turn 345°.', LocaleEnum::Cs));
	}

	public function testTheMostSpecificFormatWins(): void
	{
		$translator = new ErrorTranslator($this->directory);

		self::assertSame(
			'Pole „name“ musí být seznam textů.',
			$translator->translate('Field "name" must be a list of strings.', LocaleEnum::Cs),
		);
		self::assertSame('Pole „name“ musí být text.', $translator->translate('Field "name" must be a string.', LocaleEnum::Cs));
	}

	public function testAPartThatIsAMessageOfItsOwnIsTranslatedToo(): void
	{
		$translator = new ErrorTranslator($this->directory);

		self::assertSame(
			'Nábytek „Shelf“ nevychází: Podlaží nebylo nalezeno.',
			$translator->translate('Furniture "Shelf" does not work out: Floor not found.', LocaleEnum::Cs),
		);
		self::assertSame(
			'Nábytek „Shelf“ nevychází: Unknown parameter "x".',
			$translator->translate('Furniture "Shelf" does not work out: Unknown parameter "x".', LocaleEnum::Cs),
		);
	}

	public function testUnknownMessagesAndEnglishStayAsTheyAre(): void
	{
		$translator = new ErrorTranslator($this->directory);

		self::assertSame('Something else.', $translator->translate('Something else.', LocaleEnum::Cs));
		self::assertSame('Floor not found.', $translator->translate('Floor not found.', LocaleEnum::En));
	}
}
