<?php

declare(strict_types=1);

namespace FlatDesigner;

require_once __DIR__ . '/../vendor/autoload.php';

use FlatDesigner\App\ApplicationFactory;
use FlatDesigner\Mcp\McpUserContext;
use FlatDesigner\Response\ErrorResponse;
use FlatDesigner\Service\Actor\ActorContext;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Psr\Log\LoggerInterface;

$application = ApplicationFactory::create();

$logger = $application->container->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);

$actorContext = $application->container->get(ActorContext::class);
assert($actorContext instanceof ActorContext);
$mcpUserContext = $application->container->get(McpUserContext::class);
assert($mcpUserContext instanceof McpUserContext);
$updatePublisher = $application->container->get(ProjectUpdatePublisher::class);
assert($updatePublisher instanceof ProjectUpdatePublisher);

$emitter = new SapiEmitter();

$handler = static function () use ($application, $logger, $emitter, $actorContext, $mcpUserContext, $updatePublisher): void {
	// Per-request contexts in this long-lived worker.
	$actorContext->setHuman();
	$mcpUserContext->clear();
	$updatePublisher->reset();

	try {
		$request = ServerRequestFactory::fromGlobals();
		$response = $application->handler->handle($request);
		$emitter->emit($response);
	} catch (\Throwable $e) {
		$logger->error($e->getMessage(), ['exception' => $e]);
		$emitter->emit(ErrorResponse::fromException($e));
	}

	// Live updates go out after the client has its response, and only for committed changes.
	frankenphp_finish_request();
	$updatePublisher->flush();
};

while (frankenphp_handle_request($handler)) {
	$application->dbContext->resetOrmState();
	gc_collect_cycles();
}
