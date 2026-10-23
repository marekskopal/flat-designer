<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ProjectController;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use const JSON_THROW_ON_ERROR;

#[CoversClass(ProjectController::class)]
#[CoversClass(ProjectProvider::class)]
final class ProjectControllerTest extends IntegrationTestCase
{
	public function testCreateMakesProjectWithOneFloor(): void
	{
		$user = Fixture::createUser();

		$response = $this->request(
			'POST',
			Routes::Projects->value,
			['name' => ' Flat 12 ', 'address' => '', 'description' => 'Plan'],
			$user,
		);

		self::assertSame(201, $response->getStatusCode());
		$body = $this->jsonBody($response);
		self::assertSame('Flat 12', $body['name']);
		self::assertNull($body['address']);
		self::assertSame('Plan', $body['description']);
		self::assertSame($user->id, $body['ownerId']);
		self::assertSame([['id' => self::firstFloorId($body), 'name' => 'Ground floor', 'level' => 0]], $body['floors']);
		self::assertNull($body['latestVersion']);
	}

	public function testCreateRequiresName(): void
	{
		$response = $this->request('POST', Routes::Projects->value, ['name' => '  '], Fixture::createUser());

		self::assertSame(422, $response->getStatusCode());
	}

	public function testUsersSeeOnlyTheirProjectsAdminsSeeAll(): void
	{
		$jane = Fixture::createUser();
		$joe = Fixture::createUser();
		$admin = Fixture::createAdmin();
		$janes = Fixture::createProject($jane, 'Jane');
		Fixture::createProject($joe, 'Joe');

		$forJane = $this->jsonList($this->request('GET', Routes::Projects->value, authenticatedAs: $jane));
		$forAdmin = $this->jsonList($this->request('GET', Routes::Projects->value, authenticatedAs: $admin));

		self::assertSame(['Jane'], array_column($forJane, 'name'));
		self::assertEqualsCanonicalizing(['Jane', 'Joe'], array_column($forAdmin, 'name'));
		self::assertSame(404, $this->request('GET', self::path($janes->id), authenticatedAs: $joe)->getStatusCode());
		self::assertSame(200, $this->request('GET', self::path($janes->id), authenticatedAs: $admin)->getStatusCode());
	}

	public function testUpdateReplacesDetails(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);

		$response = $this->request(
			'PUT',
			self::path($project->id),
			['name' => 'Renamed', 'address' => 'Praha', 'description' => null],
			$user,
		);

		self::assertSame(200, $response->getStatusCode());
		$body = $this->jsonBody($response);
		self::assertSame('Renamed', $body['name']);
		self::assertSame('Praha', $body['address']);
	}

	public function testUpdateSetsTheSiteWhichTheScenesCarry(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$site = ['northAngle' => 15, 'latitude' => 50.08, 'longitude' => 14.42, 'timeZone' => 'Europe/Prague'];

		$response = $this->request('PUT', self::path($project->id), ['name' => 'Flat', ...$site], $user);

		self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
		$body = $this->jsonBody($response);
		self::assertSame($site, array_intersect_key($body, $site));
		$scene = $this->jsonBody($this->request(
			'GET',
			str_replace(
				['{projectId:number}', '{floorId:number}'],
				[(string) $project->id, (string) self::firstFloorId($body)],
				Routes::ProjectFloorScene->value,
			),
			authenticatedAs: $user,
		));
		self::assertSame($site, $scene['location']);

		// An update without them clears them, as it does the address.
		$cleared = $this->jsonBody($this->request('PUT', self::path($project->id), ['name' => 'Flat'], $user));
		self::assertSame(
			['northAngle' => 0, 'latitude' => null, 'longitude' => null, 'timeZone' => null],
			array_intersect_key($cleared, $site),
		);
	}

	public function testUpdateRefusesAnInvalidSite(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$invalid = [
			['northAngle' => 360],
			['northAngle' => -1],
			['latitude' => 50.0],
			['latitude' => 91.0, 'longitude' => 14.0],
			['latitude' => 50.0, 'longitude' => 181.0],
			['timeZone' => 'Europe/Atlantis'],
		];

		foreach ($invalid as $site) {
			$response = $this->request('PUT', self::path($project->id), ['name' => 'Flat', ...$site], $user);
			self::assertSame(422, $response->getStatusCode(), json_encode($site, JSON_THROW_ON_ERROR));
		}
	}

	public function testOthersCannotUpdateOrDelete(): void
	{
		$project = Fixture::createProject(Fixture::createUser());
		$other = Fixture::createUser();

		self::assertSame(404, $this->request('PUT', self::path($project->id), ['name' => 'Mine now'], $other)->getStatusCode());
		self::assertSame(404, $this->request('DELETE', self::path($project->id), authenticatedAs: $other)->getStatusCode());
	}

	public function testDeleteRemovesProjectWithContent(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		Fixture::furnishFloor(Fixture::firstFloor($project));

		self::assertSame(200, $this->request('DELETE', self::path($project->id), authenticatedAs: $user)->getStatusCode());
		self::assertSame(404, $this->request('GET', self::path($project->id), authenticatedAs: $user)->getStatusCode());
	}

	/** @param array<string, mixed> $project */
	private static function firstFloorId(array $project): int
	{
		self::assertIsList($project['floors']);
		self::assertIsArray($project['floors'][0]);
		self::assertIsInt($project['floors'][0]['id']);

		return $project['floors'][0]['id'];
	}

	private static function path(int $projectId): string
	{
		return str_replace('{projectId:number}', (string) $projectId, Routes::Project->value);
	}
}
