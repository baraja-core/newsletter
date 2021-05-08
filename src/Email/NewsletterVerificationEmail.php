<?php

declare(strict_types=1);

namespace Baraja\Newsletter\Email;


use Baraja\Emailer\Email\BaseEmail;

final class NewsletterVerificationEmail extends BaseEmail
{
	public function getTemplate(string $locale): ?string
	{
		return $locale === 'cs'
			? __DIR__ . '/newsletterVerificationCs.mjml'
			: null;
	}
}
