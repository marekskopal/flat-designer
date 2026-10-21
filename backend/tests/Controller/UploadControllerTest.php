<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use DateTimeImmutable;
use FlatDesigner\Controller\UploadController;
use FlatDesigner\Dto\ModelAssetInputDto;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Repository\ModelAssetRepository;
use FlatDesigner\Model\Repository\ProjectRepository;
use FlatDesigner\Service\ModelAsset\ModelAssetException;
use FlatDesigner\Service\Upload\UploadTicketService;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\Glb;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use FlatDesigner\Tests\Support\LimitedStream;
use MarekSkopal\ORM\Database\DatabaseInterface;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UploadController::class)]
#[CoversClass(UploadTicketService::class)]
final class UploadControllerTest extends IntegrationTestCase
{
	public function testAProjectFileUrlWorksOnce(): void
	{
		$user = Fixture::createUser(name: 'Jane');
		$project = Fixture::createProject($user);
		[, $token] = $this->tickets()->createForFile($user, $project, 'notes/plan.txt');

		$response = $this->putRaw(UploadTicketService::path($token), "Kitchen 3.2 m\n");
		self::assertSame(201, $response->getStatusCode());
		$file = $this->jsonBody($response);
		self::assertSame(
			['plan.txt', 'text/plain', 14, 'Jane'],
			[$file['filename'], $file['mimeType'], $file['size'], $file['uploadedByName']],
		);

		$again = $this->putRaw(UploadTicketService::path($token), 'again');
		self::assertSame(404, $again->getStatusCode());
		self::assertStringContainsString('already used', (string) $again->getBody());
		self::assertSame(404, $this->putRaw(UploadTicketService::path(str_repeat('ab', 32)), 'x')->getStatusCode());
	}

	public function testARejectedFileLeavesTheUrlOpen(): void
	{
		$user = Fixture::createUser();
		[, $token] = $this->tickets()->createForModel(
			$user,
			new ModelAssetInputDto(name: 'Lounge chair', license: 'CC0', scope: FurnitureScopeEnum::User),
			'chair.glb',
		);

		$draco = Glb::box([0.0, 0.0, 0.0], [1.0, 1.0, 1.0], [], ['extensionsRequired' => ['KHR_draco_mesh_compression']]);
		$rejected = $this->putRaw(UploadTicketService::path($token), $draco);
		self::assertSame(422, $rejected->getStatusCode());
		self::assertStringContainsString('KHR_draco_mesh_compression', (string) $rejected->getBody());

		$stored = $this->putRaw(UploadTicketService::path($token), Glb::box([-0.4, 0.0, -0.4], [0.4, 0.8, 0.4]));
		self::assertSame(201, $stored->getStatusCode());
		$model = $this->jsonBody($stored);
		self::assertSame(['Lounge chair', 'user', 800, 800, 800, 'CC0'], [
			$model['name'],
			$model['scope'],
			$model['width'],
			$model['depth'],
			$model['height'],
			$model['license'],
		]);
		self::assertIsInt($model['id']);
		self::assertSame($user->id, Fixture::service(ModelAssetRepository::class)->findById($model['id'])?->user?->id);
	}

	public function testAUrlDiesWithTheAccessToItsProject(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		[, $token] = $this->tickets()->createForFile($user, $project, 'plan.txt');
		$project->owner = Fixture::createUser();
		Fixture::service(ProjectRepository::class)->persist($project);

		$response = $this->putRaw(UploadTicketService::path($token), 'late');
		self::assertSame(403, $response->getStatusCode());
		self::assertStringContainsString('no longer have access', (string) $response->getBody());
	}

	public function testABodyOverTheLimitIsRejectedWithoutBeingRead(): void
	{
		$user = Fixture::createUser();
		[, $token] = $this->tickets()->createForFile($user, Fixture::createProject($user), 'big.bin');
		$limit = $this->tickets()->maxBytes();

		$response = $this->putRaw(UploadTicketService::path($token), new LimitedStream($limit + 1));
		self::assertSame(413, $response->getStatusCode());
		self::assertStringContainsString('larger than', (string) $response->getBody());
	}

	public function testAnExpiredUrlIsGone(): void
	{
		$user = Fixture::createUser();
		[$ticket, $token] = $this->tickets()->createForFile($user, Fixture::createProject($user), 'plan.txt');
		Fixture::service(DatabaseInterface::class)->execute(
			'UPDATE upload_tickets SET expires_at = ? WHERE id = ?',
			[(new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $ticket->id],
			cached: false,
		);

		self::assertSame(404, $this->putRaw(UploadTicketService::path($token), 'late')->getStatusCode());
	}

	public function testOnlyAdminsGetAUrlForAGlobalModel(): void
	{
		// What can be checked before the file arrives is checked when the URL is asked for.
		$this->expectExceptionObject(new ModelAssetException('Only admins manage global models.', 403));
		$this->tickets()->createForModel(
			Fixture::createUser(),
			new ModelAssetInputDto(name: 'Chair', scope: FurnitureScopeEnum::Global),
			'c.glb',
		);
	}

	public function testAProjectModelNeedsAccessToTheProject(): void
	{
		$user = Fixture::createUser();
		$foreign = Fixture::createProject(Fixture::createUser());

		$this->expectExceptionObject(new ModelAssetException('Project not found.', 404));
		$this->tickets()->createForModel(
			$user,
			new ModelAssetInputDto(name: 'Chair', scope: FurnitureScopeEnum::Project, projectId: $foreign->id),
			'c.glb',
		);
	}

	private function tickets(): UploadTicketService
	{
		return Fixture::service(UploadTicketService::class);
	}
}
