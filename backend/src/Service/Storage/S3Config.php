<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Storage;

use SensitiveParameter;

final readonly class S3Config
{
	private const int DefaultMaxFileSizeMb = 25;

	public function __construct(
		public string $endpoint,
		public string $region,
		public string $bucket,
		public string $accessKey,
		#[SensitiveParameter] public string $secretKey,
		public bool $pathStyleEndpoint,
		public int $maxFileSizeBytes,
	) {
	}

	public static function fromEnv(): self
	{
		$region = (string) getenv('S3_REGION');
		$maxMb = (int) getenv('FILE_MAX_SIZE_MB');

		return new self(
			endpoint: (string) getenv('S3_ENDPOINT'),
			region: $region === '' ? 'us-east-1' : $region,
			bucket: (string) getenv('S3_BUCKET'),
			accessKey: (string) getenv('S3_ACCESS_KEY'),
			secretKey: (string) getenv('S3_SECRET_KEY'),
			pathStyleEndpoint: in_array(strtolower((string) getenv('S3_USE_PATH_STYLE')), ['1', 'true', 'yes'], true),
			maxFileSizeBytes: ($maxMb > 0 ? $maxMb : self::DefaultMaxFileSizeMb) * 1024 * 1024,
		);
	}
}
