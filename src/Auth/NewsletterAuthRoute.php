<?php

declare(strict_types=1);

namespace Baraja\Newsletter;


use Baraja\Url\Url;
use Latte\Engine;
use Nette\Http\Request;
use Tracy\Debugger;
use Tracy\ILogger;

final class NewsletterAuthRoute
{
	public const ROUTE_PATH = 'newsletter-verification';

	private static ?string $baseUri = null;


	public function __construct(
		private string $uri,
		Request $request,
		private NewsletterManagerAccessor $newsletterManager,
	) {
		if (preg_match('/^' . $uri . '\/([^\/]+)$/', $request->getUrl()->getPathInfo(), $parser)) {
			$this->actionDefault(trim($parser[1]));
		}
	}


	public static function link(string $hash): string
	{
		if (self::$baseUri === null) {
			throw new \RuntimeException('Base URI does not exist. Did you define Newsletter extension?');
		}

		return rtrim(Url::get()->getBaseUrl(), '/') . '/' . self::$baseUri . '/' . $hash;
	}


	private function actionDefault(string $hash): void
	{
		$error = null;
		$ok = true;

		try {
			$this->newsletterManager->get()->authByHash($hash);
		} catch (\Throwable $e) {
			$ok = false;
			if (Debugger::isEnabled()) {
				$error = $e->getMessage();
			} else {
				Debugger::log($e, ILogger::CRITICAL);
			}
		}

		(new Engine)->render(
			__DIR__ . '/layout.latte',
			[
				'ok' => $ok,
				'errorMessage' => $error,
				'homepageUrl' => Url::get()->getBaseUrl(),
			]
		);
		die;
	}
}
