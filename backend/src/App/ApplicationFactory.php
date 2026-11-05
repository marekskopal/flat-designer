<?php

declare(strict_types=1);

namespace Izbik\App;

use Izbik\App\ServiceProvider\InfrastructureServiceProvider;
use Izbik\App\ServiceProvider\OrmServiceProvider;
use Izbik\Middleware\AuthorizationMiddleware;
use Izbik\Middleware\CorsMiddleware;
use Izbik\Route\Strategy\JsonStrategy;
use Izbik\Service\Dbal\DbContext;
use Izbik\Service\Translator\ErrorTranslator;
use Izbik\Service\Translator\LocalizedErrorHandler;
use League\Container\Container;
use League\Container\ReflectionContainer;
use MarekSkopal\Router\Builder\RouterBuilder;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ApplicationFactory
{
	public static function create(): Application
	{
		$dbContext = self::initializeDbContext();
		$container = self::initializeContainer($dbContext);
		$requestHandler = self::initializeRequestHandler($container);

		return new Application($container, $requestHandler, $dbContext);
	}

	private static function initializeContainer(DbContext $dbContext): ContainerInterface
	{
		$container = new Container();
		$container->defaultToShared();
		$container->delegate(new ReflectionContainer(true));

		$container->addServiceProvider(new InfrastructureServiceProvider());
		$container->addServiceProvider(new OrmServiceProvider($dbContext));

		return $container;
	}

	private static function initializeRequestHandler(ContainerInterface $container): RequestHandlerInterface
	{
		$strategy = $container->get(JsonStrategy::class);
		if (!$strategy instanceof JsonStrategy) {
			throw new \RuntimeException('JsonStrategy not found in container.');
		}
		$strategy->setContainer($container);

		$router = (new RouterBuilder())
			->setClassDirectories([__DIR__ . '/../Controller'])
			->build();

		$router->setStrategy($strategy);

		$corsMiddleware = $container->get(CorsMiddleware::class);
		if (!$corsMiddleware instanceof CorsMiddleware) {
			throw new \RuntimeException('CorsMiddleware not found in container.');
		}
		$router->middleware($corsMiddleware);

		$authorizationMiddleware = $container->get(AuthorizationMiddleware::class);
		if (!$authorizationMiddleware instanceof AuthorizationMiddleware) {
			throw new \RuntimeException('AuthorizationMiddleware not found in container.');
		}
		$router->middleware($authorizationMiddleware);

		$errorTranslator = $container->get(ErrorTranslator::class);
		if (!$errorTranslator instanceof ErrorTranslator) {
			throw new \RuntimeException('ErrorTranslator not found in container.');
		}

		return new LocalizedErrorHandler($router, $errorTranslator);
	}

	private static function initializeDbContext(): DbContext
	{
		/** @var non-empty-string $host */
		$host = (string) getenv('MYSQL_HOST');
		/** @var non-empty-string $database */
		$database = (string) getenv('MYSQL_DATABASE');
		/** @var non-empty-string $user */
		$user = (string) getenv('MYSQL_USER');
		/** @var non-empty-string $password */
		$password = (string) getenv('MYSQL_PASSWORD');

		return new DbContext($host, $database, $user, $password);
	}
}
