<?php

declare(strict_types=1);

namespace Izbik\App\ServiceProvider;

use AsyncAws\S3\S3Client;
use Http\Discovery\Psr17FactoryDiscovery;
use Izbik\Mcp\Server\IzbikServer;
use Izbik\Service\Authentication\RateLimitConfig;
use Izbik\Service\Authentication\TokenService;
use Izbik\Service\Cors\CorsPolicy;
use Izbik\Service\Logger\StderrLogger;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Realtime\MercureAccessToken;
use Izbik\Service\Realtime\MercureCookieIssuer;
use Izbik\Service\Realtime\MercurePublisherTokenProvider;
use Izbik\Service\Realtime\NullMercureHub;
use Izbik\Service\Storage\FileStorageInterface;
use Izbik\Service\Storage\S3Config;
use Izbik\Service\Storage\S3FileStorage;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\ProtocolVersion;

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
			IzbikServer::class,
			HubInterface::class,
			MercureCookieIssuer::class,
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

		$container->add(HubInterface::class, static function (): HubInterface {
			$url = (string) getenv('MERCURE_PUBLISH_URL');
			$key = (string) getenv('MERCURE_PUBLISHER_JWT_KEY');
			if ($url === '' || $key === '') {
				return new NullMercureHub();
			}

			return new Hub(
				$url,
				new MercurePublisherTokenProvider($key, MercureAccessToken::resourceIdentifier()),
				protocolVersion: ProtocolVersion::V1,
			);
		});

		$container->add(MercureCookieIssuer::class, static function () use ($container): MercureCookieIssuer {
			$projectProvider = $container->get(ProjectProvider::class);
			assert($projectProvider instanceof ProjectProvider);

			return new MercureCookieIssuer(
				$projectProvider,
				(string) getenv('MERCURE_SUBSCRIBER_JWT_KEY'),
				MercureAccessToken::resourceIdentifier(),
			);
		});

		$container->add(IzbikServer::class, static function () use ($container): IzbikServer {
			$logger = $container->get(LoggerInterface::class);
			assert($logger instanceof LoggerInterface);

			return new IzbikServer($container, $logger);
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
