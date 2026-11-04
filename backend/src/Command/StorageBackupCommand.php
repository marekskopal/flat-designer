<?php

declare(strict_types=1);

namespace Izbik\Command;

use Izbik\App\ApplicationFactory;
use Izbik\Service\Storage\StorageBackup;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Writes every stored object (project files, models) into a directory, for `make backup` (see StorageBackup). */
final class StorageBackupCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('storage:backup')
			->setDescription('Copy every stored object (project files, models) into a directory.')
			->addArgument('directory', InputArgument::REQUIRED, 'Directory to write the objects and objects.json into');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$directory = $input->getArgument('directory');
		if (!is_string($directory) || !is_dir($directory)) {
			$io->error('Usage: storage:backup <directory> (an existing directory)');

			return self::FAILURE;
		}

		$backup = ApplicationFactory::create()->container->get(StorageBackup::class);
		assert($backup instanceof StorageBackup);

		try {
			$count = $backup->backup(
				rtrim($directory, '/'),
				static fn (string $key) => $io->writeln('  ' . $key, OutputInterface::VERBOSITY_VERBOSE),
			);
		} catch (RuntimeException $e) {
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$io->success(sprintf('Backed up %d stored object(s).', $count));

		return self::SUCCESS;
	}
}
