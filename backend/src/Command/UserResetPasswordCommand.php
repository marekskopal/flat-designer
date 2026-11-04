<?php

declare(strict_types=1);

namespace Izbik\Command;

use Izbik\App\ApplicationFactory;
use Izbik\Service\Authentication\LoginAttemptService;
use Izbik\Service\Provider\UserProvider;
use Izbik\Validator\PasswordValidator;
use Izbik\Validator\UserFieldValidator;
use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sets a user's password from the server (`make reset-password EMAIL=…`), for a server that sends no mail: prompts
 * for it unless `--password` gives it. Like a reset link, it signs the user out everywhere and lifts a sign-in lock.
 */
final class UserResetPasswordCommand extends AbstractCommand
{
	protected function configure(): void
	{
		$this->setName('user:reset-password')
			->setDescription('Set a user\'s password. Prompts for it if --password is not given.')
			->addArgument('email', InputArgument::REQUIRED, 'The user\'s email address')
			->addOption(
				'password',
				null,
				InputOption::VALUE_REQUIRED,
				'New password (8+ characters with upper and lower case and a digit)',
			);
	}

	protected function process(InputInterface $input, OutputInterface $output): int
	{
		$io = new SymfonyStyle($input, $output);
		$email = $input->getArgument('email');
		$normalized = is_string($email) ? UserFieldValidator::normalizeEmail($email) : null;
		if ($normalized === null) {
			$io->error('Invalid email address.');

			return self::FAILURE;
		}

		$container = ApplicationFactory::create()->container;
		$users = $container->get(UserProvider::class);
		assert($users instanceof UserProvider);
		$loginAttempts = $container->get(LoginAttemptService::class);
		assert($loginAttempts instanceof LoginAttemptService);

		$user = $users->getUserByEmail($normalized);
		if ($user === null) {
			$io->error(sprintf('No user with email %s.', $normalized));

			return self::FAILURE;
		}

		$password = $this->resolvePassword($input, $io);
		if ($password === null) {
			return self::FAILURE;
		}

		$users->updatePassword($user, $password);
		$loginAttempts->recordSuccess($user);
		$io->success(sprintf('Set the password of %s; they are signed out everywhere.', $normalized));

		return self::SUCCESS;
	}

	private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
	{
		$password = $input->getOption('password');
		if (is_string($password) && $password !== '') {
			if (!PasswordValidator::isValid($password)) {
				$io->error(PasswordValidator::Requirements);

				return null;
			}

			return $password;
		}
		if (!$input->isInteractive()) {
			$io->error('Missing --password and stdin is not interactive.');

			return null;
		}

		$first = new Question('New password: ');
		$first->setHidden(true)->setHiddenFallback(false);
		$first->setValidator(static function (mixed $value): string {
			return is_string($value) && PasswordValidator::isValid($value)
				? $value
				: throw new RuntimeException(
					PasswordValidator::Requirements,
				);
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
