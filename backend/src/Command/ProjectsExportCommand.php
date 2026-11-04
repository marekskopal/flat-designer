<?php

declare(strict_types=1);

namespace Izbik\Command;

use Izbik\App\ApplicationFactory;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Service\Project\Transfer\ProjectExporter;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Writes a project as a project file (`ProjectBundle`): a zip with its files and models, or its JSON alone. */
final class ProjectsExportCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('projects:export')
			->setDescription('Write a project as a project file, to move it to another instance or keep it as a backup.')
			->addArgument('project', InputArgument::REQUIRED, 'Project id')
			->addArgument('path', InputArgument::REQUIRED, 'File to write, e.g. flat.izbik.zip')
			->addOption('without-files', null, InputOption::VALUE_NONE, 'Write the JSON alone, without the files and models');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$projectId = $input->getArgument('project');
		$path = $input->getArgument('path');
		if (!is_string($projectId) || !ctype_digit($projectId) || !is_string($path) || $path === '') {
			$io->error('Usage: projects:export <project id> <path> [--without-files]');

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
		$exporter = $container->get(ProjectExporter::class);
		assert($exporter instanceof ProjectExporter);

		try {
			if ($input->getOption('without-files') !== true) {
				$exporter->zip($project, $path);
			} elseif (file_put_contents($path, $exporter->json($project)) === false) {
				throw new RuntimeException(sprintf('Cannot write %s.', $path));
			}
		} catch (RuntimeException $e) {
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$io->success(sprintf('Wrote "%s" to %s.', $project->name, $path));

		return self::SUCCESS;
	}
}
