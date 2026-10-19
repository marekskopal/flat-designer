<?php

declare(strict_types=1);

namespace FlatDesigner\App\ServiceProvider;

use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FloorUnderlay;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\OAuthAuthorization;
use FlatDesigner\Model\Entity\OAuthClient;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectChange;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Model\Entity\ProjectVersion;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\ShareLink;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\FloorUnderlayRepository;
use FlatDesigner\Model\Repository\FurnitureDefinitionRepository;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OAuthAuthorizationRepository;
use FlatDesigner\Model\Repository\OAuthClientRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\ProjectChangeRepository;
use FlatDesigner\Model\Repository\ProjectFileRepository;
use FlatDesigner\Model\Repository\ProjectRepository;
use FlatDesigner\Model\Repository\ProjectVersionRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\ShareLinkRepository;
use FlatDesigner\Model\Repository\UserRepository;
use FlatDesigner\Model\Repository\VariantSetRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Dbal\DbContext;
use League\Container\Container;
use League\Container\ServiceProvider\AbstractServiceProvider;
use MarekSkopal\ORM\Database\DatabaseInterface;
use MarekSkopal\ORM\ORM;
use MarekSkopal\ORM\Repository\RepositoryInterface;

final class OrmServiceProvider extends AbstractServiceProvider
{
	public function __construct(private readonly DbContext $dbContext)
	{
	}

	public function provides(string $id): bool
	{
		return in_array($id, [
			DatabaseInterface::class,
			ORM::class,
			ProjectRepository::class,
			ProjectVersionRepository::class,
			ProjectFileRepository::class,
			ProjectChangeRepository::class,
			ShareLinkRepository::class,
			OAuthClientRepository::class,
			OAuthAuthorizationRepository::class,
			FloorRepository::class,
			FloorUnderlayRepository::class,
			MaterialRepository::class,
			FurnitureDefinitionRepository::class,
			RoomRepository::class,
			WallRepository::class,
			OpeningRepository::class,
			ItemRepository::class,
			ItemGroupRepository::class,
			VariantSetRepository::class,
			UserRepository::class,
		], true);
	}

	public function register(): void
	{
		$container = $this->getContainer();
		assert($container instanceof Container);

		$container->add(DatabaseInterface::class, fn () => $this->dbContext->getDatabase());
		$container->add(ORM::class, $this->dbContext->getOrm());

		$orm = $this->dbContext->getOrm();

		$this->addRepository($container, $orm, ProjectRepository::class, Project::class);
		$this->addRepository($container, $orm, ProjectVersionRepository::class, ProjectVersion::class);
		$this->addRepository($container, $orm, ProjectFileRepository::class, ProjectFile::class);
		$this->addRepository($container, $orm, ProjectChangeRepository::class, ProjectChange::class);
		$this->addRepository($container, $orm, ShareLinkRepository::class, ShareLink::class);
		$this->addRepository($container, $orm, OAuthClientRepository::class, OAuthClient::class);
		$this->addRepository($container, $orm, OAuthAuthorizationRepository::class, OAuthAuthorization::class);
		$this->addRepository($container, $orm, FloorRepository::class, Floor::class);
		$this->addRepository($container, $orm, FloorUnderlayRepository::class, FloorUnderlay::class);
		$this->addRepository($container, $orm, MaterialRepository::class, Material::class);
		$this->addRepository($container, $orm, FurnitureDefinitionRepository::class, FurnitureDefinition::class);
		$this->addRepository($container, $orm, RoomRepository::class, Room::class);
		$this->addRepository($container, $orm, WallRepository::class, Wall::class);
		$this->addRepository($container, $orm, OpeningRepository::class, Opening::class);
		$this->addRepository($container, $orm, ItemRepository::class, Item::class);
		$this->addRepository($container, $orm, ItemGroupRepository::class, ItemGroup::class);
		$this->addRepository($container, $orm, VariantSetRepository::class, VariantSet::class);
		$this->addRepository($container, $orm, UserRepository::class, User::class);
	}

	/**
	 * @param class-string<RepositoryInterface<TEntity>> $repositoryClass
	 * @param class-string<TEntity> $entityClass
	 * @template TEntity of object
	 */
	private function addRepository(Container $container, ORM $orm, string $repositoryClass, string $entityClass): void
	{
		$repository = $orm->getRepository($entityClass);
		$container->add($repositoryClass, fn () => $repository);
	}
}
