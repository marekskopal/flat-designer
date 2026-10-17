<?php

declare(strict_types=1);

namespace FlatDesigner\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCommand extends Command
{
	abstract protected function process(InputInterface $input, OutputInterface $output): int;

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		return $this->process($input, $output);
	}
}
