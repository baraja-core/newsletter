<?php

declare(strict_types=1);

namespace Baraja\Newsletter;


interface NewsletterManagerAccessor
{
	public function get(): NewsletterManager;
}
