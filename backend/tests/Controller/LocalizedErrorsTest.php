<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Route\Routes;
use Izbik\Service\Translator\ErrorTranslator;
use Izbik\Service\Translator\LocalizedErrorHandler;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LocalizedErrorHandler::class)]
final class LocalizedErrorsTest extends IntegrationTestCase
{
	private const array Czech = ['Accept-Language' => 'cs-CZ,cs;q=0.9,en;q=0.8'];

	public function testErrorsComeInTheRequestsLanguage(): void
	{
		$user = Fixture::createUser();

		$blank = $this->request('PATCH', Routes::CurrentUser->value, ['name' => ''], $user, headers: self::Czech);
		self::assertSame(422, $blank->getStatusCode());
		self::assertSame(['code' => 422, 'message' => 'Jméno je povinné.'], $this->jsonBody($blank));

		// Thrown and turned into a response by the route strategy, with a value filled in.
		$wrong = $this->request('PATCH', Routes::CurrentUser->value, ['locale' => 'de'], $user, headers: self::Czech);
		self::assertSame(400, $wrong->getStatusCode());
		self::assertSame('Pole „locale“ musí být jedno z: "en", "cs".', $this->jsonBody($wrong)['message'] ?? null);

		// Before authentication too.
		$anonymous = $this->request('GET', Routes::CurrentUser->value, headers: self::Czech);
		self::assertSame(401, $anonymous->getStatusCode());
		self::assertSame('Je potřeba hlavička Authorization s tokenem Bearer.', $this->jsonBody($anonymous)['message'] ?? null);
	}

	public function testErrorsStayEnglishForEnglishAndUnknownLanguages(): void
	{
		$user = Fixture::createUser();

		foreach ([[], ['Accept-Language' => 'en-US,en;q=0.9'], ['Accept-Language' => 'de-DE']] as $headers) {
			$response = $this->request('PATCH', Routes::CurrentUser->value, ['name' => ''], $user, headers: $headers);
			self::assertSame('Name is required.', $this->jsonBody($response)['message'] ?? null);
		}
	}

	public function testAnImportsWarningsComeInTheRequestsLanguage(): void
	{
		$request = new ServerRequest([], [], '/api/projects/import', 'POST', headers: self::Czech);
		$response = new JsonResponse(['warnings' => ['File "plan.pdf" was left out: The file is empty.', 'Something new.']], 201);

		$localized = LocalizedErrorHandler::localize($response, $request, new ErrorTranslator());

		self::assertSame(201, $localized->getStatusCode());
		self::assertSame(
			['warnings' => ['Soubor „plan.pdf“ byl vynechán: Soubor je prázdný.', 'Something new.']],
			json_decode((string) $localized->getBody(), true),
		);
	}
}
