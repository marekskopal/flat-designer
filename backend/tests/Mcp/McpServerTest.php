<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Mcp;

use FlatDesigner\Controller\McpController;
use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Mcp\Server\EmptyObjectSchemaValidator;
use FlatDesigner\Mcp\Server\ToolErrorTranslatingReferenceHandler;
use FlatDesigner\Mcp\Session\DatabaseSessionStore;
use FlatDesigner\Mcp\Tool\CommentTools;
use FlatDesigner\Mcp\Tool\FileTools;
use FlatDesigner\Mcp\Tool\FloorTools;
use FlatDesigner\Mcp\Tool\FurnitureTools;
use FlatDesigner\Mcp\Tool\GroupTools;
use FlatDesigner\Mcp\Tool\ItemTools;
use FlatDesigner\Mcp\Tool\MaterialTools;
use FlatDesigner\Mcp\Tool\ModelTools;
use FlatDesigner\Mcp\Tool\OpeningTools;
use FlatDesigner\Mcp\Tool\ProjectTools;
use FlatDesigner\Mcp\Tool\RoomTools;
use FlatDesigner\Mcp\Tool\ShareLinkTools;
use FlatDesigner\Mcp\Tool\UnderlayTools;
use FlatDesigner\Mcp\Tool\UploadContent;
use FlatDesigner\Mcp\Tool\VariantTools;
use FlatDesigner\Mcp\Tool\WallTools;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Material\MaterialService;
use FlatDesigner\Service\Project\ImagePreviewService;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\Glb;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use FlatDesigner\Tests\Support\McpClient;
use FlatDesigner\Tests\Support\OAuthFlow;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;
use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/** Drives the MCP server end to end over JSON-RPC, as Claude Code or claude.ai would. */
#[CoversClass(McpController::class)]
#[CoversClass(EditResultDto::class)]
#[CoversClass(DatabaseSessionStore::class)]
#[CoversClass(ToolErrorTranslatingReferenceHandler::class)]
#[CoversClass(EmptyObjectSchemaValidator::class)]
#[CoversClass(ProjectTools::class)]
#[CoversClass(ShareLinkTools::class)]
#[CoversClass(CommentTools::class)]
#[CoversClass(ItemTools::class)]
#[CoversClass(GroupTools::class)]
#[CoversClass(FurnitureTools::class)]
#[CoversClass(FloorTools::class)]
#[CoversClass(VariantTools::class)]
#[CoversClass(WallTools::class)]
#[CoversClass(RoomTools::class)]
#[CoversClass(OpeningTools::class)]
#[CoversClass(MaterialTools::class)]
#[CoversClass(ModelTools::class)]
#[CoversClass(UploadContent::class)]
#[CoversClass(MaterialService::class)]
#[CoversClass(FileTools::class)]
#[CoversClass(UnderlayTools::class)]
#[CoversClass(ImagePreviewService::class)]
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
			'add_comment',
			'add_floor',
			'add_item',
			'add_material',
			'add_opening',
			'add_plot',
			'add_roof',
			'add_roof_window',
			'add_room',
			'add_stair',
			'add_variant',
			'add_wall',
			'add_walls',
			'copy_furniture_definition',
			'create_furniture_definition',
			'create_group',
			'create_project',
			'create_share_link',
			'create_variant_set',
			'delete_comment',
			'delete_floor',
			'delete_furniture_definition',
			'delete_group',
			'delete_item',
			'delete_model',
			'delete_opening',
			'delete_plot',
			'delete_project',
			'delete_project_file',
			'delete_roof',
			'delete_roof_window',
			'delete_room',
			'delete_share_link',
			'delete_stair',
			'delete_variant_set',
			'delete_view',
			'delete_wall',
			'detect_room',
			'duplicate_group',
			'duplicate_item',
			'find_room_outline',
			'get_cut_list',
			'get_electrical_schedule',
			'get_floor',
			'get_furniture_definition',
			'get_project',
			'get_project_file',
			'get_quantities',
			'get_room_schedule',
			'list_changes',
			'list_comments',
			'list_furniture_definitions',
			'list_item_kinds',
			'list_materials',
			'list_models',
			'list_project_files',
			'list_projects',
			'list_share_links',
			'list_versions',
			'list_views',
			'move_group',
			'redo',
			'refresh_share_link',
			'remove_floor_underlay',
			'rename_group',
			'rename_variant_set',
			'request_file_upload',
			'request_model_upload',
			'resolve_comment',
			'save_version',
			'save_view',
			'set_floor_underlay',
			'set_material_prices',
			'switch_variant',
			'undo',
			'ungroup',
			'update_floor',
			'update_furniture_definition',
			'update_item',
			'update_material',
			'update_opening',
			'update_plot',
			'update_project',
			'update_roof',
			'update_roof_window',
			'update_room',
			'update_share_link',
			'update_stair',
			'update_wall',
			'upload_model',
			'upload_project_file',
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

		$frenchWindow = $this->client->call(
			'update_opening',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'openingId' => $window['element']['id'], 'sillHeight' => 0, 'height' => 2200, 'walkable' => true],
		);
		self::assertIsArray($frenchWindow);
		self::assertIsArray($frenchWindow['element']);
		self::assertTrue($frenchWindow['element']['walkable']);

		$cabinet = $this->client->call(
			'add_item',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'Sink', 'x' => 300, 'y' => 2610, 'rotation' => 180, 'params' => ['bowls' => 2]],
		);
		self::assertIsArray($cabinet);
		self::assertIsArray($cabinet['element']);
		self::assertSame(320, $cabinet['element']['height']);
		self::assertIsArray($cabinet['change']);
		self::assertSame('Added item "Sink"', $cabinet['change']['summary']);

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
		self::assertSame(['Claude Code', 'Claude Code', 'Claude Code', 'Claude Code'], array_column($changes, 'actorName'));
		self::assertSame(['Agent', 'Agent', 'Agent', 'Agent'], array_column($changes, 'actorType'));

		$slidingDoor = $this->client->call(
			'add_opening',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'wallId' => $wallId, 'type' => 'SlidingDoor', 'offset' => 100, 'width' => 600, 'hinge' => 'End', 'side' => 'Right'],
		);
		self::assertIsArray($slidingDoor);
		self::assertIsArray($slidingDoor['change']);
		self::assertSame('Added sliding door "Sliding door"', $slidingDoor['change']['summary']);
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

	public function testWallFacesTakeFinishesOfTheirOwn(): void
	{
		$floor = Fixture::firstFloor($this->project);
		$band = ['materialId' => 1, 'bottom' => 0, 'top' => 2100];
		$added = $this->client->call('add_wall', [
			'projectId' => $this->project->id,
			'floorId' => $floor->id,
			'startX' => 0,
			'startY' => 0,
			'endX' => 3000,
			'endY' => 0,
			'rightFinishes' => [$band],
		]);
		self::assertIsArray($added);
		self::assertIsArray($added['element']);
		self::assertSame([null, [$band]], [$added['element']['leftFinishes'], $added['element']['rightFinishes']]);

		$cleared = $this->client->call('update_wall', [
			'projectId' => $this->project->id,
			'floorId' => $floor->id,
			'wallId' => $added['element']['id'],
			'leftFinishes' => [],
			'clear' => ['rightFinishes'],
		]);
		self::assertIsArray($cleared);
		self::assertIsArray($cleared['element']);
		self::assertSame([[], null], [$cleared['element']['leftFinishes'], $cleared['element']['rightFinishes']]);
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

	public function testDrawsWallsAndDetectsTheRoomInside(): void
	{
		$ids = ['projectId' => $this->project->id, 'floorId' => Fixture::firstFloor($this->project)->id];

		$walls = $this->client->call('add_walls', $ids + [
			'points' => [[-150, -150], [4150, -150], [4150, 3150], [-150, 3150]],
			'closed' => true,
			'thickness' => 300,
			'upToRoof' => true,
		]);
		self::assertIsArray($walls);
		self::assertIsList($walls['batch']);
		self::assertCount(4, $walls['batch']);
		$floor = $this->client->call('get_floor', $ids);
		self::assertIsArray($floor);
		self::assertIsList($floor['walls']);
		self::assertSame([true, true, true, true], array_column($floor['walls'], 'upToRoof'));

		$outline = $this->client->call('find_room_outline', $ids + ['x' => 2000, 'y' => 1500]);
		self::assertSame(['polygon' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]]], $outline);

		$room = $this->client->call('detect_room', $ids + ['x' => 2000, 'y' => 1500, 'name' => 'Living room']);
		self::assertIsArray($room);
		self::assertIsArray($room['element']);
		self::assertSame('Living room', $room['element']['name']);
		self::assertEqualsWithDelta(12.0, $room['element']['area'], 0.001);
		self::assertEqualsWithDelta(14.0, $room['element']['perimeter'], 0.001);

		$schedule = $this->client->call('get_room_schedule', ['projectId' => $this->project->id]);
		self::assertIsArray($schedule);
		self::assertEquals(['rooms' => 1, 'area' => 12.0, 'perimeter' => 14.0], $schedule['indoor']);
		self::assertEquals(['rooms' => 0, 'area' => 0.0, 'perimeter' => 0.0], $schedule['outdoor']);
		$electrical = $this->client->call('get_electrical_schedule', ['projectId' => $this->project->id]);
		self::assertIsArray($electrical);
		self::assertEquals(
			['lights' => 0, 'sockets' => 0, 'outlets' => 0, 'switches' => 0, 'dataSockets' => 0, 'plumbingPoints' => 0],
			$electrical['totals'],
		);

		// Walls without a material are the default paint: 14 m around, 2.6 m high; the ceiling white plaster.
		$prices = $this->client->call('set_material_prices', [
			'projectId' => $this->project->id,
			'prices' => [['surface' => 'Wall', 'pricePerArea' => 5], ['surface' => 'Ceiling', 'pricePerArea' => 10]],
			'currency' => 'czk',
		]);
		self::assertIsArray($prices);
		self::assertSame('CZK', $prices['currency']);
		$quantities = $this->client->call('get_quantities', ['projectId' => $this->project->id]);
		self::assertIsArray($quantities);
		self::assertTrue($quantities['complete']);
		self::assertEqualsWithDelta(14 * 2.6 * 5 + 12 * 10, $quantities['total'], 0.01);

		$this->client->call('undo', ['projectId' => $this->project->id]);
		$this->client->call('undo', ['projectId' => $this->project->id]);
		$floor = $this->client->call('get_floor', $ids);
		self::assertIsArray($floor);
		self::assertSame([], $floor['rooms']);
		self::assertSame([], $floor['walls']);
	}

	public function testVariantsHideTheItemsOfTheOtherVariants(): void
	{
		$ids = ['projectId' => $this->project->id, 'floorId' => Fixture::firstFloor($this->project)->id];
		$island = $this->client->call('add_item', $ids + ['kind' => 'Box', 'name' => 'Island', 'x' => 2000, 'y' => 2000]);
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

		// A sun time needs both halves at first; later one of them changes alone, and `clear` drops it.
		$sunArguments = ['projectId' => $projectId, 'shareLinkId' => $link['id']];
		$isError = false;
		$this->client->call('update_share_link', [...$sunArguments, 'sunHour' => 10.0], $isError);
		self::assertTrue($isError);
		$updated = $this->client->call('update_share_link', [...$sunArguments, 'sunMonth' => 6, 'sunHour' => 15.0]);
		self::assertIsArray($updated);
		self::assertIsArray($updated['settings']);
		self::assertEquals(['month' => 6, 'hour' => 15.0], $updated['settings']['sun']);
		$updated = $this->client->call('update_share_link', [...$sunArguments, 'sunHour' => 10.0]);
		self::assertIsArray($updated);
		self::assertIsArray($updated['settings']);
		self::assertEquals(['month' => 6, 'hour' => 10.0], $updated['settings']['sun']);
		$updated = $this->client->call('update_share_link', [...$sunArguments, 'clear' => ['sun']]);
		self::assertIsArray($updated);
		self::assertIsArray($updated['settings']);
		self::assertNull($updated['settings']['sun']);

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

	public function testReadsAndAnswersReviewComments(): void
	{
		$floorId = Fixture::firstFloor($this->project)->id;
		$path = sprintf('/api/projects/%d/comments', $this->project->id);
		$created = $this->request(
			'POST',
			$path,
			['floorId' => $floorId, 'x' => 1200, 'y' => 900, 'z' => 0, 'body' => 'Wider door?'],
			$this->user,
		);
		$threadId = $this->jsonBody($created)['id'];
		self::assertIsInt($threadId);

		$open = $this->client->call('list_comments', ['projectId' => $this->project->id, 'floorId' => $floorId]);
		self::assertIsList($open);
		self::assertSame(['Wider door?'], array_column($open, 'body'));
		self::assertSame(['Jane'], array_column($open, 'authorName'));

		$reply = $this->client->call(
			'add_comment',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'parentId' => $threadId, 'body' => 'Made it 900 mm.'],
		);
		self::assertIsArray($reply);
		self::assertSame(
			['Agent', 'Claude Code', 1200, true],
			[$reply['authorType'], $reply['authorName'], $reply['x'], $reply['canDelete']],
		);
		$pinned = $this->client->call(
			'add_comment',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'point' => ['x' => 10, 'y' => 20, 'z' => 900], 'body' => 'Socket here.'],
		);
		self::assertIsArray($pinned);
		self::assertSame([10, 20, 900, 2], [$pinned['x'], $pinned['y'], $pinned['z'], $pinned['number']]);

		$resolved = $this->client->call('resolve_comment', ['projectId' => $this->project->id, 'commentId' => $threadId]);
		self::assertIsArray($resolved);
		self::assertTrue($resolved['resolved']);
		$open = $this->client->call('list_comments', ['projectId' => $this->project->id]);
		self::assertIsList($open);
		self::assertSame(['Socket here.'], array_column($open, 'body'));

		// The agent deletes only its own comments.
		$isError = false;
		$this->client->call('delete_comment', ['projectId' => $this->project->id, 'commentId' => $threadId], $isError);
		self::assertTrue($isError);
		$deleted = $this->client->call('delete_comment', ['projectId' => $this->project->id, 'commentId' => $pinned['id']]);
		self::assertIsArray($deleted);
		$all = $this->jsonList($this->request('GET', $path, authenticatedAs: $this->user));
		self::assertSame(['Wider door?', 'Made it 900 mm.'], array_column($all, 'body'));
		// The person may delete the agent's reply, not edit it.
		self::assertSame([false, true], [$all[1]['canEdit'], $all[1]['canDelete']]);
	}

	public function testAddsUpdatesAndDeletesFloors(): void
	{
		$attic = $this->client->call('add_floor', ['projectId' => $this->project->id, 'name' => 'Attic', 'level' => 1]);
		self::assertIsArray($attic);
		self::assertIsInt($attic['id']);
		self::assertSame([2850, 250], [$attic['elevation'], $attic['slabThickness']]);

		// Only the given fields change.
		$updated = $this->client->call('update_floor', ['projectId' => $this->project->id, 'floorId' => $attic['id'], 'elevation' => 3000]);
		self::assertIsArray($updated);
		self::assertSame(['Attic', 1, 3000, 250], [$updated['name'], $updated['level'], $updated['elevation'], $updated['slabThickness']]);

		$deleted = $this->client->call('delete_floor', ['projectId' => $this->project->id, 'floorId' => $attic['id']]);
		self::assertIsArray($deleted);
		self::assertSame('Attic', $deleted['name']);
		// The last floor stays.
		$floorId = Fixture::firstFloor($this->project)->id;
		$isError = false;
		$this->client->call('delete_floor', ['projectId' => $this->project->id, 'floorId' => $floorId], $isError);
		self::assertTrue($isError);
	}

	public function testCreatesUpdatesAndDeletesProjects(): void
	{
		$created = $this->client->call('create_project', ['name' => ' Cottage ', 'address' => 'Lipová 3, Brno']);
		self::assertIsArray($created);
		self::assertIsInt($created['id']);
		self::assertSame(['Cottage', 'Lipová 3, Brno', null], [$created['name'], $created['address'], $created['description']]);
		self::assertIsList($created['floors']);
		self::assertSame([['Ground floor', 0]], array_map(
			static fn (mixed $floor): array => is_array($floor) ? [$floor['name'], $floor['level']] : [],
			$created['floors'],
		));
		$listed = $this->client->call('list_projects');
		self::assertIsList($listed);
		self::assertEqualsCanonicalizing(['Cottage', 'Flat 12'], array_column($listed, 'name'));

		// Only the given fields change; clear wins over a value given for the same field.
		$updated = $this->client->call('update_project', ['projectId' => $created['id'], 'description' => 'Summer house']);
		self::assertIsArray($updated);
		self::assertSame(['Cottage', 'Lipová 3, Brno', 'Summer house'], [$updated['name'], $updated['address'], $updated['description']]);
		$updated = $this->client->call(
			'update_project',
			['projectId' => $created['id'], 'name' => 'Cottage by the lake', 'address' => 'x', 'clear' => ['address']],
		);
		self::assertIsArray($updated);
		self::assertSame(['Cottage by the lake', null, 'Summer house'], [$updated['name'], $updated['address'], $updated['description']]);

		// The site: kept by updates of other fields, its location cleared as one.
		$site = ['northAngle' => 20, 'latitude' => 49.2, 'longitude' => 16.6, 'timeZone' => 'Europe/Prague'];
		$this->client->call('update_project', ['projectId' => $created['id'], ...$site]);
		$updated = $this->client->call('update_project', ['projectId' => $created['id'], 'description' => 'By the lake']);
		self::assertIsArray($updated);
		self::assertEquals($site, array_intersect_key($updated, $site));
		$updated = $this->client->call('update_project', ['projectId' => $created['id'], 'clear' => ['location']]);
		self::assertIsArray($updated);
		self::assertEquals(
			[20, null, null, 'Europe/Prague'],
			[$updated['northAngle'], $updated['latitude'], $updated['longitude'], $updated['timeZone']],
		);

		$isError = false;
		self::assertSame(
			'Project name is required (at most 255 characters).',
			$this->client->call('update_project', ['projectId' => $created['id'], 'name' => '  '], $isError),
		);
		self::assertTrue($isError);
		$message = $this->client->call('update_project', ['projectId' => $created['id'], 'clear' => ['name']], $isError);
		self::assertTrue($isError);
		self::assertIsString($message);
		self::assertStringStartsWith('"name" cannot be cleared', $message);

		// Someone else's project is not found, as in the app.
		$stranger = new McpClient(OAuthFlow::accessToken(Fixture::createUser(name: 'Bob'), 'Claude Code'));
		$stranger->initialize();
		foreach (['update_project' => ['name' => 'Mine'], 'delete_project' => []] as $tool => $arguments) {
			self::assertSame(
				sprintf('Project %d not found. Use list_projects to see the available ones.', $created['id']),
				$stranger->call($tool, ['projectId' => $created['id'], ...$arguments], $isError),
			);
			self::assertTrue($isError);
		}

		$deleted = $this->client->call('delete_project', ['projectId' => $created['id']]);
		self::assertIsArray($deleted);
		self::assertSame('Cottage by the lake', $deleted['name']);
		$listed = $this->client->call('list_projects');
		self::assertIsList($listed);
		self::assertSame(['Flat 12'], array_column($listed, 'name'));
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

	public function testAdminsManageMaterials(): void
	{
		$oak = [
			'code' => 'walnut-veneer',
			'name' => 'Oak veneer, natural',
			'pattern' => 'Wood',
			'color' => '#C49A6C',
			'roughness' => 0.55,
		];
		$isError = false;
		self::assertSame('Only admins manage materials.', $this->client->call('add_material', $oak, $isError));
		self::assertTrue($isError);

		$admin = new McpClient(OAuthFlow::accessToken(Fixture::createAdmin(), 'Claude Code'));
		$admin->initialize();
		$added = $admin->call('add_material', $oak);
		self::assertIsArray($added);
		self::assertIsInt($added['id']);
		self::assertSame(
			['walnut-veneer', '#c49a6c', 0.55, 0, false, null],
			[$added['code'], $added['color'], $added['roughness'], $added['metalness'], $added['textured'], $added['tileWidth']],
		);
		$listed = $this->client->call('list_materials');
		self::assertIsList($listed);
		self::assertContains($added, $listed);

		$message = $admin->call('add_material', $oak, $isError);
		self::assertTrue($isError);
		self::assertSame(sprintf('Material code "walnut-veneer" exists (id %d).', $added['id']), $message);
		foreach ([
			[['color' => 'brown'], 'Field "color" must be #rrggbb, e.g. "#f2f1ed".'],
			[['roughness' => 2], 'Field "roughness" must be between 0 and 1.'],
			[['code' => 'Oak Veneer'], 'Field "code" must be kebab-case'],
			[['code' => 'oak-tiles', 'tileWidth' => 300], 'Fields "tileWidth" and "tileHeight" go together.'],
		] as [$change, $error]) {
			$message = $admin->call('add_material', [...$oak, 'code' => 'oak-2', ...$change], $isError);
			self::assertTrue($isError);
			self::assertIsString($message);
			self::assertStringStartsWith($error, $message);
		}

		// The new material is usable right away: as an item material and as a slot default of a definition.
		$floorId = Fixture::firstFloor($this->project)->id;
		$item = $this->client->call(
			'add_item',
			['projectId' => $this->project->id, 'floorId' => $floorId, 'kind' => 'Box', 'x' => 1000, 'y' => 1000, 'materialId' => $added['id']],
		);
		self::assertIsArray($item);
		self::assertIsArray($item['element']);
		self::assertSame($added['id'], $item['element']['materialId']);
		$definition = $this->client->call('create_furniture_definition', [
			'name' => 'Oak shelf',
			'definition' => [
				'format' => 1,
				'size' => ['width' => 600, 'depth' => 300, 'height' => 18],
				'materialSlots' => [['key' => 'board', 'default' => 'walnut-veneer']],
				'parts' => [['type' => 'board', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 'W', 'h' => 'H', 'd' => 'D', 'material' => 'board']],
			]]);
		self::assertIsArray($definition);
		self::assertSame('Oak shelf', $definition['name']);

		self::assertSame(
			'Only admins manage materials.',
			$this->client->call('update_material', ['materialId' => $added['id'], 'color' => '#000000'], $isError),
		);
		AppHarness::mercureHub()->updates = [];
		$updated = $admin->call(
			'update_material',
			['materialId' => $added['id'], 'color' => '#A07850', 'pattern' => 'Planks', 'tileWidth' => 180, 'tileHeight' => 1200],
		);
		self::assertIsArray($updated);
		self::assertSame(
			['walnut-veneer', 'Oak veneer, natural', 'Planks', '#a07850', 0.55, 180, 1200],
			[$updated['code'], $updated['name'], $updated['pattern'], $updated['color'], $updated['roughness'], $updated['tileWidth'], $updated['tileHeight']],
		);
		self::assertSame(
			[['type' => 'materialChanged', 'projectId' => $this->project->id, 'materialId' => $added['id'], 'originClientId' => null]],
			AppHarness::mercureHub()->messages(),
		);
		$message = $admin->call('update_material', ['materialId' => $added['id'], 'clear' => ['tileWidth']], $isError);
		self::assertTrue($isError);
		self::assertSame('Fields "tileWidth" and "tileHeight" go together.', $message);
		$cleared = $admin->call('update_material', ['materialId' => $added['id'], 'clear' => ['tileWidth', 'tileHeight']]);
		self::assertIsArray($cleared);
		self::assertSame([null, null], [$cleared['tileWidth'], $cleared['tileHeight']]);
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

	public function testReadsAPlanAndCalibratesTheUnderlay(): void
	{
		$image = imagecreatetruecolor(2000, 1000);
		self::assertNotFalse($image);
		ob_start();
		imagepng($image);
		$uploaded = $this->upload(
			str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectFiles->value),
			'Propuesta.png',
			(string) ob_get_clean(),
			$this->user,
		);
		$fileId = $this->jsonBody($uploaded)['id'];
		self::assertIsInt($fileId);
		$floorId = Fixture::firstFloor($this->project)->id;

		$files = $this->client->call('list_project_files', ['projectId' => $this->project->id]);
		self::assertIsList($files);
		self::assertSame([['Propuesta.png', 'image/png']], array_map(
			static fn (mixed $file): array => is_array($file) ? [$file['filename'], $file['mimeType']] : [],
			$files,
		));

		// A zoomed crop with a grid comes back scaled to maxSize, as a PNG.
		$result = $this->client->request('tools/call', [
			'name' => 'get_project_file',
			'arguments' => [
				'projectId' => $this->project->id,
				'fileId' => $fileId,
				'cropX' => 1000,
				'cropY' => 200,
				'cropWidth' => 800,
				'cropHeight' => 400,
				'maxSize' => 400,
				'grid' => 100,
			]]);
		self::assertIsList($result['content']);
		self::assertIsArray($result['content'][0]);
		self::assertIsString($result['content'][0]['text']);
		self::assertFalse($result['isError'] ?? false, $result['content'][0]['text']);
		self::assertSame(
			['fileId' => $fileId, 'filename' => 'Propuesta.png', 'widthPx' => 2000, 'heightPx' => 1000, 'crop' => ['x' => 1000, 'y' => 200, 'width' => 800, 'height' => 400], 'scale' => 0.5],
			json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR),
		);
		self::assertIsArray($result['content'][1]);
		self::assertSame('image', $result['content'][1]['type']);
		self::assertSame('image/png', $result['content'][1]['mimeType']);
		self::assertIsString($result['content'][1]['data']);
		$size = getimagesizefromstring((string) base64_decode($result['content'][1]['data'], true));
		self::assertNotFalse($size);
		self::assertSame([400, 200], [$size[0], $size[1]]);

		$isError = false;
		$message = $this->client->call('get_project_file', ['projectId' => $this->project->id, 'fileId' => $fileId, 'grid' => 2], $isError);
		self::assertTrue($isError);
		self::assertIsString($message);
		self::assertStringContainsString('grid', $message);
		// A crop lying entirely outside the image is refused rather than cut to a sliver.
		$message = $this->client->call(
			'get_project_file',
			['projectId' => $this->project->id, 'fileId' => $fileId, 'cropX' => 2500, 'cropY' => 0, 'cropWidth' => 300, 'cropHeight' => 300],
			$isError,
		);
		self::assertTrue($isError);
		self::assertIsString($message);
		self::assertStringContainsString('outside', $message);
		// Points are [px, py, x, y] lists, but the REST body's objects (which the schema check lets through) work too.
		$asObjects = $this->client->call('set_floor_underlay', [
			'projectId' => $this->project->id,
			'floorId' => $floorId,
			'fileId' => $fileId,
			'points' => [['px' => 1193, 'py' => 285, 'x' => 0, 'y' => 0], ['px' => 1328, 'py' => 285, 'x' => 1000, 'y' => 0]],
		], $isError);
		self::assertFalse($isError);
		self::assertIsArray($asObjects);
		self::assertEqualsWithDelta(7.407, $asObjects['scale'], 0.001);
		$message = $this->client->call('set_floor_underlay', [
			'projectId' => $this->project->id,
			'floorId' => $floorId,
			'fileId' => $fileId,
			'points' => [[1193, 285, 0, 0], [1328, 285]],
		], $isError);
		self::assertTrue($isError);
		self::assertIsString($message);
		self::assertStringContainsString('"points"', $message);

		// The scale bar: 1 m from pixel (1193, 285) to (1328, 285); its left end goes to the plan origin.
		$underlay = $this->client->call('set_floor_underlay', [
			'projectId' => $this->project->id,
			'floorId' => $floorId,
			'fileId' => $fileId,
			'points' => [[1193, 285, 0, 0], [1328, 285, 1000, 0]],
			'opacity' => 0.4,
		]);
		self::assertIsArray($underlay);
		self::assertEqualsWithDelta(7.407, $underlay['scale'], 0.001);
		self::assertEquals(
			[0.0, 0.4, 2000, 1000],
			[$underlay['rotation'], $underlay['opacity'], $underlay['widthPx'], $underlay['heightPx']],
		);

		$floor = $this->client->call('get_floor', ['projectId' => $this->project->id, 'floorId' => $floorId]);
		self::assertIsArray($floor);
		self::assertEquals($underlay, $floor['underlay']);

		self::assertSame(
			['removed' => true],
			$this->client->call('remove_floor_underlay', ['projectId' => $this->project->id, 'floorId' => $floorId]),
		);
		self::assertSame(
			['removed' => false],
			$this->client->call('remove_floor_underlay', ['projectId' => $this->project->id, 'floorId' => $floorId]),
		);
	}

	public function testUploadsAndDeletesProjectFiles(): void
	{
		$file = $this->client->call('upload_project_file', [
			'projectId' => $this->project->id,
			'filename' => 'notes.txt',
			'content' => 'data:text/plain;base64,' . base64_encode("Kitchen 3.2 m\n"),
		]);
		self::assertIsArray($file);
		self::assertSame(['notes.txt', 'text/plain', 14], [$file['filename'], $file['mimeType'], $file['size']]);
		$read = $this->client->request('tools/call', [
			'name' => 'get_project_file',
			'arguments' => ['projectId' => $this->project->id, 'fileId' => $file['id']],
		]);
		self::assertSame([['type' => 'text', 'text' => "Kitchen 3.2 m\n"]], $read['content']);

		$isError = false;
		$message = $this->client->call(
			'upload_project_file',
			['projectId' => $this->project->id, 'filename' => 'x.txt', 'content' => 'not base64!'],
			$isError,
		);
		self::assertTrue($isError);
		self::assertSame('"content" is not valid base64.', $message);

		// A larger file goes to a one-time URL, which acts as this user without an access token.
		$upload = $this->client->call('request_file_upload', ['projectId' => $this->project->id, 'filename' => 'poster.txt']);
		self::assertIsArray($upload);
		self::assertIsString($upload['uploadUrl']);
		self::assertIsString($upload['curl']);
		self::assertStringContainsString($upload['uploadUrl'], $upload['curl']);
		$path = (string) parse_url($upload['uploadUrl'], PHP_URL_PATH);
		self::assertSame(201, $this->putRaw($path, 'A poster')->getStatusCode());
		$files = $this->client->call('list_project_files', ['projectId' => $this->project->id]);
		self::assertIsList($files);
		self::assertSame(
			['notes.txt', 'poster.txt'],
			array_map(static fn (mixed $f): mixed => is_array($f) ? $f['filename'] : null, $files),
		);

		$deleted = $this->client->call('delete_project_file', ['projectId' => $this->project->id, 'fileId' => $file['id']]);
		self::assertIsArray($deleted);
		self::assertSame('notes.txt', $deleted['filename']);
		$files = $this->client->call('list_project_files', ['projectId' => $this->project->id]);
		self::assertIsList($files);
		self::assertCount(1, $files);

		$foreign = Fixture::createProject(Fixture::createUser(), 'Not yours');
		$this->client->call('request_file_upload', ['projectId' => $foreign->id, 'filename' => 'x.txt'], $isError);
		self::assertTrue($isError);
	}

	public function testUploadsAndDeletesModels(): void
	{
		$model = $this->client->call('upload_model', [
			'name' => 'Lounge chair',
			'content' => base64_encode(Glb::box([-0.38, 0.0, -0.4], [0.38, 0.86, 0.4])),
			'license' => 'CC0',
			'scope' => 'project',
			'projectId' => $this->project->id,
		]);
		self::assertIsArray($model);
		self::assertSame(['Lounge chair', 'project', $this->project->id, 760, 800, 860], [
			$model['name'],
			$model['scope'],
			$model['projectId'],
			$model['width'],
			$model['depth'],
			$model['height'],
		]);

		$isError = false;
		$message = $this->client->call('upload_model', [
			'name' => 'Squashed',
			'content' => base64_encode(
				Glb::box([0.0, 0.0, 0.0], [1.0, 1.0, 1.0], [], ['extensionsRequired' => ['KHR_draco_mesh_compression']]),
			),
		], $isError);
		self::assertTrue($isError);
		self::assertIsString($message);
		self::assertStringContainsString('KHR_draco_mesh_compression', $message);

		$message = $this->client->call('request_model_upload', ['name' => 'Chair', 'scope' => 'global'], $isError);
		self::assertTrue($isError);
		self::assertSame('Only admins manage global models.', $message);
		$upload = $this->client->call('request_model_upload', ['name' => 'Plant', 'filename' => 'plant.glb']);
		self::assertIsArray($upload);
		self::assertIsString($upload['uploadUrl']);
		$stored = $this->putRaw((string) parse_url($upload['uploadUrl'], PHP_URL_PATH), Glb::box([-0.2, 0.0, -0.2], [0.2, 1.2, 0.2]));
		self::assertSame(201, $stored->getStatusCode());
		self::assertSame(
			['Plant', 'user', 1200],
			[$this->jsonBody($stored)['name'], $this->jsonBody($stored)['scope'], $this->jsonBody($stored)['height']],
		);

		$this->client->call('delete_model', ['modelId' => $model['id']]);
		$models = $this->client->call('list_models', ['projectId' => $this->project->id]);
		self::assertIsList($models);
		self::assertSame(['Plant'], array_map(static fn (mixed $m): mixed => is_array($m) ? $m['name'] : null, $models));
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
