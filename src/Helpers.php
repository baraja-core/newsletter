<?php

declare(strict_types=1);

namespace Baraja\Newsletter;


use Baraja\Url\Url;
use Nette\Http\Request;
use Nette\Utils\Validators;

final class Helpers
{
	/** @throws \Error */
	public function __construct()
	{
		throw new \Error('Class ' . static::class . ' is static and cannot be instantiated.');
	}


	/**
	 * Return current API path by current HTTP URL.
	 * In case of CLI return empty string.
	 */
	public static function processPath(Request $httpRequest): string
	{
		return trim(
			str_replace(
				rtrim($httpRequest->getUrl()->withoutUserInfo()->getBaseUrl(), '/'),
				'',
				Url::get()->getCurrentUrl(),
			),
			'/',
		);
	}


	/**
	 * This method parses a list of e-mail addresses from any text and returns them as fields.
	 * In the text, e-mail addresses can be separated in any way: comma, semicolon, new line, only occupied in written text, ...
	 * This function was created so that in case of entering more e-mail by a customer in a text input or textarea,
	 * we understand its entry, even if they do not follow the described format.
	 *
	 * @return array<int, string>
	 * */
	public static function getEmailAddresses(string $haystack): array
	{
		if (trim($haystack) === '') {
			return [];
		}

		preg_match_all(
			"/(?:[a-z0-9!#$%&'*+=?^_`{|}~-]+(?:\\.[a-z0-9!#$%&'*+=?^_`{|}~-]+)*|\"(?:"
			. "[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*\")"
			. '@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?'
			. '|\\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?'
			. "|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]"
			. "|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\\])/i",
			$haystack,
			$matches,
		);

		if (isset($matches[0]) && \is_array($matches[0])) {
			$return = [];
			foreach (array_unique($matches[0]) as $email) {
				if (Validators::isEmail($email = trim($email, '\'"')) === true) {
					$return[] = $email;
				}
			}

			return $return;
		}

		return [];
	}
}
