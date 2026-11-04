<?php

declare(strict_types=1);

namespace Izbik\Command;

use Izbik\App\ApplicationFactory;
use Izbik\Model\Repository\UserRepository;
use Izbik\Service\Project\Transfer\ProjectImporter;
use Izbik\Validator\UserFieldValidator;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Makes a new project of a user's from a project file (`projects:export`, or downloaded from a project's page). */
final class ProjectsImportCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('projects:import')
			->setDescription('Make a new project from a project file.')
			->addArgument('path', InputArgument::REQUIRED, 'Project file (.izbik.zip or .izbik.json)')
			->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Email of the user who will own the project');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$path = $input->getArgument('path');
		$email = $input->getOption('owner');
		$normalized = is_string($email) ? UserFieldValidator::normalizeEmail($email) : null;
		if (!is_string($path) || !is_file($path) || $normalized === null) {
			$io->error('Usage: projects:import <path> --owner=<email>');

			return self::FAILURE;
		}

		$container = ApplicationFactory::create()->container;
		$users = $container->get(UserRepository::class);
		assert($users instanceof UserRepository);
		$owner = $users->findByEmail($normalized);
		if ($owner === null) {
			$io->error(sprintf('No user %s.', $normalized));

			return self::FAILURE;
		}
		$importer = $container->get(ProjectImporter::class);
		assert($importer instanceof ProjectImporter);

		try {
			$imported = $importer->import($owner, $path);
		} catch (RuntimeException $e) {
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		foreach ($imported->warnings as $warning) {
			$io->warning($warning);
		}
		$io->success(sprintf('Imported "%s" as project %d of %s.', $imported->project->name, $imported->project->id, $normalized));

		return self::SUCCESS;
	}
}
