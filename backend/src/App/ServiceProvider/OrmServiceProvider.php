<?php

declare(strict_types=1);

namespace Izbik\App\ServiceProvider;

use Izbik\Model\Entity\Comment;
use Izbik\Model\Entity\EmailVerificationToken;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\FloorUnderlay;
use Izbik\Model\Entity\FloorVoid;
use Izbik\Model\Entity\FurnitureDefinition;
use Izbik\Model\Entity\Item;
use Izbik\Model\Entity\ItemGroup;
use Izbik\Model\Entity\Material;
use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\OAuthAuthorization;
use Izbik\Model\Entity\OAuthClient;
use Izbik\Model\Entity\Opening;
use Izbik\Model\Entity\PasswordResetToken;
use Izbik\Model\Entity\Plot;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\ProjectChange;
use Izbik\Model\Entity\ProjectFile;
use Izbik\Model\Entity\ProjectMaterialPrice;
use Izbik\Model\Entity\ProjectVersion;
use Izbik\Model\Entity\Railing;
use Izbik\Model\Entity\Roof;
use Izbik\Model\Entity\RoofWindow;
use Izbik\Model\Entity\Room;
use Izbik\Model\Entity\SavedView;
use Izbik\Model\Entity\SectionLine;
use Izbik\Model\Entity\ShareLink;
use Izbik\Model\Entity\Stair;
use Izbik\Model\Entity\UploadTicket;
use Izbik\Model\Entity\User;
use Izbik\Model\Entity\VariantSet;
use Izbik\Model\Entity\Wall;
use Izbik\Model\Repository\CommentRepository;
use Izbik\Model\Repository\EmailVerificationTokenRepository;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Model\Repository\FloorUnderlayRepository;
use Izbik\Model\Repository\FloorVoidRepository;
use Izbik\Model\Repository\FurnitureDefinitionRepository;
use Izbik\Model\Repository\ItemGroupRepository;
use Izbik\Model\Repository\ItemRepository;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Model\Repository\ModelAssetRepository;
use Izbik\Model\Repository\OAuthAuthorizationRepository;
use Izbik\Model\Repository\OAuthClientRepository;
use Izbik\Model\Repository\OpeningRepository;
use Izbik\Model\Repository\PasswordResetTokenRepository;
use Izbik\Model\Repository\PlotRepository;
use Izbik\Model\Repository\ProjectChangeRepository;
use Izbik\Model\Repository\ProjectFileRepository;
use Izbik\Model\Repository\ProjectMaterialPriceRepository;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Model\Repository\ProjectVersionRepository;
use Izbik\Model\Repository\RailingRepository;
use Izbik\Model\Repository\RoofRepository;
use Izbik\Model\Repository\RoofWindowRepository;
use Izbik\Model\Repository\RoomRepository;
use Izbik\Model\Repository\SavedViewRepository;
use Izbik\Model\Repository\SectionLineRepository;
use Izbik\Model\Repository\ShareLinkRepository;
use Izbik\Model\Repository\StairRepository;
use Izbik\Model\Repository\UploadTicketRepository;
use Izbik\Model\Repository\UserRepository;
use Izbik\Model\Repository\VariantSetRepository;
use Izbik\Model\Repository\WallRepository;
use Izbik\Service\Dbal\DbContext;
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
			ProjectMaterialPriceRepository::class,
			ShareLinkRepository::class,
			SavedViewRepository::class,
			CommentRepository::class,
			UploadTicketRepository::class,
			OAuthClientRepository::class,
			OAuthAuthorizationRepository::class,
			FloorRepository::class,
			FloorUnderlayRepository::class,
			MaterialRepository::class,
			ModelAssetRepository::class,
			FurnitureDefinitionRepository::class,
			RoomRepository::class,
			PlotRepository::class,
			StairRepository::class,
			FloorVoidRepository::class,
			RailingRepository::class,
			SectionLineRepository::class,
			RoofRepository::class,
			RoofWindowRepository::class,
			WallRepository::class,
			OpeningRepository::class,
			ItemRepository::class,
			ItemGroupRepository::class,
			VariantSetRepository::class,
			UserRepository::class,
			PasswordResetTokenRepository::class,
			EmailVerificationTokenRepository::class,
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
		$this->addRepository($container, $orm, ProjectMaterialPriceRepository::class, ProjectMaterialPrice::class);
		$this->addRepository($container, $orm, ShareLinkRepository::class, ShareLink::class);
		$this->addRepository($container, $orm, SavedViewRepository::class, SavedView::class);
		$this->addRepository($container, $orm, CommentRepository::class, Comment::class);
		$this->addRepository($container, $orm, UploadTicketRepository::class, UploadTicket::class);
		$this->addRepository($container, $orm, OAuthClientRepository::class, OAuthClient::class);
		$this->addRepository($container, $orm, OAuthAuthorizationRepository::class, OAuthAuthorization::class);
		$this->addRepository($container, $orm, FloorRepository::class, Floor::class);
		$this->addRepository($container, $orm, FloorUnderlayRepository::class, FloorUnderlay::class);
		$this->addRepository($container, $orm, MaterialRepository::class, Material::class);
		$this->addRepository($container, $orm, ModelAssetRepository::class, ModelAsset::class);
		$this->addRepository($container, $orm, FurnitureDefinitionRepository::class, FurnitureDefinition::class);
		$this->addRepository($container, $orm, RoomRepository::class, Room::class);
		$this->addRepository($container, $orm, PlotRepository::class, Plot::class);
		$this->addRepository($container, $orm, StairRepository::class, Stair::class);
		$this->addRepository($container, $orm, FloorVoidRepository::class, FloorVoid::class);
		$this->addRepository($container, $orm, RailingRepository::class, Railing::class);
		$this->addRepository($container, $orm, SectionLineRepository::class, SectionLine::class);
		$this->addRepository($container, $orm, RoofRepository::class, Roof::class);
		$this->addRepository($container, $orm, RoofWindowRepository::class, RoofWindow::class);
		$this->addRepository($container, $orm, WallRepository::class, Wall::class);
		$this->addRepository($container, $orm, OpeningRepository::class, Opening::class);
		$this->addRepository($container, $orm, ItemRepository::class, Item::class);
		$this->addRepository($container, $orm, ItemGroupRepository::class, ItemGroup::class);
		$this->addRepository($container, $orm, VariantSetRepository::class, VariantSet::class);
		$this->addRepository($container, $orm, UserRepository::class, User::class);
		$this->addRepository($container, $orm, PasswordResetTokenRepository::class, PasswordResetToken::class);
		$this->addRepository($container, $orm, EmailVerificationTokenRepository::class, EmailVerificationToken::class);
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
