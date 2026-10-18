<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\ExpressionException;
use FlatDesigner\Service\Furniture\Expression\Node\Values;
use FlatDesigner\Service\Furniture\Expression\Parser;
use FlatDesigner\Service\Json\JsonDecoder;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * Runs the shared fixture tests/fixtures/furniture-expressions.json (also run by the frontend twin).
 *
 * @phpstan-import-type Value from Values
 */
#[CoversNamespace('FlatDesigner\Service\Furniture\Expression')]
final class ExpressionTest extends TestCase
{
	private const string Fixture = __DIR__ . '/../../fixtures/furniture-expressions.json';

	/** @return iterable<string, array{string, array<string, mixed>, mixed, string|null}> */
	public static function cases(): iterable
	{
		$fixture = JsonDecoder::decodeObject((string) file_get_contents(self::Fixture));
		$fixtureVariables = $fixture['variables'];
		self::assertIsArray($fixtureVariables);
		$variables = [];
		foreach ($fixtureVariables as $name => $value) {
			$variables[(string) $name] = Values::fromJson($value);
		}
		/** @var list<array{expression: string, result?: mixed, error?: string}> $cases */
		$cases = $fixture['cases'];
		foreach ($cases as $index => $case) {
			yield sprintf('#%d %s', $index, mb_substr($case['expression'], 0, 40)) => [
				$case['expression'],
				$variables,
				$case['result'] ?? null,
				$case['error'] ?? null,
			];
		}
	}

	/** @param array<string, Value> $variables */
	#[DataProvider('cases')]
	public function testFixture(string $expression, array $variables, mixed $result, ?string $error): void
	{
		if ($error !== null) {
			try {
				$value = Parser::parse($expression)->evaluate($variables);
				self::fail(sprintf('Expected error "%s", got %s.', $error, var_export($value, true)));
			} catch (ExpressionException $exception) {
				self::assertSame($error, $exception->error->value, $exception->getMessage());
			}

			return;
		}

		$value = Parser::parse($expression)->evaluate($variables);
		if (is_int($result) || is_float($result)) {
			self::assertIsFloat($value);
			self::assertEqualsWithDelta((float) $result, $value, 1e-9);
		} else {
			// Lists and records as JSON (records stay objects), numbers rounded to 9 decimals.
			self::assertSame(self::json(Values::fromJson($result)), self::json($value));
		}
	}

	private static function json(mixed $value): string
	{
		return json_encode(self::rounded(Values::toJson($value)), JSON_THROW_ON_ERROR);
	}

	private static function rounded(mixed $data): mixed
	{
		if ($data instanceof stdClass) {
			$object = new stdClass();
			foreach (get_object_vars($data) as $field => $item) {
				$object->{$field} = self::rounded($item);
			}

			return $object;
		}

		return is_array($data) ? array_map(self::rounded(...), $data) : (is_float($data) ? round($data, 9) : $data);
	}

	public function testParsedExpressionIsReusable(): void
	{
		$expression = Parser::parse('W / 2 - t');
		self::assertSame(382.0, $expression->evaluate(['W' => 800.0, 't' => 18.0]));
		self::assertSame(282.0, $expression->evaluate(['W' => 600.0, 't' => 18.0]));
	}

	public function testErrorNamesThePositionInCharacters(): void
	{
		$cases = [
			'W +* 2' => 'Unexpected "*" at 3.',
			"'déčko' == x +" => 'Unexpected end of expression.',
			"'ěšč' ~ 1" => 'Unexpected "~" at 6.',
			"W -\u{A0}2" => "Unexpected \"\u{A0}\" at 3.",
		];
		foreach ($cases as $source => $message) {
			try {
				Parser::parse($source);
				self::fail('Expected a syntax error.');
			} catch (ExpressionException $exception) {
				self::assertSame($message, $exception->getMessage());
			}
		}
	}
}
