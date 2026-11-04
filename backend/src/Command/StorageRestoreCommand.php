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

/** Puts the objects of a storage backup back into the storage, for `make restore` (see StorageBackup). */
final class StorageRestoreCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('storage:restore')
			->setDescription('Put the objects of a storage backup back into the storage.')
			->addArgument('directory', InputArgument::REQUIRED, 'Directory written by storage:backup');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$directory = $input->getArgument('directory');
		if (!is_string($directory) || !is_dir($directory)) {
			$io->error('Usage: storage:restore <directory> (an existing directory)');

			return self::FAILURE;
		}

		$backup = ApplicationFactory::create()->container->get(StorageBackup::class);
		assert($backup instanceof StorageBackup);

		try {
			$count = $backup->restore(
				rtrim($directory, '/'),
				static fn (string $key) => $io->writeln('  ' . $key, OutputInterface::VERBOSITY_VERBOSE),
			);
		} catch (RuntimeException $e) {
			$io->error($e->getMessage());

			return self::FAILURE;
		}

		$io->success(sprintf('Restored %d stored object(s).', $count));

		return self::SUCCESS;
	}
}
