<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Mcp;

use FlatDesigner\Controller\McpController;
use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Mcp\Server\EmptyObjectSchemaValidator;
use FlatDesigner\Mcp\Server\ToolErrorTranslatingReferenceHandler;
use FlatDesigner\Mcp\Session\DatabaseSessionStore;
use FlatDesigner\Mcp\Tool\FloorTools;
use FlatDesigner\Mcp\Tool\FurnitureTools;
use FlatDesigner\Mcp\Tool\GroupTools;
use FlatDesigner\Mcp\Tool\ItemTools;
use FlatDesigner\Mcp\Tool\OpeningTools;
use FlatDesigner\Mcp\Tool\ProjectTools;
use FlatDesigner\Mcp\Tool\ShareLinkTools;
use FlatDesigner\Mcp\Tool\VariantTools;
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
use stdClass;

/** Drives the MCP server end to end over JSON-RPC, as Claude Code or claude.ai would. */
#[CoversClass(McpController::class)]
#[CoversClass(EditResultDto::class)]
#[CoversClass(DatabaseSessionStore::class)]
#[CoversClass(ToolErrorTranslatingReferenceHandler::class)]
#[CoversClass(EmptyObjectSchemaValidator::class)]
#[CoversClass(ProjectTools::class)]
#[CoversClass(ShareLinkTools::class)]
#[CoversClass(ItemTools::class)]
#[CoversClass(GroupTools::class)]
#[CoversClass(FurnitureTools::class)]
#[CoversClass(FloorTools::class)]
#[CoversClass(VariantTools::class)]
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
			'add_variant',
			'add_wall',
			'copy_furniture_definition',
			'create_furniture_definition',
			'create_group',
			'create_share_link',
			'create_variant_set',
			'delete_furniture_definition',
			'delete_group',
			'delete_item',
			'delete_opening',
			'delete_room',
			'delete_share_link',
			'delete_variant_set',
			'delete_wall',
			'duplicate_group',
			'duplicate_item',
			'get_cut_list',
			'get_floor',
			'get_furniture_definition',
			'get_project',
			'list_changes',
			'list_furniture_definitions',
			'list_item_kinds',
			'list_materials',
			'list_projects',
			'list_share_links',
			'list_versions',
			'move_group',
			'redo',
			'refresh_share_link',
			'rename_group',
			'rename_variant_set',
			'save_version',
			'switch_variant',
			'undo',
			'ungroup',
			'update_furniture_definition',
			'update_item',
			'update_opening',
			'update_room',
			'update_share_link',
			'update_wall',
			'validate_furniture_definition',
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

	public function testGroupsMoveTheirItemsAsOneUndoStep(): void
	{
		$ids = ['projectId' => $this->project->id, 'floorId' => Fixture::firstFloor($this->project)->id];
		$itemIds = [];
		foreach ([0, 1000] as $x) {
			$item = $this->client->call('add_item', $ids + ['kind' => 'Box', 'x' => $x, 'y' => 0]);
			self::assertIsArray($item);
			self::assertIsArray($item['element']);
			$itemIds[] = $item['element']['id'];
		}

		$group = $this->client->call('create_group', $ids + ['name' => 'Bench', 'itemIds' => $itemIds]);
		self::assertIsArray($group);
		self::assertIsArray($group['element']);
		$groupId = $group['element']['id'];

		$moved = $this->client->call('move_group', $ids + ['groupId' => $groupId, 'dy' => 250]);
		self::assertIsArray($moved);
		self::assertIsList($moved['batch']);
		self::assertCount(2, $moved['batch']);

		$floor = $this->client->call('get_floor', $ids);
		self::assertIsArray($floor);
		self::assertSame([['id' => $groupId, 'name' => 'Bench', 'variantSetId' => null]], $floor['groups']);
		self::assertIsList($floor['items']);
		self::assertSame([250, 250], array_column($floor['items'], 'y'));
		self::assertSame([$groupId, $groupId], array_column($floor['items'], 'groupId'));

		$this->client->call('undo', ['projectId' => $this->project->id]);
		$floor = $this->client->call('get_floor', $ids);
		self::assertIsArray($floor);
		self::assertIsList($floor['items']);
		self::assertSame([0, 0], array_column($floor['items'], 'y'));
	}

	public function testVariantsHideTheItemsOfTheOtherVariants(): void
	{
		$ids = ['projectId' => $this->project->id, 'floorId' => Fixture::firstFloor($this->project)->id];
		$island = $this->client->call('add_item', $ids + ['kind' => 'BaseCabinet', 'name' => 'Island', 'x' => 2000, 'y' => 2000]);
		self::assertIsArray($island);
		self::assertIsArray($island['element']);
		$group = $this->client->call('create_group', $ids + ['name' => 'Island', 'itemIds' => [$island['element']['id']]]);
		self::assertIsArray($group);
		self::assertIsArray($group['element']);
		$islandGroupId = $group['element']['id'];

		$set = $this->client->call('create_variant_set', $ids + ['name' => 'Kitchen layout', 'groupIds' => [$islandGroupId]]);
		self::assertIsArray($set);
		self::assertIsArray($set['element']);
		$setId = $set['element']['id'];
		self::assertSame($islandGroupId, $set['element']['activeGroupId']);

		$empty = $this->client->call('add_variant', $ids + ['variantSetId' => $setId, 'name' => 'Without island']);
		self::assertIsArray($empty);
		self::assertIsArray($empty['element']);

		$floor = $this->client->call('get_floor', $ids);
		self::assertIsArray($floor);
		self::assertSame([['id' => $setId, 'name' => 'Kitchen layout', 'activeGroupId' => $empty['element']['id']]], $floor['variantSets']);
		self::assertSame([$island['element']['id']], $floor['hiddenItemIds']);

		$this->client->call('switch_variant', $ids + ['variantSetId' => $setId, 'groupId' => $islandGroupId]);
		$floor = $this->client->call('get_floor', $ids);
		self::assertIsArray($floor);
		self::assertSame([], $floor['hiddenItemIds']);
	}

	public function testManagesShareLinks(): void
	{
		$projectId = $this->project->id;
		$camera = ['position' => ['x' => 1000, 'y' => -2000, 'z' => 5000], 'target' => ['x' => 3000, 'y' => 2000, 'z' => 0]];
		$link = $this->client->call('create_share_link', [
			'projectId' => $projectId,
			'floorId' => Fixture::firstFloor($this->project)->id,
			'name' => 'For the carpenter',
			'camera' => $camera,
		]);
		self::assertIsArray($link);
		self::assertIsArray($link['settings']);
		self::assertSame(['overview', 'top'], $link['settings']['views']);
		self::assertSame('overview', $link['settings']['initialView']);
		self::assertSame($camera, $link['settings']['camera']);
		self::assertSame('http://localhost:4400/s/' . self::stringField($link['token']), $link['url']);
		self::assertSame(1, $link['versionNumber']);

		// Narrowing the views to the plan moves the start view there; the camera is cleared with it.
		$updated = $this->client->call('update_share_link', [
			'projectId' => $projectId,
			'shareLinkId' => $link['id'],
			'views' => ['top'],
			'sectionHeight' => 1300,
			'clear' => ['camera'],
		]);
		self::assertIsArray($updated);
		self::assertIsArray($updated['settings']);
		self::assertSame('For the carpenter', $updated['name']);
		self::assertSame(['top'], $updated['settings']['views']);
		self::assertSame('top', $updated['settings']['initialView']);
		self::assertNull($updated['settings']['camera']);
		self::assertSame(1300, $updated['settings']['sectionHeight']);

		$isError = false;
		$message = $this->client->call(
			'update_share_link',
			['projectId' => $projectId, 'shareLinkId' => $link['id'], 'camera' => $camera],
			$isError,
		);
		self::assertTrue($isError);
		self::assertSame('A camera position needs the 3D view allowed.', $message);

		$refreshed = $this->client->call('refresh_share_link', ['projectId' => $projectId, 'shareLinkId' => $link['id']]);
		self::assertIsArray($refreshed);
		self::assertSame($link['token'], $refreshed['token']);
		self::assertSame(2, $refreshed['versionNumber']);

		$list = $this->client->call('list_share_links', ['projectId' => $projectId]);
		self::assertIsList($list);
		self::assertSame(['For the carpenter'], array_column($list, 'name'));

		$this->client->call('delete_share_link', ['projectId' => $projectId, 'shareLinkId' => $link['id']]);
		self::assertSame([], $this->client->call('list_share_links', ['projectId' => $projectId]));
	}

	public function testDesignsCustomFurnitureAndPlacesIt(): void
	{
		$projectId = $this->project->id;
		$floorId = Fixture::firstFloor($this->project)->id;
		$definition = [
			'format' => 1,
			'size' => ['width' => 600, 'depth' => 300, 'height' => 900],
			'parameters' => [['key' => 't', 'type' => 'int', 'default' => 18, 'min' => 10, 'max' => 40]],
			'materialSlots' => [['key' => 'carcass', 'default' => 'kitchen-oak']],
			'parts' => [
				['type' => 'board', 'name' => 'Side', 'repeat' => ['count' => 2], 'x' => 'i * (W - t)', 'y' => 0, 'z' => 0, 'w' => 't', 'h' => 'H', 'd' => 'D', 'material' => 'carcass'],
				['type' => 'board', 'name' => 'Top', 'x' => 't', 'y' => 'H - t', 'z' => 0, 'w' => 'W - 2*t', 'h' => 't', 'd' => 'D', 'material' => 'carcass'],
			],
		];
		self::assertSame([], $this->client->call('list_furniture_definitions'));

		// The agent checks its geometry first, also at another size.
		$broken = $definition;
		$broken['parts'][1]['w'] = 'W - 2*thickness';
		$check = $this->client->call('validate_furniture_definition', ['definition' => $broken]);
		self::assertIsArray($check);
		self::assertFalse($check['valid']);
		self::assertIsArray($check['error']);
		self::assertSame('parts[1].w', $check['error']['path']);
		$check = $this->client->call(
			'validate_furniture_definition',
			['definition' => $definition, 'width' => 1000, 'values' => ['t' => 25]],
		);
		self::assertIsArray($check);
		self::assertTrue($check['valid']);
		self::assertIsList($check['parts']);
		self::assertSame([0, 975, 25], array_column($check['parts'], 'x'));

		$created = $this->client->call('create_furniture_definition', ['name' => 'Low shelf', 'definition' => $definition]);
		self::assertIsArray($created);
		self::assertSame('user', $created['scope']);
		$definitionId = $created['id'];
		$listed = $this->client->call('list_furniture_definitions', ['projectId' => $projectId]);
		self::assertIsList($listed);
		self::assertIsArray($listed[0]);
		self::assertSame(['Low shelf', ['width' => 600, 'depth' => 300, 'height' => 900]], [$listed[0]['name'], $listed[0]['size']]);

		$added = $this->client->call(
			'add_item',
			['projectId' => $projectId, 'floorId' => $floorId, 'kind' => 'Custom', 'definitionId' => $definitionId, 'x' => 1000, 'y' => 500],
		);
		self::assertIsArray($added);
		self::assertIsArray($added['element']);
		self::assertSame(
			[$definitionId, 'Low shelf', 600],
			[$added['element']['definitionId'], $added['element']['name'], $added['element']['width']],
		);
		$floor = $this->client->call('get_floor', ['projectId' => $projectId, 'floorId' => $floorId]);
		self::assertIsArray($floor);
		self::assertIsList($floor['definitions']);
		self::assertSame([$definitionId], array_column($floor['definitions'], 'id'));

		$this->client->call('update_furniture_definition', ['definitionId' => $definitionId, 'name' => 'Low oak shelf']);
		$read = $this->client->call('get_furniture_definition', ['definitionId' => $definitionId]);
		self::assertIsArray($read);
		self::assertSame(['Low oak shelf', 1], [$read['name'], $read['itemCount']]);
		self::assertIsArray($read['definition']);
		self::assertSame($definition['parts'], $read['definition']['parts']);

		// Back to a plain box: the definition is cleared with the kind.
		$box = $this->client->call('update_item', [
			'projectId' => $projectId,
			'floorId' => $floorId,
			'itemId' => $added['element']['id'],
			'kind' => 'Box',
			'params' => [],
			'clear' => ['definitionId'],
		]);
		self::assertIsArray($box);
		self::assertIsArray($box['element']);
		self::assertSame(['Box', null], [$box['element']['kind'], $box['element']['definitionId']]);

		$copy = $this->client->call(
			'copy_furniture_definition',
			['definitionId' => $definitionId, 'scope' => 'project', 'projectId' => $projectId],
		);
		self::assertIsArray($copy);
		self::assertSame(['project', $projectId], [$copy['scope'], $copy['projectId']]);
		$deleted = $this->client->call('delete_furniture_definition', ['definitionId' => $definitionId]);
		self::assertIsArray($deleted);
		self::assertSame('Low oak shelf', $deleted['name']);
		$isError = false;
		$message = $this->client->call('get_furniture_definition', ['definitionId' => $definitionId], $isError);
		self::assertTrue($isError);
		self::assertIsString($message);
		self::assertStringStartsWith('Furniture definition', $message);
		self::assertStringEndsWith('not found. Use list_furniture_definitions to see the available ones.', $message);
		$message = $this->client->call(
			'create_furniture_definition',
			['name' => 'Everyone\'s', 'definition' => $definition, 'scope' => 'global'],
			$isError,
		);
		self::assertSame('Only admins manage global furniture.', $message);
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
			self::assertStringContainsString('-32000', (string) $busy->getBody());

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
		$response = $this->client->post([
			'jsonrpc' => '2.0',
			'id' => 99,
			'method' => 'tools/call',
			'params' => [
				'name' => 'add_item',
				'arguments' => ['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'Box', 'x' => 0, 'y' => 0],
			],
		]);
		$body = (string) $response->getBody();

		// Both in the text content (escaped JSON inside a string) and in structuredContent.
		self::assertMatchesRegularExpression('/\\\\"params\\\\":\\s*\\{\\}/', $body);
		self::assertMatchesRegularExpression('/[^\\\\]"params":\\s*\\{\\}/', $body);
	}

	public function testAcceptsEmptyItemParams(): void
	{
		$floorId = Fixture::firstFloor($this->project)->id;
		$isError = false;

		// `new stdClass()` is sent as `{}`, which the SDK decodes into `[]`.
		$box = $this->client->call(
			'add_item',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'Box', 'x' => 0, 'y' => 0, 'params' => new stdClass()],
			$isError,
		);
		self::assertFalse($isError, is_string($box) ? $box : '');
		self::assertIsArray($box);
		self::assertIsArray($box['element']);
		self::assertSame([], $box['element']['params']);

		$updated = $this->client->call(
			'update_item',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'itemId' => $box['element']['id'], 'params' => new stdClass()],
			$isError,
		);
		self::assertFalse($isError, is_string($updated) ? $updated : '');
	}

	public function testAddsAWardrobe(): void
	{
		$floorId = Fixture::firstFloor($this->project)->id;
		$isError = false;
		$wardrobe = $this->client->call('add_item', [
			'projectId' => $this->project->id,
			'floorId' => $floorId,
			'kind' => 'Wardrobe',
			'x' => 1200,
			'y' => 300,
			'width' => 2468,
			'params' => ['doors' => 4, 'mirrorDoors' => [3]],
		], $isError);
		self::assertFalse($isError);
		self::assertIsArray($wardrobe);
		self::assertIsArray($wardrobe['element']);
		self::assertSame('Wardrobe', $wardrobe['element']['kind']);
		self::assertSame(['doors' => 4, 'mirrorDoors' => [3]], $wardrobe['element']['params']);

		$message = $this->client->call('add_item', [
			'projectId' => $this->project->id,
			'floorId' => $floorId,
			'kind' => 'Wardrobe',
			'x' => 0,
			'y' => 0,
			'params' => ['doors' => 3, 'mirrorDoors' => [4]],
		], $isError);
		self::assertTrue($isError);
		self::assertIsString($message);
		self::assertStringContainsString('mirrorDoors', $message);
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
