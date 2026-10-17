<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Session;

use MarekSkopal\ORM\Database\DatabaseInterface;
use Mcp\Server\Session\SessionStoreInterface;
use PDO;
use Symfony\Component\Uid\Uuid;

/** MCP transport sessions in MariaDB (`mcp_sessions`), so the stack needs no Redis. Expired rows are gc'd. */
final readonly class DatabaseSessionStore implements SessionStoreInterface
{
	public function __construct(private DatabaseInterface $database, private int $ttl)
	{
	}

	public function exists(Uuid $id): bool
	{
		return $this->read($id) !== false;
	}

	public function read(Uuid $id): string|false
	{
		$data = $this->database->execute(
			'SELECT data FROM mcp_sessions WHERE id = ? AND expires_at >= ?',
			[$id->toRfc4122(), time()],
			cached: false,
		)->fetchColumn();

		return is_string($data) ? $data : false;
	}

	public function write(Uuid $id, string $data): bool
	{
		$this->database->execute(
			'INSERT INTO mcp_sessions (id, data, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)',
			[$id->toRfc4122(), $data, time() + $this->ttl],
			cached: false,
		);

		return true;
	}

	public function destroy(Uuid $id): bool
	{
		$this->database->execute('DELETE FROM mcp_sessions WHERE id = ?', [$id->toRfc4122()], cached: false);

		return true;
	}

	/** @return list<Uuid> */
	public function gc(): array
	{
		$expired = $this->database->execute('SELECT id FROM mcp_sessions WHERE expires_at < ?', [time()], cached: false)->fetchAll(
			PDO::FETCH_COLUMN,
		);
		$this->database->execute('DELETE FROM mcp_sessions WHERE expires_at < ?', [time()], cached: false);

		$ids = [];
		foreach ($expired as $id) {
			if (is_string($id) && Uuid::isValid($id)) {
				$ids[] = Uuid::fromString($id);
			}
		}

		return $ids;
	}
}
