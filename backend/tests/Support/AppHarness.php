<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use FlatDesigner\App\Application;
use FlatDesigner\App\ApplicationFactory;
use PDO;
use PDOException;
use Psr\Container\ContainerInterface;

/**
 * Per-suite singleton for integration tests: on first use it recreates the test database, runs all
 * migrations and builds the Application once. `resetState()` empties the tables between tests.
 */
final class AppHarness
{
	/** Tables keeping their migrated content between tests: the migration log and the seeded catalog. */
	private const array PreservedTables = ['migrations', 'materials'];

	private static ?Application $application = null;

	/** @var list<string>|null */
	private static ?array $tables = null;

	public static function app(): Application
	{
		if (self::$application === null) {
			self::recreateDatabase();
			self::$application = ApplicationFactory::create();
			self::$application->dbContext->getMigrator()->migrate();
		}

		return self::$application;
	}

	public static function container(): ContainerInterface
	{
		return self::app()->container;
	}

	public static function pdo(): PDO
	{
		return self::app()->dbContext->getDatabase()->getPdo();
	}

	/** Empties every table except `PreservedTables` and clears the ORM identity map. */
	public static function resetState(): void
	{
		$pdo = self::pdo();

		self::$tables ??= self::listTables($pdo);

		$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
		foreach (self::$tables as $table) {
			$pdo->exec('TRUNCATE TABLE `' . $table . '`');
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

		self::app()->dbContext->resetOrmState();
	}

	/** @return list<string> */
	private static function listTables(PDO $pdo): array
	{
		$statement = $pdo->query('SHOW TABLES');
		if ($statement === false) {
			throw new \RuntimeException('SHOW TABLES query failed.');
		}

		$tables = [];
		foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
			if (is_string($table) && !in_array($table, self::PreservedTables, true)) {
				$tables[] = $table;
			}
		}

		return $tables;
	}

	private static function recreateDatabase(): void
	{
		$database = (string) getenv('MYSQL_DATABASE');
		if ($database === 'flat_designer') {
			throw new \RuntimeException('Refusing to run tests against the application database; set TEST_MYSQL_DATABASE.');
		}

		try {
			$pdo = new PDO(
				'mysql:host=' . getenv('MYSQL_HOST') . ';charset=utf8mb4',
				(string) getenv('MYSQL_USER'),
				(string) getenv('MYSQL_PASSWORD'),
				[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
			);
			$pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
			$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
		} catch (PDOException $e) {
			throw new \RuntimeException(
				sprintf('Cannot recreate test database "%s" (run the suite with `make test-backend`): %s', $database, $e->getMessage()),
				0,
				$e,
			);
		}
	}
}
