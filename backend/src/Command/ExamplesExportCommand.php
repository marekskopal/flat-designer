<?php

declare(strict_types=1);

namespace Izbik\Command;

use Izbik\App\ApplicationFactory;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Service\Example\Example;
use Izbik\Service\Example\ExampleCatalogue;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\Project\ProjectSnapshotService;
use Izbik\Service\Project\SnapshotFiles;
use Izbik\Service\Project\SnapshotJson;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use const JSON_THROW_ON_ERROR;

/**
 * Writes a project's working copy as an example project (`backend/examples/<slug>.json`, `ExampleCatalogue`), which
 * every user may then start from. Its files stay behind: the underlays and pictures are left out.
 */
final class ExamplesExportCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('examples:export')
			->setDescription('Write a project as an example project users may start from.')
			->addArgument('project', InputArgument::REQUIRED, 'Project id')
			->addArgument('slug', InputArgument::REQUIRED, 'File name in examples/, e.g. demo-flat')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name of the example and its projects (default: the project\'s)')
			->addOption('description', null, InputOption::VALUE_REQUIRED, 'What the example shows', '')
			->addOption('credit', null, InputOption::VALUE_REQUIRED, 'Where it comes from, e.g. the building it is modelled after', '')
			->addOption('start-view', null, InputOption::VALUE_REQUIRED, 'Saved view a new project opens at')
			->addOption(
				'without-views',
				null,
				InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
				'Leave out the saved views whose name starts with this (repeatable)',
			)
			->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Directory to write to (default: examples/)');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$projectId = $input->getArgument('project');
		$slug = $input->getArgument('slug');
		if (!is_string($projectId) || !ctype_digit($projectId) || !is_string($slug) || !ExampleCatalogue::isSlug($slug)) {
			$io->error('Usage: examples:export <project id> <slug> [--name=…] [--description=…] [--credit=…] [--start-view=…]');

			return self::FAILURE;
		}

		$container = ApplicationFactory::create()->container;
		$projects = $container->get(ProjectRepository::class);
		assert($projects instanceof ProjectRepository);
		$project = $projects->findById((int) $projectId);
		if ($project === null) {
			$io->error(sprintf('Project %s not found.', $projectId));

			return self::FAILURE;
		}
		$snapshots = $container->get(ProjectSnapshotService::class);
		assert($snapshots instanceof ProjectSnapshotService);

		$name = self::option($input, 'name') ?? $project->name;
		$snapshot = SnapshotFiles::withoutFiles(JsonDecoder::decodeObject($snapshots->createSnapshot($project)));
		$details = is_array($snapshot['project'] ?? null) ? $snapshot['project'] : [];
		$snapshot['project'] = ['name' => $name] + $details;
		$startView = self::option($input, 'start-view');
		$views = self::views($snapshot['views'] ?? null, $input->getOption('without-views'));
		$snapshot['views'] = $views;
		if ($startView !== null && !in_array($startView, array_column($views, 'name'), true)) {
			$io->error(sprintf('The project has no saved view "%s".', $startView));

			return self::FAILURE;
		}

		$directory = self::option($input, 'directory');
		$catalogue = $directory === null ? new ExampleCatalogue() : new ExampleCatalogue($directory);
		$file = $catalogue->write(new Example(
			slug: $slug,
			name: $name,
			description: self::option($input, 'description') ?? '',
			credit: self::option($input, 'credit') ?? '',
			startView: $startView,
			snapshot: json_encode($snapshot, JSON_THROW_ON_ERROR),
		));
		$io->success(sprintf('Wrote "%s" to %s.', $name, $file));

		return self::SUCCESS;
	}

	/** @return list<array<mixed>> the saved views without those whose name starts with one of `$prefixes` */
	private static function views(mixed $views, mixed $prefixes): array
	{
		$prefixes = array_filter(is_array($prefixes) ? $prefixes : [], static fn (mixed $p): bool => is_string($p) && $p !== '');

		return array_values(array_filter(
			SnapshotJson::arrays($views),
			static function (array $view) use ($prefixes): bool {
				$name = is_string($view['name'] ?? null) ? $view['name'] : '';

				return array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix)) === [];
			},
		));
	}

	private static function option(InputInterface $input, string $name): ?string
	{
		$value = $input->getOption($name);

		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}
}
