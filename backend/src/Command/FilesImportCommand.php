<?php

declare(strict_types=1);

namespace FlatDesigner\Command;

use FilesystemIterator;
use FlatDesigner\App\ApplicationFactory;
use FlatDesigner\Model\Repository\ProjectRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Project\ProjectFileProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Uploads every file under a directory to a project, as if its owner uploaded them. Files in
 * subdirectories get the path as a prefix ("kitchen/Render 1.png" → "kitchen_Render 1.png").
 */
final class FilesImportCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('files:import')
			->setDescription('Upload all files of a directory to a project.')
			->addArgument('directory', InputArgument::REQUIRED, 'Directory to import (recursively)')
			->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project id');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$directory = $input->getArgument('directory');
		$projectId = $input->getOption('project');
		if (!is_string($directory) || !is_dir($directory) || !is_string($projectId) || !ctype_digit($projectId)) {
			$io->error('Usage: files:import <directory> --project=<id>');

			return self::FAILURE;
		}

		$container = ApplicationFactory::create()->container;
		$projectRepository = $container->get(ProjectRepository::class);
		assert($projectRepository instanceof ProjectRepository);
		$fileProvider = $container->get(ProjectFileProvider::class);
		assert($fileProvider instanceof ProjectFileProvider);

		$project = $projectRepository->findById((int) $projectId);
		if ($project === null) {
			$io->error(sprintf('Project %s not found.', $projectId));

			return self::FAILURE;
		}

		$root = rtrim((string) realpath($directory), '/') . '/';
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		$imported = 0;
		/** @var SplFileInfo $file */
		foreach ($files as $file) {
			if (!$file->isFile() || str_starts_with($file->getFilename(), '.')) {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($root));

			try {
				$fileProvider->upload(
					$project->owner,
					$project,
					str_replace('/', '_', $relative),
					(string) file_get_contents($file->getPathname()),
				);
				$io->writeln('  ' . $relative);
				$imported++;
			} catch (ProjectException $e) {
				$io->warning(sprintf('%s: %s', $relative, $e->getMessage()));
			}
		}

		$io->success(sprintf('Imported %d file(s) into "%s".', $imported, $project->name));

		return self::SUCCESS;
	}
}
