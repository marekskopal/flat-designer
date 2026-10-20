<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\ProjectFileController;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Editor\CustomItemValidator;
use FlatDesigner\Service\Furniture\ImageParameters;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** Furniture showing a project image through an `image` parameter: the item's file, the scene's list, share guests. */
#[CoversClass(CustomItemValidator::class)]
#[CoversClass(ImageParameters::class)]
#[CoversClass(ProjectFileController::class)]
final class PictureTest extends IntegrationTestCase
{
	/** Smallest valid PNG (1 × 1 transparent pixel). */
	private const string Png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\0\x01\0\0\x05\0\x01\x0d\x0a\x2d\xb4\0\0\0\0IEND\xaeB`\x82";

	private const array Poster = [
		'format' => 1,
		'size' => ['width' => 700, 'depth' => 30, 'height' => 1000],
		'parameters' => [['key' => 'poster', 'label' => 'Picture', 'type' => 'image']],
		'materialSlots' => [['key' => 'frame', 'default' => 'matt-black']],
		'parts' => [
			['type' => 'board', 'name' => 'Frame', 'material' => 'frame', 'x' => 0, 'y' => 0, 'z' => 0, 'w' => 'W', 'h' => 'H', 'd' => 20],
			[
				'type' => 'picture',
				'name' => 'Print',
				'x' => 30,
				'y' => 30,
				'z' => 20,
				'w' => 'W - 60',
				'h' => 'H - 60',
				'd' => 2,
				'image' => ['expr' => 'poster'],
			],
		],
	];

	public function testAnItemShowsAnImageOfItsProject(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$definitionId = $this->definition($user, $project);
		$image = $this->file($user, $project, 'poster.png', self::Png);
		$text = $this->file($user, $project, 'notes.txt', 'not a picture');
		$other = $this->file($user, Fixture::createProject($user, 'Other'), 'elsewhere.png', self::Png);
		$items = self::itemsPath($project, $floor->id);
		$poster = static fn (?int $fileId): array => [
			'kind' => 'Custom',
			'definitionId' => $definitionId,
			'x' => 1000,
			'y' => 500,
			'params' => ['values' => ['poster' => $fileId]],
		];

		self::assertSame(201, $this->request('POST', $items, $poster(null), $user)->getStatusCode());
		self::assertSame(201, $this->request('POST', $items, $poster($image), $user)->getStatusCode());
		// Not an image, or another project's.
		self::assertSame(422, $this->request('POST', $items, $poster($text), $user)->getStatusCode());
		self::assertSame(422, $this->request('POST', $items, $poster($other), $user)->getStatusCode());

		$scene = $this->scene($user, $project, $floor->id);
		self::assertSame([$image], $scene['pictures']);
		$framed = $this->jsonBody($this->request('POST', $items, $poster($image), $user));
		self::assertIsArray($framed['element']);
		self::assertIsInt($framed['element']['id']);
		self::assertIsInt($framed['revision']);

		// Deleting the file tells open viewers (the picture goes plain), and the item is not stuck: it can be resized,
		// only a newly chosen file has to exist.
		self::assertSame(200, $this->request('DELETE', self::filePath($project, $image), authenticatedAs: $user)->getStatusCode());
		$scene = $this->scene($user, $project, $floor->id);
		self::assertSame([], $scene['pictures']);
		self::assertIsInt($scene['revision']);
		self::assertGreaterThan($framed['revision'], $scene['revision']);
		$itemPath = $items . '/' . $framed['element']['id'];
		self::assertSame(200, $this->request('PATCH', $itemPath, ['width' => 600], $user)->getStatusCode());
		self::assertSame(422, $this->request('PATCH', $itemPath, ['params' => ['values' => ['poster' => $text]]], $user)->getStatusCode());
		self::assertSame(200, $this->request('PATCH', $itemPath, ['params' => ['values' => ['poster' => null]]], $user)->getStatusCode());
	}

	public function testShareGuestsLoadOnlyThePicturesOfTheirScene(): void
	{
		$user = Fixture::createUser();
		$project = Fixture::createProject($user);
		$floor = Fixture::firstFloor($project);
		$definitionId = $this->definition($user, $project);
		$shown = $this->file($user, $project, 'poster.png', self::Png);
		$plan = $this->file($user, $project, 'plan.png', self::Png);
		$this->request('POST', self::itemsPath($project, $floor->id), [
			'kind' => 'Custom',
			'definitionId' => $definitionId,
			'x' => 1000,
			'y' => 500,
			'params' => ['values' => ['poster' => $shown]],
		], $user);

		$link = $this->jsonBody($this->request(
			'POST',
			str_replace('{projectId:number}', (string) $project->id, Routes::ProjectShareLinks->value),
			[
				'floorId' => $floor->id,
				'name' => 'Look',
				'settings' => ['views' => ['overview'], 'initialView' => 'overview', 'ceilings' => false, 'labels' => true, 'sectionHeight' => null, 'dimensions' => false],
			],
			$user,
		));
		self::assertIsString($link['token']);
		$path = static fn (int $fileId): string => str_replace(
			['{token:[a-f0-9]+}', '{fileId:number}'],
			[$link['token'], (string) $fileId],
			Routes::SharePictureContent->value,
		);

		// Without sign-in: the poster loads, the plan of the same project does not.
		$picture = $this->request('GET', $path($shown));
		self::assertSame(200, $picture->getStatusCode());
		self::assertSame(self::Png, (string) $picture->getBody());
		self::assertSame(404, $this->request('GET', $path($plan))->getStatusCode());
	}

	private function definition(User $user, Project $project): int
	{
		$response = $this->request('POST', Routes::FurnitureDefinitions->value, [
			'name' => 'Framed poster',
			'scope' => 'project',
			'projectId' => $project->id,
			'definition' => self::Poster,
		], $user);
		self::assertSame(201, $response->getStatusCode());
		$id = $this->jsonBody($response)['id'];
		self::assertIsInt($id);

		return $id;
	}

	private function file(User $user, Project $project, string $name, string $content): int
	{
		$response = $this->upload(
			str_replace('{projectId:number}', (string) $project->id, Routes::ProjectFiles->value),
			$name,
			$content,
			$user,
		);
		self::assertSame(201, $response->getStatusCode());
		$id = $this->jsonBody($response)['id'];
		self::assertIsInt($id);

		return $id;
	}

	/** @return array<string, mixed> */
	private function scene(User $user, Project $project, int $floorId): array
	{
		return $this->jsonBody($this->request(
			'GET',
			str_replace(
				['{projectId:number}', '{floorId:number}'],
				[(string) $project->id, (string) $floorId],
				Routes::ProjectFloorScene->value,
			),
			authenticatedAs: $user,
		));
	}

	private static function filePath(Project $project, int $fileId): string
	{
		return str_replace(
			['{projectId:number}', '{fileId:number}'],
			[(string) $project->id, (string) $fileId],
			Routes::ProjectFile->value,
		);
	}

	private static function itemsPath(Project $project, int $floorId): string
	{
		return str_replace(
			['{projectId:number}', '{floorId:number}', '{elementType:items|walls|openings|rooms}'],
			[(string) $project->id, (string) $floorId, 'items'],
			Routes::ProjectFloorElements->value,
		);
	}
}
