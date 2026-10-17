<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Route\Routes;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use const JSON_THROW_ON_ERROR;

/** Shortcuts for editing one floor of one project over the REST API. */
abstract class EditorTestCase extends IntegrationTestCase
{
	protected User $user;

	protected Project $project;

	protected Floor $floor;

	protected function setUp(): void
	{
		parent::setUp();

		$this->user = Fixture::createUser(name: 'Jane');
		$this->project = Fixture::createProject($this->user);
		$this->floor = Fixture::firstFloor($this->project);
	}

	/** @param array<string, mixed>|null $body */
	protected function edit(
		string $method,
		string $type,
		?int $id = null,
		?array $body = null,
		?User $as = null,
		?int $baseRevision = null,
		string $suffix = '',
	): ResponseInterface {
		$path = sprintf('/api/projects/%d/floors/%d/%s', $this->project->id, $this->floor->id, $type)
			. ($id === null ? '' : '/' . $id) . $suffix;
		$request = (new ServerRequest([], [], $path, $method))
			->withHeader('Authorization', 'Bearer ' . Fixture::accessTokenFor($as ?? $this->user));
		if ($body !== null) {
			$request = $request
				->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)))
				->withHeader('Content-Type', 'application/json');
		}
		if ($baseRevision !== null) {
			$request = $request->withHeader('If-Match', (string) $baseRevision);
		}

		return AppHarness::handle($request);
	}

	/**
	 * Creates an element and returns its state.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	protected function create(string $type, array $fields, ?User $as = null): array
	{
		$response = $this->edit('POST', $type, body: $fields, as: $as);
		self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
		$element = $this->jsonBody($response)['element'];
		self::assertIsArray($element);
		/** @var array<string, mixed> $state */
		$state = $element;

		return $state;
	}

	protected static function id(mixed $element): int
	{
		self::assertIsArray($element);
		self::assertIsInt($element['id'] ?? null);

		return $element['id'];
	}

	/** @return array<string, mixed> */
	protected function scene(): array
	{
		$path = str_replace(
			['{projectId:number}', '{floorId:number}'],
			[(string) $this->project->id, (string) $this->floor->id],
			Routes::ProjectFloorScene->value,
		);

		return $this->jsonBody($this->request('GET', $path, authenticatedAs: $this->user));
	}

	/** @return list<array<string, mixed>> */
	protected function sceneList(string $key): array
	{
		$list = $this->scene()[$key];
		self::assertIsList($list);
		/** @var list<array<string, mixed>> $elements */
		$elements = $list;

		return $elements;
	}

	protected function undo(?User $as = null): ResponseInterface
	{
		return $this->request('POST', sprintf('/api/projects/%d/undo', $this->project->id), [], $as ?? $this->user);
	}

	protected function redo(?User $as = null): ResponseInterface
	{
		return $this->request('POST', sprintf('/api/projects/%d/redo', $this->project->id), [], $as ?? $this->user);
	}

	protected function revision(): int
	{
		$revision = $this->scene()['revision'];
		self::assertIsInt($revision);

		return $revision;
	}
}
