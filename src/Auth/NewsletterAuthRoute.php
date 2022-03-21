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
	public function __construct(
		private string $authUri,
		Request $request,
		private NewsletterManagerAccessor $newsletterManager,
	) {
		if (preg_match('/^' . $authUri . '\/([^\/]+)$/', $request->getUrl()->getPathInfo(), $parser)) {
			$this->actionDefault(trim($parser[1]));
		}
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
			],
		);
		die;
	}
}
