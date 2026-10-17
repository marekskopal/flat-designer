<?php

declare(strict_types=1);

namespace FlatDesigner\App\ServiceProvider;

use AsyncAws\S3\S3Client;
use FlatDesigner\Service\Authentication\RateLimitConfig;
use FlatDesigner\Service\Authentication\TokenService;
use FlatDesigner\Service\Cors\CorsPolicy;
use FlatDesigner\Service\Logger\StderrLogger;
use FlatDesigner\Service\Storage\FileStorageInterface;
use FlatDesigner\Service\Storage\S3Config;
use FlatDesigner\Service\Storage\S3FileStorage;
use Http\Discovery\Psr17FactoryDiscovery;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

final class InfrastructureServiceProvider extends AbstractServiceProvider
{
	public function provides(string $id): bool
	{
		return in_array($id, [
			LoggerInterface::class,
			ResponseFactoryInterface::class,
			CorsPolicy::class,
			TokenService::class,
			RateLimitConfig::class,
			S3Config::class,
			S3Client::class,
			FileStorageInterface::class,
		], true);
	}

	public function register(): void
	{
		$container = $this->getContainer();

		$container->add(
			LoggerInterface::class,
			static fn (): LoggerInterface => new StderrLogger((string) getenv('BACKEND_LOG_LEVEL') === 'debug'),
		);

		$container->add(
			ResponseFactoryInterface::class,
			static fn (): ResponseFactoryInterface => Psr17FactoryDiscovery::findResponseFactory(),
		);

		$container->add(
			CorsPolicy::class,
			static fn (): CorsPolicy => CorsPolicy::fromEnvValue((string) getenv('BACKEND_CORS_ALLOWED_ORIGIN')),
		);

		$container->add(TokenService::class, static fn (): TokenService => TokenService::fromEnv());

		$container->add(RateLimitConfig::class, static fn (): RateLimitConfig => RateLimitConfig::fromEnv());

		$container->add(S3Config::class, static fn (): S3Config => S3Config::fromEnv());

		$container->add(S3Client::class, static function () use ($container): S3Client {
			$config = $container->get(S3Config::class);
			assert($config instanceof S3Config);
			$options = [
				'accessKeyId' => $config->accessKey,
				'accessKeySecret' => $config->secretKey,
				'region' => $config->region,
				'pathStyleEndpoint' => $config->pathStyleEndpoint ? 'true' : 'false',
			];
			if ($config->endpoint !== '') {
				$options['endpoint'] = $config->endpoint;
			}

			return new S3Client($options);
		});

		$container->add(FileStorageInterface::class, static function () use ($container): FileStorageInterface {
			$client = $container->get(S3Client::class);
			assert($client instanceof S3Client);
			$config = $container->get(S3Config::class);
			assert($config instanceof S3Config);
			$logger = $container->get(LoggerInterface::class);
			assert($logger instanceof LoggerInterface);

			return new S3FileStorage($client, $config, $logger);
		});
	}
}
