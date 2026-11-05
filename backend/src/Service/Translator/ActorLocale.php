<?php

declare(strict_types=1);

namespace Izbik\Service\Translator;

use Izbik\Model\Entity\Enum\ActorTypeEnum;
use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Model\Entity\User;
use Izbik\Service\Actor\ActorContext;

/** The language of what the current request writes for `$user`: theirs in the app, English for an agent over MCP. */
final readonly class ActorLocale
{
	public function __construct(private ActorContext $actorContext)
	{
	}

	public function of(User $user): LocaleEnum
	{
		return $this->actorContext->getActorType() === ActorTypeEnum::Human ? $user->locale : LocaleEnum::En;
	}
}
