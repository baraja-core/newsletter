<?php

declare(strict_types=1);

namespace Baraja\Newsletter;


use Baraja\Plugin\BasePlugin;

final class NewsletterPlugin extends BasePlugin
{
	public function getName(): string
	{
		return 'Newsletter';
	}


	public function getIcon(): ?string
	{
		return 'fa fa-envelope';
	}
}
