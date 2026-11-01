<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use DateTimeImmutable;
use FlatDesigner\Dto\ProjectInputDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\BoardDirectionEnum;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorHandleEnum;
use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\GutterProfileEnum;
use FlatDesigner\Model\Entity\Enum\HandrailProfileEnum;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\RailingStyleEnum;
use FlatDesigner\Model\Entity\Enum\RidgeStyleEnum;
use FlatDesigner\Model\Entity\Enum\RoofShapeEnum;
use FlatDesigner\Model\Entity\Enum\RoofWindowTypeEnum;
use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Entity\Enum\StairConstructionEnum;
use FlatDesigner\Model\Entity\Enum\StairRailingEnum;
use FlatDesigner\Model\Entity\Enum\StairShapeEnum;
use FlatDesigner\Model\Entity\Enum\StairTurnEnum;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FloorVoid;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Plot;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\Railing;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Entity\RoofWindow;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\Stair;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\FloorVoidRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\PlotRepository;
use FlatDesigner\Model\Repository\RailingRepository;
use FlatDesigner\Model\Repository\RoofRepository;
use FlatDesigner\Model\Repository\RoofWindowRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\StairRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Authentication\TokenService;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Provider\UserProvider;

final class Fixture
{
	public const string Password = 'TestPass1!';

	private static int $userCounter = 0;

	public static function reset(): void
	{
		self::$userCounter = 0;
	}

	public static function createUser(?string $email = null, string $name = 'Test User', UserRoleEnum $role = UserRoleEnum::User,): User
	{
		self::$userCounter++;

		return self::userProvider()->createUser($email ?? 'user' . self::$userCounter . '@example.com', self::Password, $name, $role);
	}

	public static function createAdmin(?string $email = null): User
	{
		return self::createUser($email, 'Admin', UserRoleEnum::Admin);
	}

	public static function accessTokenFor(User $user): string
	{
		return self::tokenService()->createAuthentication($user)->accessToken;
	}

	public static function refreshTokenFor(User $user): string
	{
		return self::tokenService()->createAuthentication($user)->refreshToken;
	}

	private static function userProvider(): UserProvider
	{
		$userProvider = AppHarness::container()->get(UserProvider::class);
		assert($userProvider instanceof UserProvider);

		return $userProvider;
	}

	private static function tokenService(): TokenService
	{
		$tokenService = AppHarness::container()->get(TokenService::class);
		assert($tokenService instanceof TokenService);

		return $tokenService;
	}

	/** Project with its default empty ground floor. */
	public static function createProject(User $owner, string $name = 'Test Project'): Project
	{
		return self::service(ProjectProvider::class)->create($owner, new ProjectInputDto($name, 'Example City', null));
	}

	/** The elements path of one floor (`Routes::ProjectFloorElements` with the type filled in). */
	public static function elementsPath(Project $project, int $floorId, string $type = 'items'): string
	{
		return sprintf('/api/projects/%d/floors/%d/%s', $project->id, $floorId, $type);
	}

	public static function firstFloor(Project $project): Floor
	{
		return self::service(FloorRepository::class)->findByProject($project->id)[0];
	}

	/**
	 * One room with tiling, a plot, an L stair (balusters, a wall handrail, a lined void), a floor void and a railing, a hip roof with a window,
	 * gutters, downpipes, snow guards and round ridge tiles, a wall rising to the roof with a door and finishes of its own, two items (one
	 * with empty params) on the floor, a chimney and a panel lying on the roof.
	 */
	public static function furnishFloor(Floor $floor): void
	{
		$material = self::service(MaterialRepository::class)->findAllOrdered()[0] ?? null;

		$room = self::stamp(new Room(
			floor: $floor,
			code: 'R1',
			name: 'Kitchen',
			type: RoomTypeEnum::Living,
			polygon: '[[0,0],[4000,0],[4000,3000],[0,3000]]',
			ceilingHeight: 2600,
			floorMaterial: $material,
			wallFinishes: $material === null ? null : '[{"materialId":' . $material->id . ',"bottom":0,"top":1200}]',
		));
		$room->floorElevation = 20;
		$room->soffitMaterial = $material;
		self::service(RoomRepository::class)->persist($room);
		$plot = new Plot(
			floor: $floor,
			name: 'Parcel',
			polygon: '[[-3000,-3000],[9000,-3000],[9000,8000],[-3000,8000]]',
			groundMaterial: $material,
		);
		$plot->groundLevel = -300;
		$plot->terrain = '[[-3000,-3000,-300],[9000,8000,450]]';
		self::service(PlotRepository::class)->persist(self::stamp($plot));
		$stair = self::stamp(new Stair(
			floor: $floor,
			name: 'Main stairs',
			shape: StairShapeEnum::LShaped,
			x: 3000,
			y: 500,
			rotation: 90,
			width: 1000,
			going: 280,
			risers: null,
			firstFlight: 5,
			turn: StairTurnEnum::Right,
			gap: 100,
			construction: StairConstructionEnum::Stringers,
			railing: StairRailingEnum::Both,
			treadMaterial: $material,
		));
		$stair->railingStyle = RailingStyleEnum::Balusters;
		$stair->railingMaterial = $material;
		$stair->wallHandrail = StairRailingEnum::Left;
		$stair->voidLiningMaterial = $material;
		self::service(StairRepository::class)->persist($stair);
		$void = new FloorVoid(
			floor: $floor,
			name: 'Gallery void',
			polygon: '[[500,500],[1500,500],[1500,1500],[500,1500]]',
			liningMaterial: $material,
		);
		$void->liningDepth = 400;
		self::service(FloorVoidRepository::class)->persist(self::stamp($void));
		$railing = new Railing(
			floor: $floor,
			name: 'Gallery balustrade',
			points: '[[500,500],[1500,500],[1500,1500]]',
			height: 1100,
			style: RailingStyleEnum::Glass,
			postSpacing: 900,
			handrail: HandrailProfileEnum::Flat,
			material: $material,
			infillMaterial: $material,
		);
		$railing->baseZ = 150;
		$railing->spacing = 80;
		self::service(RailingRepository::class)->persist(self::stamp($railing));
		$roof = self::stamp(new Roof(
			floor: $floor,
			name: 'Main roof',
			shape: RoofShapeEnum::Hip,
			x: 2000,
			y: 1500,
			rotation: 0,
			width: 4300,
			depth: 3300,
			eaveHeight: 2600,
			pitch: 40,
			overhang: 500,
			vergeOverhang: 0,
			thickness: 280,
			coveringMaterial: $material,
		));
		$roof->gutters = GutterProfileEnum::HalfRound;
		$roof->gutterMaterial = $material;
		$roof->downpipes = '[{"edge":"Front","along":-1500},{"edge":"Left","along":0}]';
		$roof->snowGuards = 2;
		$roof->ridgeStyle = RidgeStyleEnum::Round;
		self::service(RoofRepository::class)->persist($roof);
		self::service(RoofWindowRepository::class)->persist(self::stamp(new RoofWindow(
			roof: $roof,
			name: 'Skylight',
			type: RoofWindowTypeEnum::TopHung,
			along: 0,
			across: -900,
			width: 550,
			length: 780,
			frameMaterial: $material,
		)));

		$wall = self::stamp(new Wall(
			floor: $floor,
			name: 'South',
			type: WallTypeEnum::Exterior,
			startX: 0,
			startY: 0,
			endX: 4000,
			endY: 0,
			thickness: 300,
			height: 2600,
			material: $material,
		));
		$wall->leftFinishes = $material === null ? null : '[{"materialId":' . $material->id . ',"bottom":0,"top":2100}]';
		$wall->rightFinishes = '[]';
		$wall->upToRoof = true;
		self::service(WallRepository::class)->persist($wall);
		$door = self::stamp(new Opening(
			wall: $wall,
			name: 'Door',
			type: OpeningTypeEnum::Door,
			offset: 500,
			width: 900,
			height: 1970,
			sillHeight: 0,
			leafWidth: 800,
			hinge: OpeningHingeEnum::Start,
			side: OpeningSideEnum::Left,
		));
		$door->entrance = true;
		$door->frameStyle = DoorFrameStyleEnum::Architrave;
		$door->handle = DoorHandleEnum::Knob;
		$door->frameMaterial = $material;
		$door->leafMaterial = $material;
		$door->leafStyle = DoorLeafStyleEnum::Boarded;
		$door->leafPanels = 9;
		$door->boardDirection = BoardDirectionEnum::Horizontal;
		$door->inset = 60;
		self::service(OpeningRepository::class)->persist($door);
		$frenchWindow = self::stamp(new Opening(
			wall: $wall,
			name: 'French window',
			type: OpeningTypeEnum::Window,
			offset: 2500,
			width: 1200,
			height: 2200,
			sillHeight: 0,
			leafWidth: null,
			hinge: null,
			side: null,
		));
		$frenchWindow->walkable = true;
		$frenchWindow->revealLining = true;
		$frenchWindow->outsideFrameMaterial = $material;
		self::service(OpeningRepository::class)->persist($frenchWindow);

		$items = self::service(ItemRepository::class);
		$items->persist(self::stamp(new Item(
			floor: $floor,
			room: $room,
			kind: ItemKindEnum::Box,
			name: 'Base 60',
			x: 300,
			y: 2700,
			z: 0,
			width: 600,
			depth: 600,
			height: 870,
			rotation: 180,
			material: $material,
			params: null,
		)));
		$items->persist(self::stamp(new Item(
			floor: $floor,
			room: null,
			kind: ItemKindEnum::Box,
			name: 'Worktop',
			x: 300,
			y: 2700,
			z: 870,
			width: 600,
			depth: 620,
			height: 38,
			rotation: 180,
			material: null,
			params: null,
		)));
		$planter = self::stamp(new Item(
			floor: $floor,
			room: null,
			kind: ItemKindEnum::Box,
			name: 'Planter',
			x: 7000,
			y: 6000,
			z: 0,
			width: 800,
			depth: 400,
			height: 450,
			rotation: 0,
			material: null,
			params: null,
		));
		$planter->onGround = true;
		$items->persist($planter);
		$items->persist(self::stamp(new Item(
			floor: $floor,
			room: $room,
			kind: ItemKindEnum::Chimney,
			name: 'Chimney',
			x: 3500,
			y: 2500,
			z: 20,
			width: 450,
			depth: 450,
			height: 3000,
			rotation: 0,
			material: $material,
			params: '{"shape":"rect","aboveRoof":600,"cap":true}',
		)));
		$panel = self::stamp(new Item(
			floor: $floor,
			room: null,
			kind: ItemKindEnum::Box,
			name: 'Solar panel',
			x: 1500,
			y: 600,
			z: 80,
			width: 1000,
			depth: 1700,
			height: 40,
			rotation: 0,
			material: null,
			params: null,
		));
		$panel->onRoof = true;
		$items->persist($panel);
	}

	/**
	 * @param class-string<T> $class
	 * @return T
	 * @template T of object
	 */
	public static function service(string $class): object
	{
		$service = AppHarness::container()->get($class);
		assert($service instanceof $class);

		return $service;
	}

	/**
	 * @param T $entity
	 * @return T
	 * @template T of AEntity
	 */
	private static function stamp(AEntity $entity): AEntity
	{
		$entity->createdAt = new DateTimeImmutable();
		$entity->updatedAt = $entity->createdAt;

		return $entity;
	}
}
