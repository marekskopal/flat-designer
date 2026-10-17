<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Realtime;

use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Project\ProjectProvider;
use SensitiveParameter;

/**
 * The HttpOnly cookie the Mercure hub reads to authorize a subscription: a subscriber JWT granting the
 * topics of every project the user can access right now (admins: every topic). The app requests a fresh
 * one whenever it starts listening, so projects created since are covered.
 */
final readonly class MercureCookieIssuer
{
	public const string CookieName = 'mercureAuthorization';
	public const string CookiePath = '/.well-known/mercure';
	private const int TtlSeconds = 3600;

	public function __construct(
		private ProjectProvider $projectProvider,
		#[SensitiveParameter] private string $subscriberKey,
		private string $audience,
	) {
	}

	/** @return list<string> */
	public function topics(User $user): array
	{
		if ($user->isAdmin()) {
			return ['*'];
		}

		return array_map(
			fn (Project $project): string => ProjectUpdatePublisher::topic($project->id),
			$this->projectProvider->listAccessible($user),
		);
	}

	/** `Set-Cookie` header value. */
	public function issue(User $user, bool $secure): string
	{
		$jwt = MercureAccessToken::encode(
			$this->subscriberKey,
			$this->audience,
			(string) $user->id,
			MercureAccessToken::ActionSubscribe,
			$this->topics($user),
			self::TtlSeconds,
		);

		return implode('; ', array_filter([
			self::CookieName . '=' . $jwt,
			'Path=' . self::CookiePath,
			'HttpOnly',
			'SameSite=Strict',
			$secure ? 'Secure' : null,
			'Max-Age=' . self::TtlSeconds,
		]));
	}
}
