<?php

declare(strict_types=1);

namespace FlatDesigner;

require_once __DIR__ . '/../vendor/autoload.php';

use FlatDesigner\App\ApplicationFactory;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Service\Actor\ActorContext;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Log\LoggerInterface;

$application = ApplicationFactory::create();

$logger = $application->container->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);

$actorContext = $application->container->get(ActorContext::class);
assert($actorContext instanceof ActorContext);

$emitter = new SapiEmitter();

$handler = static function () use ($application, $logger, $emitter, $actorContext): void {
	$actorContext->setHuman();

	try {
		$request = ServerRequestFactory::fromGlobals();
		$response = $application->handler->handle($request);
		$emitter->emit($response);
	} catch (\Throwable $e) {
		$logger->error($e->getMessage(), ['exception' => $e]);
		$emitter->emit(ErrorResponse::fromException($e));
	}
};

while (frankenphp_handle_request($handler)) {
	$application->dbContext->resetOrmState();
	gc_collect_cycles();
}
