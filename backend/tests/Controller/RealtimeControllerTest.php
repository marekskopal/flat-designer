<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use FlatDesigner\Controller\RealtimeController;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Realtime\MercureCookieIssuer;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\McpClient;
use FlatDesigner\Tests\Support\OAuthFlow;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use const JSON_THROW_ON_ERROR;

#[CoversClass(RealtimeController::class)]
#[CoversClass(MercureCookieIssuer::class)]
#[CoversClass(ProjectUpdatePublisher::class)]
final class RealtimeControllerTest extends EditorTestCase
{
	public function testSubscriptionCookieGrantsTheUsersProjects(): void
	{
		$other = Fixture::createProject($this->user, 'Second');
		Fixture::createProject(Fixture::createUser(), 'Not mine');

		$response = $this->request('POST', Routes::RealtimeSubscription->value, [], $this->user);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('/.well-known/mercure', $this->jsonBody($response)['hubUrl']);
		$cookie = $response->getHeaderLine('Set-Cookie');
		self::assertStringContainsString('Path=/.well-known/mercure; HttpOnly; SameSite=Strict', $cookie);
		$expected = ['flat-designer/projects/' . $this->project->id, 'flat-designer/projects/' . $other->id];
		sort($expected);
		self::assertSame($expected, self::grantedTopics($cookie));

		$admin = $this->request('POST', Routes::RealtimeSubscription->value, [], Fixture::createUser(role: UserRoleEnum::Admin));
		self::assertSame(['*'], self::grantedTopics($admin->getHeaderLine('Set-Cookie')));
	}

	public function testEditPublishesOneUpdateWithTheCommittedRevision(): void
	{
		$request = (new ServerRequest([], [], sprintf('/api/projects/%d/floors/%d/items', $this->project->id, $this->floor->id), 'POST'))
			->withHeader('Authorization', 'Bearer ' . Fixture::accessTokenFor($this->user))
			->withHeader('X-Origin-Client-Id', 'tab-1')
			->withBody((new StreamFactory())->createStream(json_encode(['name' => 'Base'], JSON_THROW_ON_ERROR)));

		AppHarness::handle($request);

		$updates = AppHarness::mercureHub()->updates;
		self::assertCount(1, $updates);
		self::assertSame(['flat-designer/projects/' . $this->project->id], $updates[0]->getTopics());
		self::assertTrue($updates[0]->isPrivate());
		$message = AppHarness::mercureHub()->messages()[0];
		self::assertSame($this->revision(), $message['revision']);
		self::assertSame('Added item "Base"', $message['summary']);
		self::assertSame('Jane', $message['actorName']);
		self::assertSame($this->floor->id, $message['floorId']);
		self::assertSame('tab-1', $message['originClientId']);
	}

	public function testRejectedAndNoOpEditsPublishNothing(): void
	{
		$item = $this->create('items', ['name' => 'Base']);
		AppHarness::mercureHub()->updates = [];

		$this->edit('POST', 'items', body: ['width' => 0]);
		$this->edit('PATCH', 'items', self::id($item), ['name' => 'Base']);

		self::assertSame([], AppHarness::mercureHub()->updates);
	}

	public function testAgentEditsAndUndoArePublished(): void
	{
		$client = new McpClient(OAuthFlow::accessToken($this->user, 'Claude'));
		$client->initialize();
		AppHarness::mercureHub()->updates = [];

		$client->call('add_item', ['projectId' => $this->project->id, 'floorId' => $this->floor->id, 'kind' => 'Box', 'x' => 0, 'y' => 0]);
		$client->call('undo', ['projectId' => $this->project->id]);

		$messages = AppHarness::mercureHub()->messages();
		self::assertSame(['Agent', 'Agent'], array_column($messages, 'actorType'));
		self::assertSame(['Claude', 'Claude'], array_column($messages, 'actorName'));
		self::assertSame([false, true], array_column($messages, 'undone'));
	}

	public function testEditingAFurnitureDefinitionTellsTheProjectsUsingIt(): void
	{
		$definition = [
			'format' => 1,
			'size' => ['width' => 600, 'depth' => 300, 'height' => 900],
			'parts' => [['type' => 'board', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 'W', 'h' => 18, 'd' => 'D']],
		];
		$created = $this->jsonBody(
			$this->request('POST', Routes::FurnitureDefinitions->value, ['name' => 'Shelf', 'definition' => $definition], $this->user),
		);
		$definitionId = self::id($created);
		$this->create('items', ['kind' => 'Custom', 'definitionId' => $definitionId]);
		// A project without items of it hears nothing.
		Fixture::createProject($this->user, 'Other');
		AppHarness::mercureHub()->updates = [];

		$path = str_replace('{definitionId:number}', (string) $definitionId, Routes::FurnitureDefinition->value);
		$this->request(
			'PUT',
			$path,
			['name' => 'Shelf', 'definition' => ['parts' => [...$definition['parts'], ...$definition['parts']]] + $definition],
			$this->user,
		);

		self::assertSame(
			[['type' => 'definitionChanged', 'projectId' => $this->project->id, 'definitionId' => $definitionId, 'originClientId' => null]],
			AppHarness::mercureHub()->messages(),
		);
	}

	public function testChangingTheProjectDetailsTellsItsSubscribers(): void
	{
		AppHarness::mercureHub()->updates = [];
		$path = str_replace('{projectId:number}', (string) $this->project->id, Routes::Project->value);
		$this->request('PUT', $path, ['name' => 'Flat 12 (renovated)', 'address' => null, 'description' => null], $this->user);

		self::assertSame(
			[['type' => 'detailsChanged', 'projectId' => $this->project->id, 'originClientId' => null]],
			AppHarness::mercureHub()->messages(),
		);

		// Saving the same details again is not a change.
		AppHarness::mercureHub()->updates = [];
		$this->request('PUT', $path, ['name' => 'Flat 12 (renovated)', 'address' => null, 'description' => null], $this->user);
		self::assertSame([], AppHarness::mercureHub()->updates);

		// Agents' changes go out too; a rejected one sends nothing.
		$client = new McpClient(OAuthFlow::accessToken($this->user, 'Claude Code'));
		$client->initialize();
		AppHarness::mercureHub()->updates = [];
		$isError = false;
		$client->call('update_project', ['projectId' => $this->project->id, 'name' => ' '], $isError);
		self::assertTrue($isError);
		self::assertSame([], AppHarness::mercureHub()->updates);
		$client->call('update_project', ['projectId' => $this->project->id, 'description' => 'Second floor, left']);
		self::assertSame(
			[['type' => 'detailsChanged', 'projectId' => $this->project->id, 'originClientId' => null]],
			AppHarness::mercureHub()->messages(),
		);
	}

	/** @return list<string> */
	private static function grantedTopics(string $cookie): array
	{
		self::assertSame(1, preg_match('/mercureAuthorization=([^;]+)/', $cookie, $match));
		// Verified with the subscriber key the hub uses.
		$decoded = JWT::decode($match[1], new Key((string) getenv('MERCURE_SUBSCRIBER_JWT_KEY'), 'HS256'));
		$claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($claims);
		self::assertSame('http://localhost/.well-known/mercure', $claims['aud']);
		self::assertSame('flat-designer', $claims['iss']);
		self::assertIsList($claims['authorization_details']);
		self::assertIsArray($claims['authorization_details'][0]);
		self::assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);
		self::assertIsList($claims['authorization_details'][0]['topics']);

		/** @var list<string> $topics */
		$topics = array_column($claims['authorization_details'][0]['topics'], 'match');
		sort($topics);

		return $topics;
	}
}
