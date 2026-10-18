<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use FlatDesigner\Model\Entity\User;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;
use const UPLOAD_ERR_OK;

/** Dispatches requests through the production router and middleware stack against the test database. */
abstract class IntegrationTestCase extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		AppHarness::resetState();
		Fixture::reset();
	}

	/** Multipart POST with one `file` field, like a browser upload. */
	protected function upload(string $path, string $filename, string $content, User $authenticatedAs): ResponseInterface
	{
		$stream = (new StreamFactory())->createStream($content);
		$request = (new ServerRequest([], [], $path, 'POST'))
			->withUploadedFiles(['file' => new UploadedFile($stream, strlen($content), UPLOAD_ERR_OK, $filename, 'text/html')])
			->withHeader('Authorization', 'Bearer ' . Fixture::accessTokenFor($authenticatedAs));

		return AppHarness::handle($request);
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
		parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
		if ($query !== []) {
			$request = $request->withQueryParams($query);
		}

		if ($body !== null) {
			$request = $request
				->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
				->withHeader('Content-Type', 'application/json');
		}

		$bearerToken ??= $authenticatedAs !== null ? Fixture::accessTokenFor($authenticatedAs) : null;
		if ($bearerToken !== null) {
			$request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
		}

		return AppHarness::handle($request);
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
