<?php

declare(strict_types=1);

namespace Izbik\Service\Example;

use Izbik\Service\Json\JsonDecoder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The example projects shipped with the app: one JSON file per example in `backend/examples/`, `<slug>.json` holding
 * `{format, name, description, credit, startView, snapshot}` (`ExamplesExportCommand` writes them). The snapshot is a project
 * snapshot taken on the instance it was modelled on, so it is imported (`ProjectSnapshotService::import()`), not restored.
 */
final readonly class ExampleCatalogue
{
	public const int Format = 1;

	private const string SlugPattern = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	public function __construct(private LoggerInterface $logger, private string $directory = __DIR__ . '/../../../examples')
	{
	}

	public static function isSlug(string $slug): bool
	{
		return preg_match(self::SlugPattern, $slug) === 1;
	}

	/** @return list<Example> by name, leaving out (and logging) a file that is not an example */
	public function all(): array
	{
		$examples = [];
		$files = glob($this->directory . '/*.json');
		foreach ($files === false ? [] : $files as $file) {
			try {
				$examples[] = $this->read($file);
			} catch (Throwable $e) {
				$this->logger->warning(sprintf('Example %s left out: %s', basename($file), $e->getMessage()));
			}
		}
		usort($examples, static fn (Example $a, Example $b): int => strcmp($a->name, $b->name));

		return $examples;
	}

	public function find(string $slug): ?Example
	{
		$file = $this->path($slug);

		return self::isSlug($slug) && is_file($file) ? $this->read($file) : null;
	}

	public function write(Example $example): string
	{
		if (!self::isSlug($example->slug)) {
			throw new RuntimeException(sprintf('"%s" is not a slug (lowercase letters, digits and dashes).', $example->slug));
		}
		$file = $this->path($example->slug);
		$json = json_encode(
			[
				'format' => self::Format,
				'name' => $example->name,
				'description' => $example->description,
				'credit' => $example->credit,
				'startView' => $example->startView,
				'snapshot' => JsonDecoder::decodeObject($example->snapshot),
			],
			JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
		);
		if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) || file_put_contents($file, $json . "\n") === false) {
			throw new RuntimeException(sprintf('Cannot write %s.', $file));
		}

		return $file;
	}

	private function path(string $slug): string
	{
		return $this->directory . '/' . $slug . '.json';
	}

	private function read(string $file): Example
	{
		$data = JsonDecoder::decodeObject((string) file_get_contents($file));
		$snapshot = $data['snapshot'] ?? null;
		if (($data['format'] ?? null) !== self::Format || !is_string($data['name'] ?? null) || !is_array($snapshot)) {
			throw new RuntimeException(sprintf('%s is not an example project file.', basename($file)));
		}
		$startView = $data['startView'] ?? null;

		return new Example(
			slug: basename($file, '.json'),
			name: $data['name'],
			description: is_string($data['description'] ?? null) ? $data['description'] : '',
			credit: is_string($data['credit'] ?? null) ? $data['credit'] : '',
			startView: is_string($startView) ? $startView : null,
			snapshot: json_encode($snapshot, JSON_THROW_ON_ERROR),
		);
	}
}
