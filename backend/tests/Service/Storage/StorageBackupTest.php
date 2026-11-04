<?php

declare(strict_types=1);

namespace Izbik\Tests\Service\Storage;

use Izbik\Service\Storage\StorageBackup;
use Izbik\Tests\Support\InMemoryFileStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(StorageBackup::class)]
final class StorageBackupTest extends TestCase
{
	private string $directory;

	protected function setUp(): void
	{
		$this->directory = sys_get_temp_dir() . '/izbik-storage-backup-' . bin2hex(random_bytes(6));
		mkdir($this->directory);
	}

	protected function tearDown(): void
	{
		exec('rm -r ' . escapeshellarg($this->directory));
	}

	public function testObjectsComeBackWithTheirContentTypes(): void
	{
		$storage = new InMemoryFileStorage();
		$storage->put('projects/4/1f2e.png', 'png bytes', 'image/png');
		$storage->put('models/9a0b.glb', 'glb bytes', 'model/gltf-binary');

		self::assertSame(2, new StorageBackup($storage)->backup($this->directory));
		self::assertSame('png bytes', file_get_contents($this->directory . '/objects/projects/4/1f2e.png'));

		$restored = new InMemoryFileStorage();
		$restored->put('projects/5/new.pdf', 'stored since', 'application/pdf');
		self::assertSame(2, new StorageBackup($restored)->restore($this->directory));

		self::assertSame(
			['projects/5/new.pdf' => 'stored since', 'projects/4/1f2e.png' => 'png bytes', 'models/9a0b.glb' => 'glb bytes'],
			$restored->objects,
		);
		self::assertSame('model/gltf-binary', $restored->contentTypes['models/9a0b.glb']);
	}

	public function testAnEmptyStorageMakesAnEmptyBackup(): void
	{
		self::assertSame(0, new StorageBackup(new InMemoryFileStorage())->backup($this->directory));
		self::assertSame(0, new StorageBackup(new InMemoryFileStorage())->restore($this->directory));
	}

	public function testAManifestReachingOutOfTheBackupIsRefused(): void
	{
		file_put_contents($this->directory . '/objects.json', '{"../../etc/passwd": "text/plain"}');
		$storage = new InMemoryFileStorage();

		self::assertStringContainsString(
			'is not a storage key',
			self::refusal(fn () => new StorageBackup($storage)->restore($this->directory)),
		);
		self::assertSame([], $storage->objects);
	}

	public function testADirectoryWithoutAManifestIsNoBackup(): void
	{
		$refusal = self::refusal(fn () => new StorageBackup(new InMemoryFileStorage())->restore($this->directory));

		self::assertStringContainsString('not a storage backup', $refusal);
	}

	/** @param callable(): mixed $restore the message of the RuntimeException it throws */
	private static function refusal(callable $restore): string
	{
		try {
			$restore();
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}

		self::fail('The restore went through.');
	}
}
