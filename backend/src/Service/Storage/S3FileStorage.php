<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Storage;

use AsyncAws\S3\S3Client;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** Stores objects in one bucket, created on first use (MinIO starts empty). */
final class S3FileStorage implements FileStorageInterface
{
	private bool $bucketEnsured = false;

	public function __construct(
		private readonly S3Client $client,
		private readonly S3Config $config,
		private readonly LoggerInterface $logger,
	)
	{
	}

	public function put(string $key, string $body, string $contentType): void
	{
		$this->ensureBucket();

		try {
			$this->client->putObject([
				'Bucket' => $this->config->bucket,
				'Key' => $key,
				'Body' => $body,
				'ContentType' => $contentType,
			])->resolve();
		} catch (Throwable $e) {
			throw new RuntimeException('Failed to store object "' . $key . '": ' . $e->getMessage(), 0, $e);
		}
	}

	public function get(string $key): string
	{
		$this->ensureBucket();

		try {
			return $this->client->getObject(['Bucket' => $this->config->bucket, 'Key' => $key])->getBody()->getContentAsString();
		} catch (Throwable $e) {
			throw new RuntimeException('Failed to read object "' . $key . '": ' . $e->getMessage(), 0, $e);
		}
	}

	public function delete(string $key): void
	{
		try {
			$this->ensureBucket();
			$this->client->deleteObject(['Bucket' => $this->config->bucket, 'Key' => $key])->resolve();
		} catch (Throwable $e) {
			$this->logger->warning('Failed to delete S3 object "' . $key . '": ' . $e->getMessage());
		}
	}

	private function ensureBucket(): void
	{
		if ($this->bucketEnsured) {
			return;
		}

		try {
			if (!$this->client->bucketExists(['Bucket' => $this->config->bucket])->isSuccess()) {
				$this->client->createBucket(['Bucket' => $this->config->bucket])->resolve();
			}
		} catch (Throwable $e) {
			throw new RuntimeException('Failed to ensure S3 bucket "' . $this->config->bucket . '": ' . $e->getMessage(), 0, $e);
		}

		$this->bucketEnsured = true;
	}
}
