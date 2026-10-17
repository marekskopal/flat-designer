<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Deterministic env for the suite. putenv() wins over container-provided variables when the
// application reads getenv(). The database is only touched by integration tests (AppHarness), so
// pure unit tests also run on the host without the stack.
$env = static fn (string $name, string $default): string => is_string(getenv($name)) && getenv($name) !== '' ? (string) getenv(
	$name,
) : $default;

$testEnv = [
	'AUTHORIZATION_TOKEN_KEY' => 'test-token-key-test-token-key-test-token-key-0123',
	'MERCURE_SUBSCRIBER_JWT_KEY' => 'test-mercure-subscriber-key-0123456789-0123456789',
	'MERCURE_PUBLISHER_JWT_KEY' => 'test-mercure-publisher-key-0123456789-0123456789',
	'MYSQL_HOST' => $env('MYSQL_HOST', 'db'),
	'MYSQL_USER' => $env('MYSQL_USER', 'flat_designer'),
	'MYSQL_PASSWORD' => $env('MYSQL_PASSWORD', 'flat_designer'),
	'MYSQL_DATABASE' => $env('TEST_MYSQL_DATABASE', 'flat_designer_test'),
	'RATE_LIMIT_LOGIN_ATTEMPTS' => '5',
	'RATE_LIMIT_LOGIN_BACKOFF_CAP_SECONDS' => '3600',
	'BACKEND_CORS_ALLOWED_ORIGIN' => '*',
	'BACKEND_LOG_LEVEL' => 'production',
	'S3_BUCKET' => 'test-bucket',
	'FILE_MAX_SIZE_MB' => '1',
];
foreach ($testEnv as $name => $value) {
	putenv($name . '=' . $value);
	$_ENV[$name] = $value;
	$_SERVER[$name] = $value;
}
