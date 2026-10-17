<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\FloorController;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\FloorProvider;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FloorController::class)]
#[CoversClass(FloorProvider::class)]
final class FloorControllerTest extends IntegrationTestCase
{
	public function testAddRenameAndDeleteFloors(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$ground = Fixture::firstFloor($project);

		$created = $this->request('POST', self::path(Routes::ProjectFloors, $project->id), ['name' => 'Attic', 'level' => 1], $user);
		self::assertSame(201, $created->getStatusCode());
		$atticId = $this->jsonBody($created)['id'];
		self::assertIsInt($atticId);

		$renamed = $this->request('PUT', self::path(Routes::ProjectFloor, $project->id, $atticId), ['name' => 'Loft', 'level' => 2], $user);
		self::assertSame(['id' => $atticId, 'name' => 'Loft', 'level' => 2], $this->jsonBody($renamed));

		self::assertSame(
			200,
			$this->request('DELETE', self::path(Routes::ProjectFloor, $project->id, $atticId), authenticatedAs: $user)->getStatusCode(),
		);
		// The last floor stays.
		self::assertSame(
			409,
			$this->request('DELETE', self::path(Routes::ProjectFloor, $project->id, $ground->id), authenticatedAs: $user)->getStatusCode(),
		);
	}

	public function testFloorInputIsValidated(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);

		self::assertSame(
			422,
			$this->request('POST', self::path(Routes::ProjectFloors, $project->id), ['name' => '', 'level' => 0], $user)->getStatusCode(),
		);
		self::assertSame(
			422,
			$this->request(
				'POST',
				self::path(Routes::ProjectFloors, $project->id),
				['name' => 'X', 'level' => 500],
				$user,
			)->getStatusCode(),
		);
		self::assertSame(
			400,
			$this->request(
				'POST',
				self::path(Routes::ProjectFloors, $project->id),
				['name' => 'X', 'level' => '1'],
				$user,
			)->getStatusCode(),
		);
	}

	public function testSceneOfFloor(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);

		$scene = $this->jsonBody(
			$this->request('GET', self::path(Routes::ProjectFloorScene, $project->id, $floor->id), authenticatedAs: $user),
		);

		self::assertSame(['id' => $floor->id, 'name' => 'Ground floor', 'level' => 0], $scene['floor']);
		self::assertIsList($scene['rooms']);
		self::assertCount(1, $scene['rooms']);
		self::assertIsList($scene['walls']);
		self::assertCount(1, $scene['walls']);
		self::assertIsList($scene['items']);
		self::assertCount(2, $scene['items']);
	}

	public function testFloorsOfOtherProjectsAreNotFound(): void
	{
		$user = Fixture::createUser();
		$mine = Fixture::createProject($user);
		$foreign = Fixture::createProject(Fixture::createUser());
		$foreignFloor = Fixture::firstFloor($foreign);

		self::assertSame(
			404,
			$this->request(
				'GET',
				self::path(Routes::ProjectFloorScene, $foreign->id, $foreignFloor->id),
				authenticatedAs: $user,
			)->getStatusCode(),
		);
		// A floor id of another project under my project id.
		self::assertSame(
			404,
			$this->request(
				'GET',
				self::path(Routes::ProjectFloorScene, $mine->id, $foreignFloor->id),
				authenticatedAs: $user,
			)->getStatusCode(),
		);
	}

	private static function path(Routes $route, int $projectId, ?int $floorId = null): string
	{
		return str_replace(['{projectId:number}', '{floorId:number}'], [(string) $projectId, (string) $floorId], $route->value);
	}
}
