<?php

declare(strict_types=1);

namespace Baraja\Newsletter;


use Baraja\Doctrine\EntityManager;
use Baraja\DynamicConfiguration\Configuration;
use Baraja\Emailer\EmailerAccessor;
use Baraja\Emailer\EmailerException;
use Baraja\Newsletter\Email\NewsletterVerificationEmail;
use Baraja\Newsletter\Entity\Newsletter;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Application\UI\InvalidLinkException;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\DateTime;
use Tracy\Debugger;
use Tracy\ILogger;

final class NewsletterManager
{
	public const
		AUTO_REMOVE_AUTHORIZED_KEY = 'auto-remove--authorized',
		AUTO_REMOVE_UNAUTHORIZED_KEY = 'auto-remove--un-authorized',
		DEFAULT_REMOVE = 'should-remove-records';

	private Cache $cache;


	public function __construct(
		private string $authUri,
		private EntityManager $entityManager,
		private EmailerAccessor $emailer,
		private Configuration $configuration,
		Storage $storage,
	) {
		$this->cache = new Cache($storage, 'newsletter');
	}


	/**
	 * @return array<int, Newsletter>
	 */
	public function getList(int $limit = 128, int $offset = 0): array
	{
		return $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->select('newsletter')
			->orderBy('newsletter.email', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset)
			->getQuery()
			->getResult();
	}


	/**
	 * @return array<string, string>
	 */
	public function getSourceTypes(): array
	{
		/** @var array<int, array{source: string|null}> $results */
		$results = $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->select('DISTINCT newsletter.source')
			->getQuery()
			->getResult();

		$return = [];
		foreach ($results as $result) {
			$source = (string) $result['source'];
			$key = $source !== '' ? $source : '--null--';
			$value = $source !== '' ? $source : '(unknown)';
			$return[$key] = $value;
		}

		return $return;
	}


	/**
	 * @param class-string|null $mailClass
	 * @throws EmailerException|InvalidLinkException
	 */
	public function register(string $email, ?string $source = null, ?string $mailClass = null): void
	{
		try {
			$this->getNewsletterByEmail($email);
		} catch (NoResultException | NonUniqueResultException) {
			$newsletter = new Newsletter($email, $source);
			$this->entityManager->persist($newsletter);
			$this->sendMail($newsletter, [], $mailClass);
			$this->entityManager->flush();
		}

		if ($this->isAutoRemoveActive() === true) {
			$this->autoRemove();
		}
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getNewsletterByEmail(string $email): Newsletter
	{
		return $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->select('newsletter')
			->where('newsletter.email = :email')
			->setParameter('email', $email)
			->setMaxResults(2)
			->getQuery()
			->getSingleResult();
	}


	/**
	 * @param array<string, mixed> $config
	 * @param class-string|null $serviceClass implements \Baraja\Emailer\Message\Email
	 * @throws EmailerException
	 */
	public function sendMail(Newsletter $newsletter, array $config = [], ?string $serviceClass = null): void
	{
		$defaultConfig = [
			'to' => $newsletter->getEmail(),
			'subject' => 'Potvrzení odběru novinek',
			'link' => sprintf('%s/%s/%s', rtrim(Url::get()->getBaseUrl(), '/'), $this->authUri, $newsletter->getHash()),
		];

		$this->entityManager->flush();
		$this->emailer->get()->getEmailServiceByType(
			$serviceClass ?? NewsletterVerificationEmail::class,
			array_merge($defaultConfig, $config),
			false,
		)->send();
	}


	/**
	 * @param array<int, string> $emails
	 */
	public function bulkRegister(array $emails, ?string $source = null): void
	{
		foreach ($emails as $email) {
			try {
				$newsletter = new Newsletter($email, $source);
				$newsletter->authorize();
				$this->entityManager->persist($newsletter);
				$this->entityManager->flush();
			} catch (\Throwable) {
				// Silence is golden.
			}
		}
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function authByHash(string $hash): void
	{
		$newsletter = $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->select('newsletter')
			->where('newsletter.hash = :hash')
			->setParameter('hash', $hash)
			->getQuery()
			->getSingleResult();
		assert($newsletter instanceof Newsletter);

		try {
			if ($newsletter->isAuthorizedByUser() === false) {
				$newsletter->authorize();
				$this->entityManager->flush();
			}
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::CRITICAL);
		}
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function unregisterById(string $id): void
	{
		$newsletter = $this->getNewsletterById($id);
		$this->entityManager->remove($newsletter);
		$this->entityManager->flush();
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function getNewsletterById(string $id): Newsletter
	{
		return $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->where('newsletter.id = :id')
			->setParameter('id', $id)
			->getQuery()
			->getSingleResult();
	}


	/**
	 * @throws NoResultException|NonUniqueResultException
	 */
	public function cancelById(string $id, string $message = null): void
	{
		$newsletter = $this->getNewsletterById($id);
		$newsletter->cancel($message);
		$this->entityManager->flush();
	}


	/**
	 * @return array<string, bool> (mail => isKnown?)
	 */
	public function loadContacts(string $hash): array
	{
		$data = $this->cache->load($hash);
		if (\is_array($data)) {
			/** @var array<int, Newsletter> $databaseContacts */
			$databaseContacts = $this->entityManager->getRepository(Newsletter::class)
				->createQueryBuilder('newsletter')
				->where('newsletter.email IN (:ids)')
				->setParameter('ids', $data)
				->getQuery()
				->getResult();

			$known = [];
			foreach ($databaseContacts as $databaseContact) {
				$known[$databaseContact->getEmail()] = true;
			}

			$return = [];
			foreach ($data as $contact) {
				$return[(string) $contact] = isset($known[$contact]);
			}

			return $return;
		}

		return [];
	}


	public function loadContactsExpireTime(string $hash): ?string
	{
		$data = $this->cache->load($hash . '_time');

		return is_string($data) ? $data : null;
	}


	public function unregisterByEmail(string $email): void
	{
		try {
			$this->entityManager->remove($this->getNewsletterByEmail($email))->flush();
		} catch (NoResultException | NonUniqueResultException) {
			// Silence is golden.
		}
	}


	public function getAutoRemoveAuthorized(): string
	{
		$haystack = $this->configuration->get(self::AUTO_REMOVE_AUTHORIZED_KEY, 'newsletter');
		if ($haystack === null) {
			$haystack = '99 years';
			$this->setAutoRemoveAuthorized($haystack);
		}

		return $haystack;
	}


	public function getAutoRemoveUnAuthorized(): string
	{
		$haystack = $this->configuration->get(self::AUTO_REMOVE_UNAUTHORIZED_KEY, 'newsletter');
		if ($haystack === null) {
			$haystack = '14 days';
			$this->setAutoRemoveUnAuthorized($haystack);
		}

		return $haystack;
	}


	public function isAutoRemoveActive(): bool
	{
		$haystack = $this->configuration->get(self::DEFAULT_REMOVE, 'newsletter');
		if ($haystack === null) {
			$haystack = 'true';
			$this->setAutoRemoveActive();
		}

		return $haystack === 'true';
	}


	public function setAutoRemoveActive(bool $status = true): void
	{
		$this->configuration->save(self::DEFAULT_REMOVE, $status ? 'true' : 'false', 'newsletter');
	}


	public function setAutoRemoveAuthorized(string $haystack): void
	{
		$haystack = trim($haystack);
		if (strtotime('now + ' . $haystack) === false) {
			throw new \InvalidArgumentException(
				sprintf('Date "%s" is not in valid format. Did you mean "14 days" for instance?', $haystack),
			);
		}

		$this->configuration->save(self::AUTO_REMOVE_AUTHORIZED_KEY, $haystack, 'newsletter');
	}


	public function setAutoRemoveUnAuthorized(string $haystack): void
	{
		$haystack = trim($haystack);
		if (strtotime('now + ' . $haystack) === false) {
			throw new \InvalidArgumentException(
				sprintf('Date "%s" is not in valid format. Did you mean "14 days" for instance?', $haystack),
			);
		}

		$this->configuration->save(self::AUTO_REMOVE_UNAUTHORIZED_KEY, $haystack, 'newsletter');
	}


	public function autoRemove(): void
	{
		/** @var Newsletter[] $newsletters */
		$newsletters = $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->where('newsletter.authorizedByUser = TRUE AND newsletter.authorizedDate <= :authorizedDate')
			->orWhere('newsletter.authorizedByUser = FALSE AND newsletter.insertedDate <= :unauthorizedDate')
			->setParameter('authorizedDate', DateTime::from('now')->modify('- ' . $this->getAutoRemoveAuthorized()))
			->setParameter('unauthorizedDate', DateTime::from('now')->modify('- ' . $this->getAutoRemoveUnAuthorized()))
			->setMaxResults(1_000)
			->getQuery()
			->getResult();

		foreach ($newsletters as $newsletter) {
			$this->entityManager->remove($newsletter);
		}

		$this->entityManager->flush();
	}
}
