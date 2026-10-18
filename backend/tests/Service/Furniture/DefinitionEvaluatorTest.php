<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Furniture;

use FlatDesigner\Service\Furniture\DefinitionEvaluator;
use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Furniture\ResolvedPart;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs the shared fixture tests/fixtures/furniture-definitions.json (also run by the frontend twin). */
#[CoversClass(DefinitionEvaluator::class)]
final class DefinitionEvaluatorTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../fixtures/furniture-definitions.json';

	/** @return iterable<string, array{array<mixed>, array{width: int, depth: int, height: int}, array<mixed>, list<array<string, mixed>>|null, array{code: string, path: string}|null}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		/** @var list<array{name: string, definition: array<mixed>, size: array{width: int, depth: int, height: int}, values?: array<mixed>, parts?: list<array<string, mixed>>, error?: array{code: string, path: string}}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $case) {
			yield $case['name'] => [$case['definition'], $case['size'], $case['values'] ?? [], $case['parts'] ?? null, $case['error'] ?? null];
		}
	}

	/**
	 * @param array<mixed> $definition
	 * @param array{width: int, depth: int, height: int} $size
	 * @param array<mixed> $values
	 * @param list<array<string, mixed>>|null $parts
	 * @param array{code: string, path: string}|null $error
	 */
	#[DataProvider('cases')]
	public function testFixture(array $definition, array $size, array $values, ?array $parts, ?array $error): void
	{
		$evaluator = new DefinitionEvaluator();
		if ($error !== null) {
			try {
				$evaluator->evaluate($definition, $size['width'], $size['depth'], $size['height'], $values);
				self::fail(sprintf('Expected error "%s" at %s.', $error['code'], $error['path']));
			} catch (DefinitionException $exception) {
				self::assertSame($error, ['code' => $exception->error->value, 'path' => $exception->path], $exception->getMessage());
			}

			return;
		}

		$resolved = $evaluator->evaluate($definition, $size['width'], $size['depth'], $size['height'], $values);
		self::assertSame($parts, array_map(static fn (ResolvedPart $part): array => $part->jsonSerialize(), $resolved));
	}

	public function testMessageCarriesThePath(): void
	{
		try {
			new DefinitionEvaluator()->evaluate(
				['format' => 1, 'parts' => [['type' => 'board', 'name' => 'Top', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 'W', 'h' => '0.4', 'd' => 'D']]],
				800,
				300,
				900,
			);
			self::fail('Expected a non-positive size.');
		} catch (DefinitionException $exception) {
			self::assertSame('parts[0].h: "Top" gets a height of 0 mm.', $exception->getMessage());
		}
	}
}
