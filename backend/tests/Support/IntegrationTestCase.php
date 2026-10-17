<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use FlatDesigner\Model\Entity\User;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use const JSON_THROW_ON_ERROR;

/** Dispatches requests through the production router and middleware stack against the test database. */
abstract class IntegrationTestCase extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		AppHarness::resetState();
		Fixture::reset();
	}

	/** @param array<string, mixed>|null $body */
	protected function request(
		string $method,
		string $path,
		?array $body = null,
		?User $authenticatedAs = null,
		?string $bearerToken = null,
	): ResponseInterface {
		$request = new ServerRequest([], [], $path, $method);

		if ($body !== null) {
			$request = $request
				->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
				->withHeader('Content-Type', 'application/json');
		}

		$bearerToken ??= $authenticatedAs !== null ? Fixture::accessTokenFor($authenticatedAs) : null;
		if ($bearerToken !== null) {
			$request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
		}

		$response = AppHarness::app()->handler->handle($request);
		// Production clears the ORM state between requests (frankenphp-worker.php); do the same so a
		// test sees what the database holds, not the identity map.
		AppHarness::app()->dbContext->resetOrmState();

		return $response;
	}

	/** @return array<string, mixed> */
	protected function jsonBody(ResponseInterface $response): array
	{
		$decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		/** @var array<string, mixed> $object */
		$object = $decoded;

		return $object;
	}

	/** @return list<array<string, mixed>> */
	protected function jsonList(ResponseInterface $response): array
	{
		$decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsList($decoded);
		/** @var list<array<string, mixed>> $list */
		$list = $decoded;

		return $list;
	}

	protected static function stringField(mixed $value): string
	{
		self::assertIsString($value);

		return $value;
	}
}
