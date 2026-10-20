<?php

declare(strict_types=1);

namespace FlatDesigner\Command;

use FlatDesigner\App\ApplicationFactory;
use FlatDesigner\Dto\ModelAssetInputDto;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Repository\ProjectRepository;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\ModelAsset\ModelAssetException;
use FlatDesigner\Service\ModelAsset\ModelAssetProvider;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use const PATHINFO_FILENAME;

/**
 * Uploads every `.glb` of a directory as a 3D model: global, or a project's own with `--project`. A `<name>.json` next to
 * a model gives its `name`, `description`, `license`, `author` and `sourceUrl` (credits of third-party models); without
 * one the file name is the model's name.
 */
final class ModelsImportCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('models:import')
			->setDescription('Upload the .glb models of a directory to the model library.')
			->addArgument('directory', InputArgument::REQUIRED, 'Directory with .glb files (and optional .json credits)')
			->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project id; without it the models are global');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$directory = $input->getArgument('directory');
		$projectId = $input->getOption('project');
		if (!is_string($directory) || !is_dir($directory) || $projectId !== null && (!is_string($projectId) || !ctype_digit($projectId))) {
			$io->error('Usage: models:import <directory> [--project=<id>]');

			return self::FAILURE;
		}

		$container = ApplicationFactory::create()->container;
		$provider = $container->get(ModelAssetProvider::class);
		assert($provider instanceof ModelAssetProvider);
		$project = null;
		if ($projectId !== null) {
			$projects = $container->get(ProjectRepository::class);
			assert($projects instanceof ProjectRepository);
			$project = $projects->findById((int) $projectId);
			if ($project === null) {
				$io->error(sprintf('Project %s not found.', $projectId));

				return self::FAILURE;
			}
		}

		$files = glob(rtrim($directory, '/') . '/*.glb');
		$files = $files === false ? [] : $files;
		$imported = 0;
		foreach ($files as $file) {
			$credits = self::credits(substr($file, 0, -4) . '.json');
			$modelInput = new ModelAssetInputDto(
				name: $credits['name'] ?? pathinfo($file, PATHINFO_FILENAME),
				description: $credits['description'] ?? '',
				license: $credits['license'] ?? '',
				author: $credits['author'] ?? '',
				sourceUrl: $credits['sourceUrl'] ?? '',
				scope: $project === null ? FurnitureScopeEnum::Global : FurnitureScopeEnum::Project,
				projectId: $project?->id,
			);

			try {
				// A project model is added as its owner; global ones have no author.
				$model = $provider->upload($project?->owner, $modelInput, basename($file), (string) file_get_contents($file));
				$io->writeln(sprintf('  %s: %d × %d × %d mm', $model->name, $model->width, $model->depth, $model->height));
				$imported++;
			} catch (ModelAssetException $e) {
				$io->warning(sprintf('%s: %s', basename($file), $e->getMessage()));
			}
		}

		$io->success(
			sprintf('Imported %d model(s)%s.', $imported, $project === null ? ' as global models' : sprintf(' into "%s"', $project->name)),
		);

		return self::SUCCESS;
	}

	/** @return array<string, string> the string fields of the credits file, if there is one */
	private static function credits(string $path): array
	{
		if (!is_file($path)) {
			return [];
		}

		return array_filter(JsonDecoder::decodeObject((string) file_get_contents($path)), is_string(...));
	}
}
