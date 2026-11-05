<?php

declare(strict_types=1);

namespace Izbik\Controller;

use Izbik\Dto\ExampleDto;
use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Response\NotFoundResponse;
use Izbik\Route\Routes;
use Izbik\Service\Example\Example;
use Izbik\Service\Example\ExampleCatalogue;
use Izbik\Service\Example\ExampleProjectService;
use Izbik\Service\Request\RequestService;
use Laminas\Diactoros\Response\JsonResponse;
use MarekSkopal\Router\Attribute\RouteGet;
use MarekSkopal\Router\Attribute\RoutePost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The example projects shipped with the app, which a user copies into a project of their own to start from. */
final readonly class ExampleController
{
	public function __construct(
		private ExampleCatalogue $catalogue,
		private ExampleProjectService $exampleProjectService,
		private RequestService $requestService,
	) {
	}

	#[RouteGet(Routes::Examples->value)]
	public function actionGetExamples(ServerRequestInterface $request): ResponseInterface
	{
		return new JsonResponse(
			array_map(
				static fn (Example $example): ExampleDto => ExampleDto::fromExample($example->in(self::locale($request))),
				$this->catalogue->all(),
			),
		);
	}

	#[RoutePost(Routes::ExampleProjects->value)]
	public function actionPostExampleProject(ServerRequestInterface $request, string $slug): ResponseInterface
	{
		$example = $this->catalogue->find($slug);
		if ($example === null) {
			return new NotFoundResponse('Example not found.');
		}

		return new JsonResponse(
			$this->exampleProjectService->create($this->requestService->getUser($request), $example->in(self::locale($request))),
			201,
		);
	}

	/** The project is named as the user reads the example. */
	private static function locale(ServerRequestInterface $request): LocaleEnum
	{
		return LocaleEnum::fromAcceptLanguage($request->getHeaderLine('Accept-Language'));
	}
}
