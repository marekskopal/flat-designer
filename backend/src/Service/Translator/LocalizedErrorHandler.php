<?php

declare(strict_types=1);

namespace Izbik\Service\Translator;

use Izbik\Model\Entity\Enum\LocaleEnum;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Puts the message of an error response (`{code, message}`, `ErrorResponse`), and the `warnings` an import answers with
 * (a project file, a DXF plan), into the request's language (`Accept-Language`, which the app sets to the language it
 * shows), by `ErrorTranslator`. MCP is left alone: agents read English.
 */
final readonly class LocalizedErrorHandler implements RequestHandlerInterface
{
	public function __construct(private RequestHandlerInterface $handler, private ErrorTranslator $translator)
	{
	}

	public function handle(ServerRequestInterface $request): ResponseInterface
	{
		return self::localize($this->handler->handle($request), $request, $this->translator);
	}

	public static function localize(
		ResponseInterface $response,
		ServerRequestInterface $request,
		ErrorTranslator $translator,
	): ResponseInterface
	{
		$locale = LocaleEnum::fromAcceptLanguage($request->getHeaderLine('Accept-Language'));
		$error = $response->getStatusCode() >= 400;
		if (!self::mayCarryMessages($response, $request, $locale, $error)) {
			return $response;
		}
		$body = json_decode((string) $response->getBody(), true);
		if (!is_array($body)) {
			return $response;
		}
		$translate = static fn (mixed $text): mixed => is_string($text) ? $translator->translate($text, $locale) : $text;
		if ($error && isset($body['code'], $body['message'])) {
			$body['message'] = $translate($body['message']);
		} elseif (!$error && isset($body['warnings']) && is_array($body['warnings'])) {
			$body['warnings'] = array_map($translate, $body['warnings']);
		} else {
			return $response;
		}

		$localized = new JsonResponse($body, $response->getStatusCode());
		foreach ($response->getHeaders() as $name => $values) {
			if (strtolower((string) $name) !== 'content-length') {
				$localized = $localized->withHeader((string) $name, $values);
			}
		}

		return $localized;
	}

	/** An error, or an import's answer (posted, with warnings), outside MCP, for a language other than English. */
	private static function mayCarryMessages(
		ResponseInterface $response,
		ServerRequestInterface $request,
		LocaleEnum $locale,
		bool $error,
	): bool
	{
		if ($locale === LocaleEnum::En || str_starts_with($request->getUri()->getPath(), '/mcp')) {
			return false;
		}
		if (!str_contains($response->getHeaderLine('Content-Type'), 'json')) {
			return false;
		}

		return $error || ($request->getMethod() === 'POST' && str_contains((string) $response->getBody(), '"warnings":['));
	}
}
