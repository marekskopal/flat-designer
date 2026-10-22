<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\CommentController;
use FlatDesigner\Controller\SharedCommentController;
use FlatDesigner\Dto\CommentDto;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Comment\CommentActor;
use FlatDesigner\Service\Comment\CommentKeeper;
use FlatDesigner\Service\Comment\CommentProvider;
use FlatDesigner\Service\Comment\SharedComments;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\EditorTestCase;
use FlatDesigner\Tests\Support\Fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(CommentController::class)]
#[CoversClass(SharedCommentController::class)]
#[CoversClass(CommentProvider::class)]
#[CoversClass(SharedComments::class)]
#[CoversClass(CommentKeeper::class)]
#[CoversClass(CommentActor::class)]
#[CoversClass(CommentDto::class)]
final class CommentControllerTest extends EditorTestCase
{
	private const array ShareSettings = [
		'views' => ['overview', 'top'],
		'initialView' => 'overview',
		'camera' => null,
		'ceilings' => false,
		'labels' => true,
		'sectionHeight' => null,
	];

	public function testThreadsRepliesResolveAndDelete(): void
	{
		$created = $this->comment(['x' => 1200, 'y' => 900, 'z' => 850, 'body' => '  Move the socket here?  ']);
		self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
		$thread = $this->jsonBody($created);
		self::assertSame(
			[$this->floor->id, null, 1200, 900, 850, 'Move the socket here?', 'User', 'Jane', false, true, true],
			[
				$thread['floorId'],
				$thread['parentId'],
				$thread['x'],
				$thread['y'],
				$thread['z'],
				$thread['body'],
				$thread['authorType'],
				$thread['authorName'],
				$thread['resolved'],
				$thread['canEdit'],
				$thread['canDelete'],
			],
		);
		$threadId = self::id($thread);

		// A reply takes the thread's point; a reply to a reply is refused.
		$reply = $this->jsonBody($this->comment(['parentId' => $threadId, 'body' => 'Yes, at 300 mm.']));
		self::assertSame([$threadId, 1200, 900, 850], [$reply['parentId'], $reply['x'], $reply['y'], $reply['z']]);
		self::assertSame(422, $this->comment(['parentId' => self::id($reply), 'body' => 'Nested'])->getStatusCode());
		$second = self::id($this->jsonBody($this->comment(['x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Second'])));

		self::assertSame([$threadId, self::id($reply), $second], array_column($this->listed(), 'id'));
		// Threads are numbered per floor, replies are not.
		self::assertSame([1, null, 2], array_column($this->listed(), 'number'));

		// Only threads are resolved; open-only listing drops the resolved thread with its reply.
		self::assertSame(422, $this->update(self::id($reply), ['resolved' => true])->getStatusCode());
		$resolved = $this->update($threadId, ['resolved' => true]);
		self::assertTrue($this->jsonBody($resolved)['resolved']);
		self::assertSame([$second], array_column($this->listed('&resolved=0'), 'id'));
		self::assertSame([$threadId, self::id($reply), $second], array_column($this->listed(), 'id'));

		self::assertSame('Edited', $this->jsonBody($this->update($second, ['body' => 'Edited']))['body']);
		self::assertSame(200, $this->request('DELETE', $this->commentPath($threadId), authenticatedAs: $this->user)->getStatusCode());
		self::assertSame([$second], array_column($this->listed(), 'id'));
		// A deleted thread's number is not reused.
		$third = $this->jsonBody($this->comment(['x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Third']));
		self::assertSame(3, $third['number']);

		// Resolving a resolved thread again changes nothing and publishes nothing.
		$published = count(AppHarness::mercureHub()->messages());
		$this->update($second, ['resolved' => true]);
		$this->update($second, ['resolved' => true]);
		self::assertCount($published + 1, AppHarness::mercureHub()->messages());

		$types = array_column(AppHarness::mercureHub()->messages(), 'type');
		self::assertContains('commentsChanged', $types);
	}

	public function testRefusesBadInputAndOtherUsers(): void
	{
		$other = Fixture::firstFloor(Fixture::createProject($this->user, 'Other'));
		self::assertSame(422, $this->comment(['floorId' => $other->id, 'x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Hi'])->getStatusCode());
		self::assertSame(422, $this->comment(['body' => 'No point'])->getStatusCode());
		self::assertSame(422, $this->comment(['x' => 0, 'y' => 0, 'z' => 0, 'body' => '   '])->getStatusCode());
		self::assertSame(
			422,
			$this->comment(['x' => 0, 'y' => 0, 'z' => 0, 'body' => str_repeat('a', CommentProvider::MaxBodyLength + 1)])->getStatusCode(),
		);
		self::assertSame(
			404,
			$this->request('GET', $this->commentsPath(), authenticatedAs: Fixture::createUser())->getStatusCode(),
		);
	}

	public function testOnlyTheAuthorEditsAndAdminsMayDelete(): void
	{
		$admin = Fixture::createUser(name: 'Admin', role: UserRoleEnum::Admin);
		$comment = $this->jsonBody($this->comment(['x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Mine']));
		$id = self::id($comment);

		$asAdmin = $this->jsonList($this->request('GET', $this->commentsPath(), authenticatedAs: $admin))[0];
		self::assertSame([false, true], [$asAdmin['canEdit'], $asAdmin['canDelete']]);
		self::assertSame(403, $this->request('PUT', $this->commentPath($id), ['body' => 'Theirs'], $admin)->getStatusCode());
		self::assertTrue($this->jsonBody($this->request('PUT', $this->commentPath($id), ['resolved' => true], $admin))['resolved']);
		self::assertSame(200, $this->request('DELETE', $this->commentPath($id), authenticatedAs: $admin)->getStatusCode());
	}

	public function testShareLinkGuestsReadAndWriteAsTheLinkAllows(): void
	{
		$this->comment(['x' => 100, 'y' => 200, 'z' => 0, 'body' => 'Open']);
		$resolvedId = self::id($this->jsonBody($this->comment(['x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Done'])));
		$this->update($resolvedId, ['resolved' => true]);

		$hidden = $this->shareLink(null);
		self::assertSame([], $this->jsonList($this->request('GET', self::sharedPath($hidden))));
		self::assertSame(
			403,
			$this->guestComment($hidden, ['guestName' => 'Eve', 'x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Hi'])->getStatusCode(),
		);

		$read = $this->shareLink('read');
		$seen = $this->jsonList($this->request('GET', self::sharedPath($read)));
		self::assertSame(['Open'], array_column($seen, 'body'));
		self::assertSame([false, false], [$seen[0]['canEdit'], $seen[0]['canDelete']]);
		self::assertSame(
			403,
			$this->guestComment($read, ['guestName' => 'Eve', 'x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Hi'])->getStatusCode(),
		);

		$write = $this->shareLink('write');
		self::assertSame(422, $this->guestComment($write, ['x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Anonymous'])->getStatusCode());
		// The floor is the link's, whatever the guest sends.
		$posted = $this->guestComment(
			$write,
			['guestName' => ' Eve ', 'floorId' => 999, 'x' => 500, 'y' => 600, 'z' => 1000, 'body' => 'Bigger window?'],
		);
		self::assertSame(201, $posted->getStatusCode(), (string) $posted->getBody());
		$guest = $this->jsonBody($posted);
		self::assertSame([$this->floor->id, 'Guest', 'Eve'], [$guest['floorId'], $guest['authorType'], $guest['authorName']]);
		self::assertSame(
			422,
			$this->guestComment($write, ['guestName' => 'Eve', 'parentId' => $resolvedId, 'body' => 'Reopen'])->getStatusCode(),
		);

		// The owner sees it, can delete it, but not edit it.
		$listed = $this->listed();
		self::assertSame(['Open', 'Done', 'Bigger window?'], array_column($listed, 'body'));
		self::assertSame([false, true], [$listed[2]['canEdit'], $listed[2]['canDelete']]);

		self::assertSame(404, $this->request('GET', self::sharedPath('0123456789abcdef0123456789abcdef'))->getStatusCode());
	}

	public function testGuestsAreRateLimitedPerLink(): void
	{
		$token = $this->shareLink('write');
		for ($i = 0; $i < SharedComments::GuestCommentsPerHour; $i++) {
			self::assertSame(
				201,
				$this->guestComment($token, ['guestName' => 'Bot', 'x' => $i, 'y' => 0, 'z' => 0, 'body' => 'Spam'])->getStatusCode(),
			);
		}
		self::assertSame(
			429,
			$this->guestComment($token, ['guestName' => 'Bot', 'x' => 0, 'y' => 0, 'z' => 0, 'body' => 'Spam'])->getStatusCode(),
		);
	}

	public function testAVersionRestoreKeepsTheComments(): void
	{
		$thread = self::id($this->jsonBody($this->comment(['x' => 100, 'y' => 200, 'z' => 300, 'body' => 'Before'])));
		$versionId = self::id(
			$this->jsonBody(
				$this->request(
					'POST',
					str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectVersions->value),
					[],
					$this->user,
				),
			),
		);
		$this->comment(['parentId' => $thread, 'body' => 'After the version']);
		$this->update($thread, ['resolved' => true]);
		$token = $this->shareLink('read');

		$restore = str_replace(
			['{projectId:number}', '{versionId:number}'],
			[(string) $this->project->id, (string) $versionId],
			Routes::ProjectVersionRestore->value,
		);
		self::assertSame(200, $this->request('POST', $restore, [], $this->user)->getStatusCode());

		$this->floor = Fixture::firstFloor($this->project);
		$comments = $this->listed();
		self::assertSame(['Before', 'After the version'], array_column($comments, 'body'));
		self::assertSame([1, null], array_column($comments, 'number'));
		self::assertSame([$this->floor->id, $this->floor->id], array_column($comments, 'floorId'));
		self::assertSame($comments[0]['id'], $comments[1]['parentId']);
		self::assertSame([true, false], array_column($comments, 'resolved'));
		self::assertSame([100, 200, 300], [$comments[0]['x'], $comments[0]['y'], $comments[0]['z']]);

		// The link's floor id is from before the restore: guests find the floor by its name.
		$this->update(self::id($comments[0]), ['resolved' => false]);
		self::assertCount(2, $this->jsonList($this->request('GET', self::sharedPath($token))));
	}

	/** @param array<string, mixed> $body */
	private function comment(array $body): ResponseInterface
	{
		return $this->request('POST', $this->commentsPath(), $body + ['floorId' => $this->floor->id], $this->user);
	}

	/** @param array<string, mixed> $body */
	private function update(int $commentId, array $body): ResponseInterface
	{
		return $this->request('PUT', $this->commentPath($commentId), $body, $this->user);
	}

	/** @return list<array<string, mixed>> */
	private function listed(string $query = ''): array
	{
		return $this->jsonList(
			$this->request('GET', $this->commentsPath() . '?floorId=' . $this->floor->id . $query, authenticatedAs: $this->user),
		);
	}

	/** @return string the token of a new link to the floor */
	private function shareLink(?string $comments): string
	{
		$settings = self::ShareSettings + ($comments === null ? [] : ['comments' => $comments]);
		$created = $this->request(
			'POST',
			str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectShareLinks->value),
			['floorId' => $this->floor->id, 'name' => 'Review', 'settings' => $settings],
			$this->user,
		);
		self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

		return self::stringField($this->jsonBody($created)['token']);
	}

	/** @param array<string, mixed> $body */
	private function guestComment(string $token, array $body): ResponseInterface
	{
		return $this->request('POST', self::sharedPath($token), $body);
	}

	private static function sharedPath(string $token): string
	{
		return str_replace('{token:[a-f0-9]+}', $token, Routes::ShareComments->value);
	}

	private function commentsPath(): string
	{
		return str_replace('{projectId:number}', (string) $this->project->id, Routes::ProjectComments->value);
	}

	private function commentPath(int $commentId): string
	{
		return str_replace(
			['{projectId:number}', '{commentId:number}'],
			[(string) $this->project->id, (string) $commentId],
			Routes::ProjectComment->value,
		);
	}
}
