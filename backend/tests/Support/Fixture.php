<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use DateTimeImmutable;
use FlatDesigner\Dto\ProjectInputDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorHandleEnum;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\RoomRepository;
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

	public static function firstFloor(Project $project): Floor
	{
		return self::service(FloorRepository::class)->findByProject($project->id)[0];
	}

	/** One room with tiling, a wall with a door and finishes of its own and two items (one with empty params) on the floor. */
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
		self::service(RoomRepository::class)->persist($room);

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
