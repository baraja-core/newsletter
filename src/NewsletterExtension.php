<?php

declare(strict_types=1);

namespace Baraja\Newsletter;


use Baraja\Doctrine\ORM\DI\OrmAnnotationsExtension;
use Baraja\Newsletter\Email\NewsletterVerificationEmail;
use Baraja\Plugin\Component\VueComponent;
use Baraja\Plugin\PluginManager;
use Nette\Application\Application;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\PhpGenerator\ClassType;

final class NewsletterExtension extends CompilerExtension
{
	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();
		OrmAnnotationsExtension::addAnnotationPathToManager($builder, 'Baraja\Newsletter\Entity', __DIR__ . '/Entity');

		$builder->addDefinition($this->prefix('emailNewsletterVerification'))
			->setFactory(NewsletterVerificationEmail::class)
			->setAutowired(NewsletterVerificationEmail::class);

		if (PHP_SAPI !== 'cli') {
			$builder->addDefinition($this->prefix('authRoute'))
				->setFactory(NewsletterAuthRoute::class)
				->setArgument('uri', 'newsletter-verification');
		}

		$builder->addAccessorDefinition($this->prefix('managerAccessor'))
			->setImplement(NewsletterManagerAccessor::class);

		$builder->addDefinition($this->prefix('manager'))
			->setFactory(NewsletterManager::class);

		/** @var ServiceDefinition $pluginManager */
		$pluginManager = $this->getContainerBuilder()->getDefinitionByType(PluginManager::class);
		$pluginManager->addSetup('?->addComponent(?)', ['@self', [
			'key' => 'newsletterDefault',
			'name' => 'newsletter-default',
			'implements' => NewsletterPlugin::class,
			'componentClass' => VueComponent::class,
			'view' => 'default',
			'source' => __DIR__ . '/../template/default.js',
			'position' => 100,
			'tab' => 'Dashboard',
			'params' => [],
		]]);
	}


	public function afterCompile(ClassType $class): void
	{
		if (PHP_SAPI === 'cli') {
			return;
		}

		$class->getMethod('initialize')->addBody(
			'if (strncmp(' . Helpers::class . '::processPath($this->getService(\'http.request\')), ?, ?) === 0) {'
			. "\n\t" . '$this->getByType(' . Application::class . '::class)->onStartup[] = function(' . Application::class . ' $a) {'
			. "\n\t\t" . '$this->getByType(?);'
			. "\n\t" . '};'
			. "\n" . '}',
			[NewsletterAuthRoute::ROUTE_PATH, \strlen(NewsletterAuthRoute::ROUTE_PATH), NewsletterAuthRoute::class],
		);
	}
}
