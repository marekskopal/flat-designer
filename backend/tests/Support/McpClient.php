<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use const JSON_THROW_ON_ERROR;

/** Minimal MCP client speaking JSON-RPC over Streamable HTTP to the app's /mcp endpoint. */
final class McpClient
{
	private ?string $sessionId = null;

	private int $nextId = 1;

	public function __construct(private readonly string $accessToken)
	{
	}

	public function initialize(): void
	{
		$response = $this->post([
			'jsonrpc' => '2.0',
			'id' => $this->takeId(),
			'method' => 'initialize',
			'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']],
		]);
		Assert::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		$this->sessionId = $response->getHeaderLine('Mcp-Session-Id');
		Assert::assertNotSame('', $this->sessionId);

		$this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
	}

	public function sessionId(): ?string
	{
		return $this->sessionId;
	}

	/**
	 * @param array<string, mixed>|null $params
	 * @return array<string, mixed> the JSON-RPC result
	 */
	public function request(string $method, ?array $params = null): array
	{
		$body = ['jsonrpc' => '2.0', 'id' => $this->takeId(), 'method' => $method];
		if ($params !== null) {
			$body['params'] = $params;
		}
		$message = $this->decode($this->post($body));
		Assert::assertArrayHasKey('result', $message, json_encode($message, JSON_THROW_ON_ERROR));
		Assert::assertIsArray($message['result']);

		/** @var array<string, mixed> $result */
		$result = $message['result'];

		return $result;
	}

	/**
	 * Calls a tool and returns its decoded JSON output; `$isError` tells whether the tool reported an error
	 * (then the output is the message).
	 *
	 * @param array<string, mixed> $arguments
	 */
	public function call(string $tool, array $arguments = [], bool &$isError = false): mixed
	{
		$result = $this->request('tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new stdClass() : $arguments]);
		$isError = ($result['isError'] ?? false) === true;
		Assert::assertIsList($result['content']);
		Assert::assertIsArray($result['content'][0]);
		$text = $result['content'][0]['text'] ?? null;
		Assert::assertIsString($text);

		return $isError ? $text : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
	}

	/** @param array<string, mixed> $body */
	public function post(array $body): ResponseInterface
	{
		$request = (new ServerRequest([], [], 'http://localhost:4400/mcp', 'POST'))
			->withHeader('Host', 'localhost:4400')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withHeader('Authorization', 'Bearer ' . $this->accessToken)
			->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
		if ($this->sessionId !== null) {
			$request = $request->withHeader('Mcp-Session-Id', $this->sessionId)->withHeader('Mcp-Protocol-Version', '2025-06-18');
		}

		return AppHarness::handle($request);
	}

	/** @return array<string, mixed> */
	private function decode(ResponseInterface $response): array
	{
		$body = (string) $response->getBody();
		// A streamed answer carries the JSON-RPC message in an SSE `data:` line.
		if (
			str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')
			&& preg_match('/^data: (.+)$/m', $body, $match) === 1
		) {
			$body = $match[1];
		}
		$decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		Assert::assertIsArray($decoded);

		/** @var array<string, mixed> $message */
		$message = $decoded;

		return $message;
	}

	private function takeId(): int
	{
		$id = $this->nextId;
		$this->nextId++;

		return $id;
	}
}
