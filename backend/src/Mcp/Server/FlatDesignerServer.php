<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Server;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Builds the MCP server: tools discovered from `Mcp/Tool` (`#[McpTool]` methods), instances from the container. */
final readonly class FlatDesignerServer
{
	private const string Instructions = <<<'TEXT'
		This server edits 3D designs of flats. A user has projects; a project has floors; a floor holds rooms
		(floor outlines), walls (centre lines) with openings (doors, windows) and items (kitchen cabinets,
		appliances, sanitary ware, fences, …).

		Units and coordinates: all lengths are whole millimetres in plan coordinates of the floor — origin at the
		inner south-west corner, x grows east, y grows north. Items are boxes given by the centre of their
		footprint (x, y), bottom elevation z and width × depth × height; rotation is degrees counter-clockwise,
		0 = front facing south (−y), 90 = east, 180 = north, 270 = west. Openings sit on a wall at `offset` mm
		from the wall's start point.

		Typical flow: list_projects → get_project (floors, revision) → get_floor (rooms, walls with openings,
		items, all with ids) → list_item_kinds / list_materials when adding things → add_*/update_*/delete_*.
		update_* tools change only the fields you pass. Every change is logged under your client's name and
		shows up live in the user's viewer; undo/redo revert your own changes only. Before a larger
		redesign, call save_version so the user can go back.
		TEXT;

	public function __construct(private ContainerInterface $container, private LoggerInterface $logger)
	{
	}

	public function build(SessionStoreInterface $sessionStore): Server
	{
		return Server::builder()
			->setContainer($this->container)
			->setLogger($this->logger)
			->setReferenceHandler(new ToolErrorTranslatingReferenceHandler(new ReferenceHandler($this->container)))
			->setDiscovery(basePath: dirname(__DIR__, 2), scanDirs: ['Mcp/Tool'])
			->setServerInfo(
				name: 'flat-designer',
				version: '1.0.0',
				description: 'Flat Designer: edit floors, rooms, walls and furniture of flat projects',
			)
			->setInstructions(self::Instructions)
			->setSession($sessionStore)
			->build();
	}
}
