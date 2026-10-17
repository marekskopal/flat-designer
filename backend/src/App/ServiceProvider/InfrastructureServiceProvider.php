<?php

declare(strict_types=1);

namespace FlatDesigner\App\ServiceProvider;

use FlatDesigner\Service\Cors\CorsPolicy;
use FlatDesigner\Service\Logger\StderrLogger;
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
	}
}
