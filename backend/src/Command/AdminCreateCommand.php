<?php

declare(strict_types=1);

namespace FlatDesigner\Command;

use FlatDesigner\App\ApplicationFactory;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Service\Provider\UserProvider;
use FlatDesigner\Validator\UserFieldValidator;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Creates an admin account. Prompts for the email and password when they are not given as options. */
final class AdminCreateCommand extends AbstractCommand
{
	private const int MinPasswordLength = 12;

	protected function configure(): void
	{
		$this->setName('admin:create')
			->setDescription('Create an admin user. Prompts for credentials if --email/--password are not given.')
			->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email address')
			->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password (at least 12 characters)')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name', 'Administrator');
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);

		$userProvider = ApplicationFactory::create()->container->get(UserProvider::class);
		assert($userProvider instanceof UserProvider);

		$email = $this->resolveEmail($input, $io);
		if ($email === null) {
			return self::FAILURE;
		}

		if ($userProvider->getUserByEmail($email) !== null) {
			$io->error(sprintf('A user with email %s already exists.', $email));

			return self::FAILURE;
		}

		$password = $this->resolvePassword($input, $io);
		if ($password === null) {
			return self::FAILURE;
		}

		$nameOption = $input->getOption('name');
		$name = UserFieldValidator::normalizeName(is_string($nameOption) ? $nameOption : '') ?? 'Administrator';

		$userProvider->createUser($email, $password, $name, UserRoleEnum::Admin);

		$io->success(sprintf('Created admin %s.', $email));

		return self::SUCCESS;
	}

	private function resolveEmail(InputInterface $input, SymfonyStyle $io): ?string
	{
		$email = $input->getOption('email');
		if (is_string($email) && $email !== '') {
			$normalized = UserFieldValidator::normalizeEmail($email);
			if ($normalized === null) {
				$io->error('Invalid email address.');
			}

			return $normalized;
		}

		if (!$input->isInteractive()) {
			$io->error('Missing --email and stdin is not interactive.');

			return null;
		}

		$question = new Question('Email: ');
		$question->setValidator(static function (mixed $value): string {
			$normalized = is_string($value) ? UserFieldValidator::normalizeEmail($value) : null;

			return $normalized ?? throw new \RuntimeException('Invalid email address.');
		});

		return $this->ask($input, $io, $question);
	}

	private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
	{
		$password = $input->getOption('password');
		if (is_string($password) && $password !== '') {
			if (strlen($password) < self::MinPasswordLength) {
				$io->error(sprintf('Password must be at least %d characters.', self::MinPasswordLength));

				return null;
			}

			return $password;
		}

		if (!$input->isInteractive()) {
			$io->error('Missing --password and stdin is not interactive.');

			return null;
		}

		$first = new Question(sprintf('Password (min %d chars): ', self::MinPasswordLength));
		$first->setHidden(true)->setHiddenFallback(false);
		$first->setValidator(static function (mixed $value): string {
			if (!is_string($value) || strlen($value) < self::MinPasswordLength) {
				throw new \RuntimeException(sprintf('Password must be at least %d characters.', self::MinPasswordLength));
			}

			return $value;
		});
		$entered = $this->ask($input, $io, $first);

		$confirm = new Question('Confirm password: ');
		$confirm->setHidden(true)->setHiddenFallback(false);

		if ($entered !== $this->ask($input, $io, $confirm)) {
			$io->error('Passwords do not match.');

			return null;
		}

		return $entered;
	}

	private function ask(InputInterface $input, OutputInterface $output, Question $question): string
	{
		$helper = $this->getHelper('question');
		assert($helper instanceof QuestionHelper);

		$answer = $helper->ask($input, $output, $question);

		return is_string($answer) ? $answer : '';
	}
}
