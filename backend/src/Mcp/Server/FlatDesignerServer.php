<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Server;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Session\SessionLockInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the MCP server: tools discovered from `Mcp/Tool` (`#[McpTool]` methods), instances from the container.
 * Tool arguments are validated with `EmptyObjectSchemaValidator`; the session lock serializes the requests of one
 * session.
 */
final readonly class FlatDesignerServer
{
	private const string Instructions = <<<'TEXT'
		This server edits 3D designs of flats. A user has projects; a project has floors; a floor holds rooms
		(floor outlines), walls (centre lines) with openings (doors, windows) and items (custom-made furniture,
		appliances, sanitary ware, fences, …).

		Units and coordinates: all lengths are whole millimetres in plan coordinates of the floor — origin at the
		inner south-west corner, x grows east, y grows north. Items are boxes given by the centre of their
		footprint (x, y), bottom elevation z and width × depth × height; rotation is degrees counter-clockwise,
		0 = front facing south (−y), 90 = east, 180 = north, 270 = west. Openings sit on a wall at `offset` mm
		from the wall's start point. Items forming one piece of furniture (a kitchen run, a hall stand) share a
		group (`groupId`): move_group, duplicate_group and delete_group act on all of them as one change.
		Alternatives of a piece of furniture (e.g. with or without a kitchen island) are groups in a variant
		set; only its active variant is shown (switch_variant), get_floor lists the hidden items.

		Custom-made furniture is data: a furniture definition (list_furniture_definitions) has parameters and
		parts (boards, fronts, legs, …) whose sizes are expressions of the item's W, D, H; add it with
		add_item(kind: "Custom", definitionId). To design a new piece, write a definition (the format is in the
		schema of create_furniture_definition), check it with validate_furniture_definition, then create it.
		Editing a definition changes every item drawn from it, in all projects. Kitchen cabinets, worktops,
		wardrobes, chairs and tables are such definitions; the built-in kinds of the same furniture (marked
		replacedBy in list_item_kinds) are kept only for older items, so add new furniture from the definitions.
		get_cut_list lists the boards of a project's custom-made furniture; get_room_schedule lists the rooms'
		areas, perimeters and clear heights with the indoor and outdoor totals.

		To lay out a floor, draw the walls as runs of centre-line points with add_walls (a closed run for the
		outer walls, open runs for partitions ending on other walls), then add each room with
		detect_room(x, y) at a point inside it: its outline follows the inner wall faces.

		To work from a plan or photos the user uploaded, list them with list_project_files and look at them
		with get_project_file (crop into a region and add a pixel grid to read positions exactly). Lay the plan
		under the floor with set_floor_underlay, calibrated from two points you know (a dimensioned wall, the
		scale bar), then convert pixel positions to plan mm with its placement to place walls and openings.

		Typical flow: list_projects → get_project (floors, revision) → get_floor (rooms, walls with openings,
		items, all with ids) → list_item_kinds / list_materials when adding things → add_*/update_*/delete_*.
		update_* tools change only the fields you pass. Every change is logged under your client's name and
		shows up live in the user's viewer; undo/redo revert your own changes only. Before a larger
		redesign, call save_version so the user can go back.
		TEXT;

	private const int PaginationLimit = 200;

	public function __construct(private ContainerInterface $container, private LoggerInterface $logger)
	{
	}

	public function build(SessionStoreInterface $sessionStore, SessionLockInterface $sessionLock): Server
	{
		return Server::builder()
			->setContainer($this->container)
			->setLogger($this->logger)
			->setReferenceHandler(new ToolErrorTranslatingReferenceHandler(new ReferenceHandler($this->container)))
			->setSchemaValidator(new EmptyObjectSchemaValidator(logger: $this->logger))
			->setDiscovery(basePath: dirname(__DIR__, 2), scanDirs: ['Mcp/Tool'])
			// All tools on one page of tools/list: not every client follows `nextCursor` (the default page is 50).
			->setPaginationLimit(self::PaginationLimit)
			->setServerInfo(
				name: 'flat-designer',
				version: '1.0.0',
				description: 'Flat Designer: edit floors, rooms, walls and furniture of flat projects',
			)
			->setInstructions(self::Instructions)
			->setSession($sessionStore)
			->setSessionLock($sessionLock)
			->build();
	}
}
