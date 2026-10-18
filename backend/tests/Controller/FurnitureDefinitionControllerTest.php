<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\FurnitureDefinitionController;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Furniture\DefinitionValidator;
use FlatDesigner\Service\Furniture\FurnitureDefinitionProvider;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(FurnitureDefinitionController::class)]
#[CoversClass(FurnitureDefinitionProvider::class)]
#[CoversClass(DefinitionValidator::class)]
final class FurnitureDefinitionControllerTest extends IntegrationTestCase
{
	private const array Definition = [
		'format' => 1,
		'size' => ['width' => 800, 'depth' => 300, 'height' => 1800],
		'parameters' => [['key' => 't', 'label' => 'Board thickness', 'type' => 'int', 'default' => 18, 'min' => 10, 'max' => 40, 'unit' => 'mm']],
		'derived' => [],
		'materialSlots' => [['key' => 'carcass', 'label' => 'Carcass', 'default' => 'kitchen-oak']],
		'parts' => [
			['type' => 'board', 'name' => 'Side', 'repeat' => ['count' => 2], 'x' => 'i * (W - t)', 'y' => 0, 'z' => 0, 'w' => 't', 'h' => 'H', 'd' => 'D', 'material' => 'carcass', 'grain' => 'vertical'],
			['type' => 'board', 'name' => 'Top', 'x' => 't', 'y' => 'H - t', 'z' => 0, 'w' => 'W - 2*t', 'h' => 't', 'd' => 'D', 'material' => 'carcass', 'edges' => ['front']],
		],
	];

	public function testUserKeepsADefinitionInTheirLibrary(): void
	{
		$user = Fixture::createUser();
		$created = $this->create($user, ['name' => ' Open shelf ', 'description' => 'Oak, no back']);

		self::assertSame(201, $created->getStatusCode());
		// Empty objects stay objects in the stored JSON.
		self::assertStringContainsString('"derived":{}', (string) $created->getBody());
		$definition = $this->jsonBody($created);
		self::assertSame('Open shelf', $definition['name']);
		self::assertSame('Oak, no back', $definition['description']);
		self::assertSame('user', $definition['scope']);
		self::assertSame($user->id, $definition['userId']);
		self::assertNull($definition['projectId']);
		self::assertTrue($definition['canEdit']);
		self::assertIsArray($definition['definition']);
		self::assertSame(self::Definition['parts'], $definition['definition']['parts']);

		$path = self::definitionPath(self::id($definition));
		self::assertSame(200, $this->request('GET', $path, authenticatedAs: $user)->getStatusCode());
		self::assertSame(404, $this->request('GET', $path, authenticatedAs: Fixture::createUser())->getStatusCode());
		self::assertSame(200, $this->request('GET', $path, authenticatedAs: Fixture::createAdmin())->getStatusCode());
	}

	public function testOnlyAdminsManageGlobalDefinitions(): void
	{
		$user = Fixture::createUser();
		self::assertSame(403, $this->create($user, ['scope' => 'global'])->getStatusCode());

		$created = $this->create(Fixture::createAdmin(), ['scope' => 'global']);
		self::assertSame(201, $created->getStatusCode());
		$path = self::definitionPath(self::id($this->jsonBody($created)));

		$read = $this->jsonBody($this->request('GET', $path, authenticatedAs: $user));
		self::assertSame('global', $read['scope']);
		self::assertFalse($read['canEdit']);
		self::assertSame(403, $this->request('PUT', $path, self::input(), $user)->getStatusCode());
		self::assertSame(403, $this->request('DELETE', $path, authenticatedAs: $user)->getStatusCode());
	}

	public function testProjectDefinitionsBelongToTheProject(): void
	{
		$owner = Fixture::createUser();
		$project = Fixture::createProject($owner);
		$stranger = Fixture::createUser();

		self::assertSame(404, $this->create($stranger, ['scope' => 'project', 'projectId' => $project->id])->getStatusCode());
		self::assertSame(422, $this->create($owner, ['scope' => 'project'])->getStatusCode());

		$created = $this->jsonBody($this->create($owner, ['scope' => 'project', 'projectId' => $project->id]));
		self::assertSame('project', $created['scope']);
		self::assertSame($project->id, $created['projectId']);
		self::assertNull($created['userId']);

		$path = self::definitionPath(self::id($created));
		self::assertSame(404, $this->request('GET', $path, authenticatedAs: $stranger)->getStatusCode());
		self::assertSame(200, $this->request('GET', $path, authenticatedAs: Fixture::createAdmin())->getStatusCode());
	}

	public function testListShowsGlobalOwnAndProjectDefinitions(): void
	{
		$admin = Fixture::createAdmin();
		$owner = Fixture::createUser();
		$other = Fixture::createUser();
		$project = Fixture::createProject($owner);
		$this->create($admin, ['name' => 'Global', 'scope' => 'global']);
		$this->create($owner, ['name' => 'Owner library']);
		$this->create($other, ['name' => 'Other library']);
		$this->create($owner, ['name' => 'Project', 'scope' => 'project', 'projectId' => $project->id]);

		$this->create($admin, ['name' => 'Admin library']);

		self::assertSame(['Global', 'Owner library'], $this->listNames($owner));
		self::assertSame(['Global', 'Owner library', 'Project'], $this->listNames($owner, $project->id));
		// With a project: what its items may use — an admin working on it sees the owner's library, not their own.
		self::assertSame(['Admin library', 'Global'], $this->listNames($admin));
		self::assertSame(['Global', 'Owner library', 'Project'], $this->listNames($admin, $project->id));
		self::assertSame(['Global', 'Other library'], $this->listNames($other));
		self::assertSame(
			404,
			$this->request(
				'GET',
				Routes::FurnitureDefinitions->value . '?projectId=' . $project->id,
				authenticatedAs: $other,
			)->getStatusCode(),
		);
		$malformed = $this->request('GET', Routes::FurnitureDefinitions->value . '?projectId=abc', authenticatedAs: $other);
		self::assertSame(400, $malformed->getStatusCode());
		self::assertSame(['Global', 'Other library'], $this->listNames($other, ''));
	}

	public function testUpdateAndDelete(): void
	{
		$user = Fixture::createUser();
		$path = self::definitionPath(self::id($this->jsonBody($this->create($user))));

		$definition = self::Definition;
		$definition['parameters'][0]['default'] = 25;
		$updated = $this->request('PUT', $path, self::input(['name' => 'Thick shelf', 'definition' => $definition]), $user);
		self::assertSame(200, $updated->getStatusCode());
		$body = $this->jsonBody($updated);
		self::assertSame('Thick shelf', $body['name']);
		self::assertIsArray($body['definition']);
		self::assertSame($definition['parameters'], $body['definition']['parameters']);

		// The scope is not an update field.
		$moved = $this->request('PUT', $path, self::input(['scope' => 'project', 'projectId' => Fixture::createProject($user)->id]), $user);
		self::assertSame(422, $moved->getStatusCode());

		self::assertSame(200, $this->request('DELETE', $path, authenticatedAs: $user)->getStatusCode());
		self::assertSame(404, $this->request('GET', $path, authenticatedAs: $user)->getStatusCode());
		self::assertSame([], $this->listNames($user));
	}

	public function testCopyToAnotherScope(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$global = self::id($this->jsonBody($this->create(Fixture::createAdmin(), ['name' => 'Sideboard', 'scope' => 'global'])));
		$copyPath = self::definitionPath($global) . '/copy';

		$copied = $this->request('POST', $copyPath, ['scope' => 'project', 'projectId' => $project->id], $user);
		self::assertSame(201, $copied->getStatusCode());
		$copy = $this->jsonBody($copied);
		self::assertNotSame($global, $copy['id']);
		self::assertSame('Sideboard', $copy['name']);
		self::assertSame('project', $copy['scope']);
		self::assertTrue($copy['canEdit']);

		self::assertSame(403, $this->request('POST', $copyPath, ['scope' => 'global'], $user)->getStatusCode());
		self::assertSame(400, $this->request('POST', $copyPath, ['scope' => 'team'], $user)->getStatusCode());
	}

	/** @return iterable<string, array{array<string, mixed>, non-empty-string}> */
	public static function invalidDefinitions(): iterable
	{
		$definition = self::Definition;
		$definition['parts'][0]['type'] = 'drawerBox';

		yield 'unknown part type' => [$definition, 'parts[0].type: Unknown part type'];

		$definition = self::Definition;
		$definition['parts'][1]['grain'] = 'diagonal';

		yield 'unknown option of a part' => [$definition, 'parts[1].grain: Must be one of "vertical", "horizontal".'];

		$definition = self::Definition;
		$definition['parts'][1]['color'] = '#ffffff';

		yield 'option of another part type' => [$definition, 'parts[1].color: A board part has no option "color"'];

		$definition = self::Definition;
		$definition['materialSlots'][0]['default'] = 'unobtainium';

		yield 'unknown material code' => [$definition, 'materialSlots[0].default: Must be the code of a material'];

		$definition = self::Definition;
		unset($definition['size']);

		yield 'no default size' => [$definition, 'size: Must be the default size'];

		$definition = self::Definition;
		$definition['colour'] = 'oak';

		yield 'unknown key' => [$definition, 'colour: Unknown key'];

		$definition = self::Definition;
		$definition['size']['width'] = 30;

		yield 'parts do not fit the default size' => [$definition, 'parts[1].w: "Top" gets a width of -6 mm.'];

		$definition = self::Definition;
		$definition['parts'][1]['h'] = 't +';

		yield 'expression error' => [$definition, 'parts[1].h: Unexpected end of expression.'];

		$definition = self::Definition;
		$definition['parameters'][] = ['key' => 'open', 'type' => 'bool', 'default' => true, 'min' => 0];

		yield 'key of another parameter type' => [$definition, 'parameters[1].min: Unknown key; known: key, label, type, default.'];

		$definition = self::Definition;
		$definition['parts'][1]['name'] = str_repeat('x', 256);

		yield 'part name too long' => [$definition, 'parts[1].name: Must be a string of at most 255 characters.'];
	}

	/**
	 * @param array<string, mixed> $definition
	 * @param non-empty-string $message
	 */
	#[DataProvider('invalidDefinitions')]
	public function testInvalidDefinitionIsRejectedWithItsPath(array $definition, string $message): void
	{
		$response = $this->create(Fixture::createUser(), ['definition' => $definition]);

		self::assertSame(422, $response->getStatusCode());
		self::assertStringStartsWith($message, self::stringField($this->jsonBody($response)['message']));
	}

	public function testValidateWithoutSaving(): void
	{
		$user = Fixture::createUser();

		$valid = $this->jsonBody(
			$this->request('POST', Routes::FurnitureDefinitionValidate->value, ['definition' => self::Definition], $user),
		);
		self::assertTrue($valid['valid']);
		self::assertNull($valid['error']);
		self::assertIsList($valid['parts']);
		self::assertSame(['Side', 'Side', 'Top'], array_column($valid['parts'], 'name'));
		self::assertSame([0, 782, 18], array_column($valid['parts'], 'x'));

		$definition = self::Definition;
		$definition['parts'][1]['w'] = 'W - 2*thickness';
		$invalid = $this->jsonBody(
			$this->request('POST', Routes::FurnitureDefinitionValidate->value, ['definition' => $definition], $user),
		);
		self::assertFalse($invalid['valid']);
		self::assertSame(
			['code' => 'unknownVariable', 'path' => 'parts[1].w', 'message' => 'parts[1].w: Unknown variable "thickness".'],
			$invalid['error'],
		);
		self::assertSame([], $invalid['parts']);
		self::assertSame([], $this->listNames($user));
	}

	public function testListParametersAssembliesAndExpressionOptions(): void
	{
		$cabinet = [
			'format' => 1,
			'size' => ['width' => 600, 'depth' => 580, 'height' => 760],
			'parameters' => [
				[
					'key' => 'fronts',
					'type' => 'list',
					'fields' => [
						['key' => 'type', 'type' => 'select', 'options' => ['door', 'drawer'], 'default' => 'door'],
						['key' => 'height', 'type' => 'int', 'min' => 1, 'optional' => true, 'unit' => 'mm'],
						['key' => 'count', 'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 2],
					],
					'default' => [['type' => 'drawer', 'height' => 180], []],
					'maxItems' => 6,
				]],
			'derived' => ['heights' => "share(field(fronts, 'height'), H, 3)", 'starts' => 'offsets(heights, 3)'],
			'parts' => [
				[
					'type' => 'assembly',
					'repeat' => ['over' => 'fronts', 'as' => 'f'],
					'x' => 0,
					'y' => 'starts[f_index]',
					'z' => 'D - 19',
					'w' => 'W',
					'h' => 'heights[f_index]',
					'd' => 19,
					'parts' => [
						[
							'type' => 'front',
							'name' => 'Leaf',
							'repeat' => ['count' => 'f.count', 'as' => 'k'],
							'x' => 'k * AW / f.count + 1',
							'y' => 0,
							'z' => 0,
							'w' => 'AW / f.count - 2',
							'h' => 'AH',
							'd' => 'AD',
							'kind' => ['expr' => 'f.type'],
							'handleSide' => ['expr' => "f.count == 2 && k == 0 ? 'right' : 'left'"],
						]],
				]],
		];
		$user = Fixture::createUser();

		$created = $this->create($user, ['definition' => $cabinet]);
		self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
		// The empty default item and the expression options stay objects.
		self::assertStringContainsString('"default":[{"type":"drawer","height":180},{}]', (string) $created->getBody());
		self::assertStringContainsString('"kind":{"expr":"f.type"}', (string) $created->getBody());

		$bad = [
			'an expression option giving a wrong value' => [
				['parts', 0, 'parts', 0, 'handleSide'],
				['expr' => "'up'"],
				'parts[0].parts[0].handleSide: Must be one of "left", "right".',
			],
			'an option of an assembly' => [['parts', 0, 'grain'], 'vertical', 'parts[0].grain: An assembly part has no option "grain".'],
			'an unknown option written as an expression' => [
				['parts', 0, 'parts', 0, 'grian'],
				['expr' => "'vertical'"],
				'parts[0].parts[0].grian: A front part has no option "grian"',
			],
			'an unknown key of a field' => [['parameters', 0, 'fields', 1, 'optinal'], true, 'parameters[0].fields[1].optinal: Unknown key'],
			'a child of unknown type' => [['parts', 0, 'parts', 0, 'type'], 'drawerBox', 'parts[0].parts[0].type: Unknown part type'],
		];
		foreach ($bad as $case => [$keys, $value, $message]) {
			$definition = $cabinet;
			$target = &$definition;
			foreach ($keys as $key) {
				$target = &$target[$key];
			}
			$target = $value;
			unset($target);
			$response = $this->create($user, ['definition' => $definition]);
			self::assertSame(422, $response->getStatusCode(), $case);
			self::assertStringStartsWith($message, self::stringField($this->jsonBody($response)['message']), $case);
		}
	}

	public function testAppliancesSlidingDoorsAndBoardOptions(): void
	{
		$wardrobe = [
			'format' => 1,
			'size' => ['width' => 1200, 'depth' => 600, 'height' => 2400],
			'materialSlots' => [['key' => 'carcass', 'default' => 'kitchen-oak'], ['key' => 'profile', 'default' => 'aluminium-brushed']],
			'parts' => [
				['type' => 'board', 'name' => 'Plinth', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 'W', 'h' => 60, 'd' => 'D', 'material' => 'carcass', 'shade' => 0.3],
				['type' => 'board', 'name' => 'Seat', 'x' => 0, 'y' => 400, 'z' => 0, 'w' => 'W', 'h' => 20, 'd' => 'D', 'backWidth' => 1000, 'taper' => 'left', 'tilt' => 5, 'tiltY' => 400],
				['type' => 'slidingDoor', 'repeat' => ['count' => 2], 'x' => 'i * W / 2', 'y' => 60, 'z' => 'D - 20 - i * 25', 'w' => 'W / 2', 'h' => 'H - 60', 'd' => 20, 'material' => 'carcass', 'profile' => 'profile', 'mirror' => ['expr' => 'i == 1']],
				['type' => 'appliance', 'kind' => 'microwave', 'x' => 0, 'y' => 900, 'z' => 'D - 20', 'w' => 'W', 'h' => 400, 'd' => 20],
			],
		];
		$user = Fixture::createUser();
		$created = $this->create($user, ['definition' => $wardrobe]);
		self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

		$bad = [
			'a profile that is no slot' => [['parts', 2, 'profile'], 'frame', 'parts[2].profile: Must be the key of one of the materialSlots.'],
			'a profile expression naming no slot' => [
				['parts', 2, 'profile'],
				['expr' => "i == 0 ? 'profile' : 'frame'"],
				'parts[2].profile: Must be the key of one of the materialSlots.',
			],
			'shade above 1' => [['parts', 0, 'shade'], 1.5, 'parts[0].shade: Must be a number from 0 to 1.'],
			'tilt beyond 90°' => [['parts', 1, 'tilt'], 120, 'parts[1].tilt: Must be degrees from -90 to 90.'],
			'an appliance not an oven or microwave' => [['parts', 3, 'kind'], 'fridge', 'parts[3].kind: Must be one of "oven", "microwave".'],
			'mirror not a boolean once evaluated' => [['parts', 2, 'mirror'], ['expr' => 'i'], 'parts[2].mirror: Must be true or false.'],
		];
		foreach ($bad as $case => [$keys, $value, $message]) {
			$definition = $wardrobe;
			$target = &$definition;
			foreach ($keys as $key) {
				$target = &$target[$key];
			}
			$target = $value;
			unset($target);
			$response = $this->create($user, ['definition' => $definition]);
			self::assertSame(422, $response->getStatusCode(), $case);
			self::assertSame($message, self::stringField($this->jsonBody($response)['message']), $case);
		}
	}

	public function testNameIsRequired(): void
	{
		self::assertSame(422, $this->create(Fixture::createUser(), ['name' => '  '])->getStatusCode());
	}

	/** @param array<string, mixed> $fields */
	private function create(User $user, array $fields = []): ResponseInterface
	{
		return $this->request('POST', Routes::FurnitureDefinitions->value, self::input($fields), $user);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private static function input(array $fields = []): array
	{
		return $fields + ['name' => 'Open shelf', 'definition' => self::Definition];
	}

	/** @return list<string> */
	private function listNames(User $user, int|string|null $projectId = null): array
	{
		$path = Routes::FurnitureDefinitions->value . ($projectId === null ? '' : '?projectId=' . $projectId);

		return array_map(
			static fn (array $definition): string => self::stringField($definition['name']),
			$this->jsonList($this->request('GET', $path, authenticatedAs: $user)),
		);
	}

	/** @param array<string, mixed> $definition */
	private static function id(array $definition): int
	{
		self::assertIsInt($definition['id']);

		return $definition['id'];
	}

	private static function definitionPath(int $definitionId): string
	{
		return str_replace('{definitionId:number}', (string) $definitionId, Routes::FurnitureDefinition->value);
	}
}
