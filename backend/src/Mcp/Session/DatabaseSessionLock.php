<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Session;

use MarekSkopal\ORM\Database\DatabaseInterface;
use Mcp\Exception\SessionLockException;
use Mcp\Exception\SessionLockTimeoutException;
use Mcp\Server\Session\SessionLockInterface;
use PDOException;
use Symfony\Component\Uid\Uuid;

/**
 * Serializes the requests of one MCP session with MariaDB's named locks (`GET_LOCK('mcp:<sessionId>')`), which
 * every worker shares like the `mcp_sessions` store. A request that waits longer than the timeout is answered by
 * the SDK with `503`. The lock belongs to the database connection, so it also goes away with a dead worker.
 */
final class DatabaseSessionLock implements SessionLockInterface
{
	/** @var array<string, true> MariaDB's locks are re-entrant per connection, the interface's are not. */
	private array $held = [];

	/** @param int $timeout how long to wait for a lock held by another request, in seconds */
	public function __construct(private readonly DatabaseInterface $database, private readonly int $timeout)
	{
	}

	public function acquire(Uuid $sessionId): void
	{
		if (!$this->lock($sessionId, $this->timeout)) {
			throw new SessionLockTimeoutException(
				sprintf('Timed out after %ds waiting for the lock of session "%s".', $this->timeout, $sessionId->toRfc4122()),
			);
		}
	}

	public function tryAcquire(Uuid $sessionId): bool
	{
		return $this->lock($sessionId, 0);
	}

	public function release(Uuid $sessionId): void
	{
		$name = self::lockName($sessionId);
		if (!isset($this->held[$name])) {
			throw new SessionLockException(sprintf('The lock of session "%s" is not held.', $sessionId->toRfc4122()));
		}

		unset($this->held[$name]);
		if ($this->query('SELECT RELEASE_LOCK(?)', [$name]) !== 1) {
			throw new SessionLockException(sprintf('Failed to release the lock of session "%s".', $sessionId->toRfc4122()));
		}
	}

	private function lock(Uuid $sessionId, int $timeout): bool
	{
		$name = self::lockName($sessionId);
		if (isset($this->held[$name])) {
			throw new SessionLockException(sprintf('The lock of session "%s" is already held.', $sessionId->toRfc4122()));
		}

		// 1 = locked, 0 = timed out, NULL = an error (e.g. the connection was killed).
		$result = $this->query('SELECT GET_LOCK(?, ?)', [$name, $timeout]);
		if ($result === null) {
			throw new SessionLockException(sprintf('Failed to lock session "%s".', $sessionId->toRfc4122()));
		}
		if ($result === 0) {
			return false;
		}

		$this->held[$name] = true;

		return true;
	}

	/** @param list<string|int> $parameters */
	private function query(string $sql, array $parameters): ?int
	{
		try {
			$value = $this->database->execute($sql, $parameters, cached: false)->fetchColumn();
		} catch (PDOException $exception) {
			throw new SessionLockException($exception->getMessage(), previous: $exception);
		}

		return is_numeric($value) ? (int) $value : null;
	}

	private static function lockName(Uuid $sessionId): string
	{
		return 'mcp:' . $sessionId->toRfc4122();
	}
}
