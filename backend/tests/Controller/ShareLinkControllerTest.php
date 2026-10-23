<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use DateTimeImmutable;
use FlatDesigner\Controller\SharedViewController;
use FlatDesigner\Controller\ShareLinkController;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ShareLinkRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Project\ShareLinkProvider;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use const DATE_ATOM;

#[CoversClass(ShareLinkController::class)]
#[CoversClass(SharedViewController::class)]
#[CoversClass(ShareLinkProvider::class)]
final class ShareLinkControllerTest extends IntegrationTestCase
{
	private const array Settings = [
		'views' => ['overview', 'top'],
		'initialView' => 'overview',
		'camera' => ['position' => ['x' => -2000, 'y' => -3000, 'z' => 6000], 'target' => ['x' => 4000, 'y' => 5000, 'z' => 0]],
		'ceilings' => false,
		'labels' => true,
		'sectionHeight' => 1300,
		'dimensions' => true,
		'night' => true,
		'sun' => ['month' => 6, 'hour' => 15.5],
		'presentation' => true,
		'exports' => false,
		'comments' => 'read',
	];

	public function testCreatingALinkSavesAVersionAndGuestsSeeIt(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);

		$created = $this->request(
			'POST',
			self::path(Routes::ProjectShareLinks, $project->id),
			['floorId' => $floor->id, 'name' => 'Kitchen', 'settings' => self::Settings],
			$user,
		);

		self::assertSame(201, $created->getStatusCode());
		$link = $this->jsonBody($created);
		self::assertSame('Kitchen', $link['name']);
		self::assertSame('Ground floor', $link['floorName']);
		self::assertSame(1, $link['versionNumber']);
		self::assertSame(self::Settings, $link['settings']);
		self::assertFalse($link['expired']);
		$token = self::stringField($link['token']);
		self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);

		$versions = $this->jsonList($this->request('GET', self::path(Routes::ProjectVersions, $project->id), authenticatedAs: $user));
		self::assertSame(['Shared: Kitchen'], array_column($versions, 'note'));

		// No Authorization header: the guest.
		$shared = $this->request('GET', self::sharePath($token));
		self::assertSame(200, $shared->getStatusCode());
		$view = $this->jsonBody($shared);
		self::assertSame('Kitchen', $view['name']);
		self::assertSame('Test Project', $view['projectName']);
		self::assertSame(self::Settings, $view['settings']);
		self::assertIsArray($view['scene']);
		self::assertSame(
			['id' => $floor->id, 'name' => 'Ground floor', 'level' => 0, 'elevation' => 0, 'slabThickness' => 300],
			$view['scene']['floor'],
		);
		self::assertIsList($view['scene']['walls']);
		self::assertCount(1, $view['scene']['walls']);
	}

	public function testGuestSeesTheFloorAsItWasWhenShared(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$token = self::stringField($this->createLink($user, $project->id, $floor->id)['token']);

		$walls = Fixture::service(WallRepository::class);
		foreach ($walls->findByFloor($floor->id) as $wall) {
			$walls->delete($wall);
		}

		$scene = $this->jsonBody($this->request('GET', self::sharePath($token)))['scene'];
		self::assertIsArray($scene);
		self::assertIsList($scene['walls']);
		self::assertCount(1, $scene['walls']);
	}

	public function testRefreshingALinkShowsTheFloorAsItIsNow(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		Fixture::furnishFloor($floor);
		$link = $this->createLink($user, $project->id, $floor->id);
		self::assertIsInt($link['id']);
		$token = self::stringField($link['token']);

		$walls = Fixture::service(WallRepository::class);
		foreach ($walls->findByFloor($floor->id) as $wall) {
			$walls->delete($wall);
		}

		$refreshed = $this->request('POST', self::path(Routes::ProjectShareLinkRefresh, $project->id, $link['id']), authenticatedAs: $user);
		self::assertSame(200, $refreshed->getStatusCode());
		$body = $this->jsonBody($refreshed);
		self::assertSame($token, $body['token']);
		self::assertSame(2, $body['versionNumber']);
		self::assertSame($link['settings'], $body['settings']);

		$scene = $this->jsonBody($this->request('GET', self::sharePath($token)))['scene'];
		self::assertIsArray($scene);
		self::assertSame([], $scene['walls']);
		$versions = $this->jsonList($this->request('GET', self::path(Routes::ProjectVersions, $project->id), authenticatedAs: $user));
		self::assertSame(['Shared: Link', 'Shared: Link'], array_column($versions, 'note'));

		$other = Fixture::createUser('other@example.com');
		self::assertSame(
			404,
			$this->request('POST', self::path(Routes::ProjectShareLinkRefresh, $project->id, $link['id']), authenticatedAs: $other)
				->getStatusCode(),
		);
	}

	public function testOwnerListsUpdatesAndDeletesLinks(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floorId = Fixture::firstFloor($project)->id;
		$first = $this->createLink($user, $project->id, $floorId, 'First');
		$second = $this->createLink($user, $project->id, $floorId, 'Second');
		self::assertIsInt($first['id']);
		self::assertIsInt($second['id']);

		$list = $this->jsonList($this->request('GET', self::path(Routes::ProjectShareLinks, $project->id), authenticatedAs: $user));
		self::assertSame(['Second', 'First'], array_column($list, 'name'));

		$expiresAt = (new DateTimeImmutable('+1 week'))->format(DATE_ATOM);
		$settings = ['views' => ['top'], 'initialView' => 'top', 'camera' => null, 'ceilings' => true, 'labels' => false, 'sectionHeight' => null];
		$updated = $this->request(
			'PUT',
			self::path(Routes::ProjectShareLink, $project->id, $first['id']),
			['name' => 'Renamed', 'settings' => $settings, 'expiresAt' => $expiresAt],
			$user,
		);
		self::assertSame(200, $updated->getStatusCode());
		$body = $this->jsonBody($updated);
		self::assertSame('Renamed', $body['name']);
		// Without the flags (a link from before dimension lines, the night view and the quality), they are off; exports are
		// allowed and comments hidden.
		self::assertSame(
			[...$settings, 'dimensions' => false, 'night' => false, 'sun' => null, 'presentation' => false, 'exports' => true, 'comments' => 'hidden'],
			$body['settings'],
		);
		self::assertSame($expiresAt, $body['expiresAt']);
		self::assertSame($first['versionId'], $body['versionId']);

		self::assertSame(
			200,
			$this->request(
				'DELETE',
				self::path(Routes::ProjectShareLink, $project->id, $second['id']),
				authenticatedAs: $user,
			)->getStatusCode(),
		);
		self::assertSame(404, $this->request('GET', self::sharePath(self::stringField($second['token'])))->getStatusCode());
		// The version stays in the history.
		$versions = $this->jsonList($this->request('GET', self::path(Routes::ProjectVersions, $project->id), authenticatedAs: $user));
		self::assertCount(2, $versions);
	}

	public function testExpiredAndUnknownLinksAreNotFound(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$link = $this->createLink($user, $project->id, Fixture::firstFloor($project)->id);
		$token = self::stringField($link['token']);
		self::assertIsInt($link['id']);

		$repository = Fixture::service(ShareLinkRepository::class);
		$entity = $repository->findOneInProject($project->id, $link['id']);
		self::assertNotNull($entity);
		$entity->expiresAt = new DateTimeImmutable('-1 minute');
		$repository->persist($entity);

		self::assertSame(404, $this->request('GET', self::sharePath($token))->getStatusCode());
		self::assertSame(404, $this->request('GET', self::sharePath(str_repeat('0', 32)))->getStatusCode());
		$list = $this->jsonList($this->request('GET', self::path(Routes::ProjectShareLinks, $project->id), authenticatedAs: $user));
		self::assertTrue($list[0]['expired']);
	}

	public function testLinksOfOtherProjectsAreNotFound(): void
	{
		$owner = Fixture::createUser();
		$project = Fixture::createProject($owner);
		$floorId = Fixture::firstFloor($project)->id;
		$link = $this->createLink($owner, $project->id, $floorId);
		self::assertIsInt($link['id']);
		$other = Fixture::createUser();
		$otherProject = Fixture::createProject($other);

		self::assertSame(
			404,
			$this->request('GET', self::path(Routes::ProjectShareLinks, $project->id), authenticatedAs: $other)->getStatusCode(),
		);
		self::assertSame(
			404,
			$this->request(
				'DELETE',
				self::path(Routes::ProjectShareLink, $project->id, $link['id']),
				authenticatedAs: $other,
			)->getStatusCode(),
		);
		// Sharing someone else's floor from one's own project.
		self::assertSame(
			422,
			$this->request(
				'POST',
				self::path(Routes::ProjectShareLinks, $otherProject->id),
				['floorId' => $floorId, 'name' => 'Stolen', 'settings' => self::Settings],
				$other,
			)->getStatusCode(),
		);
	}

	public function testGuestsCannotManageLinks(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);

		self::assertSame(401, $this->request('GET', self::path(Routes::ProjectShareLinks, $project->id))->getStatusCode());
	}

	/** @param array<string, mixed> $settings */
	#[DataProvider('invalidSettings')]
	public function testInvalidSettingsAreRejected(array $settings, int $status): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);

		$response = $this->request(
			'POST',
			self::path(Routes::ProjectShareLinks, $project->id),
			['floorId' => Fixture::firstFloor($project)->id, 'name' => 'Bad', 'settings' => [...self::Settings, ...$settings]],
			$user,
		);

		self::assertSame($status, $response->getStatusCode());
	}

	/** @return iterable<string, array{array<string, mixed>, int}> */
	public static function invalidSettings(): iterable
	{
		yield 'no views' => [['views' => []], 422];
		yield 'unknown view' => [['views' => ['side']], 422];
		yield 'start view not allowed' => [['initialView' => 'walkthrough'], 422];
		yield 'camera without 3D' => [['views' => ['top'], 'initialView' => 'top'], 422];
		yield 'walk only' => [['views' => ['walkthrough'], 'initialView' => 'walkthrough', 'camera' => null], 422];
		yield 'wall cut too low' => [['sectionHeight' => 100], 422];
		yield 'camera not an object' => [['camera' => 'front'], 400];
	}

	/** @return array<string, mixed> */
	private function createLink(User $user, int $projectId, int $floorId, string $name = 'Link'): array
	{
		$response = $this->request(
			'POST',
			self::path(Routes::ProjectShareLinks, $projectId),
			['floorId' => $floorId, 'name' => $name, 'settings' => self::Settings],
			$user,
		);
		self::assertSame(201, $response->getStatusCode());

		return $this->jsonBody($response);
	}

	private static function path(Routes $route, int $projectId, ?int $shareLinkId = null): string
	{
		return str_replace(['{projectId:number}', '{shareLinkId:number}'], [(string) $projectId, (string) $shareLinkId], $route->value);
	}

	private static function sharePath(string $token): string
	{
		return str_replace('{token:[a-f0-9]+}', $token, Routes::Share->value);
	}
}
