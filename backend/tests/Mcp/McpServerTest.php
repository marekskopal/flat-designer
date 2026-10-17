<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Mcp;

use FlatDesigner\Controller\McpController;
use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Mcp\Server\ToolErrorTranslatingReferenceHandler;
use FlatDesigner\Mcp\Session\DatabaseSessionStore;
use FlatDesigner\Mcp\Tool\ItemTools;
use FlatDesigner\Mcp\Tool\OpeningTools;
use FlatDesigner\Mcp\Tool\ProjectTools;
use FlatDesigner\Mcp\Tool\WallTools;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Route\Routes;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use FlatDesigner\Tests\Support\McpClient;
use FlatDesigner\Tests\Support\OAuthFlow;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/** Drives the MCP server end to end over JSON-RPC, as Claude Code or claude.ai would. */
#[CoversClass(McpController::class)]
#[CoversClass(EditResultDto::class)]
#[CoversClass(DatabaseSessionStore::class)]
#[CoversClass(ToolErrorTranslatingReferenceHandler::class)]
#[CoversClass(ProjectTools::class)]
#[CoversClass(ItemTools::class)]
#[CoversClass(WallTools::class)]
#[CoversClass(OpeningTools::class)]
final class McpServerTest extends IntegrationTestCase
{
	private User $user;

	private Project $project;

	private McpClient $client;

	protected function setUp(): void
	{
		parent::setUp();

		$this->user = Fixture::createUser(name: 'Jane');
		$this->project = Fixture::createProject($this->user, 'Flat 12');
		$this->client = new McpClient(OAuthFlow::accessToken($this->user, 'Claude Code'));
		$this->client->initialize();
	}

	public function testListsAllTools(): void
	{
		$tools = $this->client->request('tools/list')['tools'];
		self::assertIsList($tools);

		$names = array_column($tools, 'name');
		sort($names);
		self::assertSame([
			'add_floor',
			'add_item',
			'add_opening',
			'add_room',
			'add_wall',
			'delete_item',
			'delete_opening',
			'delete_room',
			'delete_wall',
			'duplicate_item',
			'get_floor',
			'get_project',
			'list_changes',
			'list_item_kinds',
			'list_materials',
			'list_projects',
			'list_versions',
			'redo',
			'save_version',
			'undo',
			'update_item',
			'update_opening',
			'update_room',
			'update_wall',
		], $names);
	}

	public function testBuildsAKitchenWallAndLogsTheAgent(): void
	{
		$projects = $this->client->call('list_projects');
		self::assertIsList($projects);
		self::assertSame(['Flat 12'], array_column($projects, 'name'));
		$floorId = Fixture::firstFloor($this->project)->id;

		$wall = $this->client->call(
			'add_wall',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'startX' => 0, 'startY' => 3000, 'endX' => 4000, 'endY' => 3000, 'thickness' => 150],
		);
		self::assertIsArray($wall);
		self::assertIsArray($wall['element']);
		$wallId = $wall['element']['id'];

		$window = $this->client->call(
			'add_opening',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'wallId' => $wallId, 'type' => 'Window', 'offset' => 1500],
		);
		self::assertIsArray($window);
		self::assertIsArray($window['element']);
		self::assertSame(900, $window['element']['sillHeight']);

		$cabinet = $this->client->call(
			'add_item',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'BaseCabinet', 'x' => 300, 'y' => 2610, 'rotation' => 180, 'params' => ['plinth' => 100, 'handle' => 'horizontal']],
		);
		self::assertIsArray($cabinet);
		self::assertIsArray($cabinet['element']);
		self::assertSame(860, $cabinet['element']['height']);
		self::assertIsArray($cabinet['change']);
		self::assertSame('Added item "BaseCabinet"', $cabinet['change']['summary']);

		$floor = $this->client->call('get_floor', ['projectId' => $this->project->id, 'floorId' => $floorId]);
		self::assertIsArray($floor);
		self::assertIsList($floor['walls']);
		self::assertIsArray($floor['walls'][0]);
		self::assertIsList($floor['walls'][0]['openings']);
		self::assertCount(1, $floor['walls'][0]['openings']);

		// The person sees the agent's changes under the client's name.
		$changes = $this->jsonList(
			$this->request('GET', sprintf('/api/projects/%d/changes', $this->project->id), authenticatedAs: $this->user),
		);
		self::assertSame(['Claude Code', 'Claude Code', 'Claude Code'], array_column($changes, 'actorName'));
		self::assertSame(['Agent', 'Agent', 'Agent'], array_column($changes, 'actorType'));
	}

	public function testDomainErrorsReachTheAgentAsToolErrors(): void
	{
		$floorId = Fixture::firstFloor($this->project)->id;
		$isError = false;

		$this->client->call(
			'add_item',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'Spaceship', 'x' => 0, 'y' => 0],
			$isError,
		);
		self::assertTrue($isError);

		$message = $this->client->call(
			'add_item',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'Spaceship', 'x' => 0, 'y' => 0],
		);
		self::assertIsString($message);
		self::assertStringContainsString('Field "kind" must be one of', $message);

		$foreign = Fixture::createProject(Fixture::createUser(), 'Not yours');
		$notFound = $this->client->call('get_project', ['projectId' => $foreign->id], $isError);
		self::assertTrue($isError);
		self::assertIsString($notFound);
		self::assertStringContainsString('not found', $notFound);
	}

	public function testUpdateClearsFieldsAndAgentUndoKeepsThePersonsChanges(): void
	{
		$floor = Fixture::firstFloor($this->project);
		$room = $this->jsonBody(
			$this->request(
				'POST',
				sprintf('/api/projects/%d/floors/%d/rooms', $this->project->id, $floor->id),
				['name' => 'Kitchen'],
				$this->user,
			),
		);
		self::assertIsArray($room['element']);
		$roomId = $room['element']['id'];

		$added = $this->client->call(
			'add_item',
			['projectId' => $this->project->id, 'floorId' => $floor->id, 'kind' => 'Box', 'x' => 0, 'y' => 0, 'roomId' => $roomId],
		);
		self::assertIsArray($added);
		self::assertIsArray($added['element']);
		$itemId = $added['element']['id'];

		$cleared = $this->client->call(
			'update_item',
			['projectId' => $this->project->id, 'floorId' => $floor->id, 'itemId' => $itemId, 'x' => 500, 'clear' => ['roomId']],
		);
		self::assertIsArray($cleared);
		self::assertIsArray($cleared['element']);
		self::assertNull($cleared['element']['roomId']);
		self::assertSame(500, $cleared['element']['x']);

		// The agent undoes its update, then its add; the room the person added stays.
		$isError = false;
		$this->client->call('undo', ['projectId' => $this->project->id]);
		$this->client->call('undo', ['projectId' => $this->project->id]);
		$this->client->call('undo', ['projectId' => $this->project->id], $isError);
		self::assertTrue($isError);

		$scene = $this->jsonBody(
			$this->request(
				'GET',
				sprintf('/api/projects/%d/floors/%d/scene', $this->project->id, $floor->id),
				authenticatedAs: $this->user,
			),
		);
		self::assertSame([], $scene['items']);
		self::assertIsList($scene['rooms']);
		self::assertCount(1, $scene['rooms']);
	}

	public function testRequestsOfOneSessionWaitForEachOther(): void
	{
		// Another connection (as another worker would) holds the session while a request of it is running.
		$sessionId = $this->client->sessionId();
		self::assertIsString($sessionId);
		$other = new PDO(
			'mysql:host=' . getenv('MYSQL_HOST') . ';dbname=' . getenv('MYSQL_DATABASE'),
			(string) getenv('MYSQL_USER'),
			(string) getenv('MYSQL_PASSWORD'),
		);
		$lock = $other->prepare('SELECT GET_LOCK(?, 0)');
		self::assertNotFalse($lock);
		$lock->execute(['mcp:' . $sessionId]);
		self::assertSame(1, (int) $lock->fetchColumn());

		putenv('MCP_SESSION_LOCK_TIMEOUT=0');

		try {
			$busy = $this->client->post(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'tools/list']);
			self::assertSame(503, $busy->getStatusCode());

			$release = $other->prepare('SELECT RELEASE_LOCK(?)');
			self::assertNotFalse($release);
			$release->execute(['mcp:' . $sessionId]);
			self::assertNotSame([], $this->client->request('tools/list')['tools']);
		} finally {
			putenv('MCP_SESSION_LOCK_TIMEOUT');
		}
	}

	public function testEmptyItemParamsAreAJsonObject(): void
	{
		$floorId = Fixture::firstFloor($this->project)->id;
		$result = $this->client->request('tools/call', [
			'name' => 'add_item',
			'arguments' => ['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'Box', 'x' => 0, 'y' => 0],
		]);
		self::assertIsList($result['content']);
		self::assertIsArray($result['content'][0]);
		self::assertIsString($result['content'][0]['text']);
		self::assertMatchesRegularExpression('/"params":\s*\{\}/', $result['content'][0]['text']);
	}

	public function testRequestsWithoutAValidTokenAreRejected(): void
	{
		$response = $this->request('POST', Routes::Mcp->value, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
		self::assertSame(401, $response->getStatusCode());
		self::assertStringContainsString('/.well-known/oauth-protected-resource/mcp', $response->getHeaderLine('WWW-Authenticate'));

		// The app's own JWT is not an MCP token.
		self::assertSame(
			401,
			$this->request('POST', Routes::Mcp->value, [], bearerToken: Fixture::accessTokenFor($this->user))->getStatusCode(),
		);
	}
}
