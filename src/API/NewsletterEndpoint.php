<?php

declare(strict_types=1);

namespace Baraja\Newsletter;


use Baraja\Doctrine\EntityManager;
use Baraja\Newsletter\Entity\Newsletter;
use Baraja\StructuredApi\BaseEndpoint;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Nette\Http\Response;
use Nette\Utils\DateTime;
use Nette\Utils\Paginator;
use Tracy\Debugger;
use Tracy\ILogger;

final class NewsletterEndpoint extends BaseEndpoint
{
	public function __construct(
		private EntityManager $entityManager,
		private NewsletterManagerAccessor $newsletterManager,
	) {
	}


	/**
	 * @param string|null $email find users by this part of string
	 * @param string|null $source filter by property "sourceTypes"
	 * @param string|null $authorized states: [authorized, disabled, canceled]
	 */
	public function actionDefault(
		int $page = 1,
		int $limit = 32,
		?string $email = null,
		?string $source = null,
		?string $authorized = null,
	): void {
		$selection = $this->entityManager->getRepository(Newsletter::class)->createQueryBuilder('newsletter');

		if ($email !== null) {
			$selection->andWhere('newsletter.email LIKE :email')->setParameter('email', '%' . $email . '%');
		}

		if ($source !== null) {
			if ($source === '--null--') {
				$selection->andWhere('newsletter.source IS NULL');
			} else {
				$selection->andWhere('newsletter.source = :source')->setParameter('source', $source);
			}
		}

		if ($authorized === 'canceled') {
			$selection->andWhere('newsletter.canceled = TRUE');
		} elseif ($authorized === 'authorized') {
			$selection->andWhere('newsletter.authorizedByUser = TRUE');
		} elseif ($authorized === 'disabled') {
			$selection->andWhere('newsletter.authorizedByUser = FALSE');
		}

		try {
			$count = (int) (clone $selection)->select('COUNT(newsletter.id)')->getQuery()->getSingleScalarResult();
		} catch (NonUniqueResultException | NoResultException) {
			$count = 0;
		}

		/** @var array<int, array<string, mixed>> $list */
		$list = $selection
			->select('PARTIAL newsletter.{id, email, source, canceled, authorizedByUser, authorizedDate, insertedDate}')
			->setMaxResults($limit)
			->setFirstResult(($page - 1) * $limit)
			->orderBy('newsletter.authorizedDate', 'DESC')
			->getQuery()
			->getArrayResult();

		$return = [];
		foreach ($list as $item) {
			$return[] = [
				'id' => $item['id'],
				'email' => $item['email'],
				'source' => $item['source'],
				'authorized' => (static function (bool $canceled, bool $authorizedByUser) {
					if ($canceled === true) {
						return 'canceled';
					}

					return $authorizedByUser ? 'authorized' : 'disabled';
				})(
					$item['canceled'],
					$item['authorizedByUser'],
				),
				'isActive' => $item['authorizedByUser'] === true && $item['canceled'] === false,
				'authorizedDate' => $item['authorizedDate'],
				'insertedDate' => $item['insertedDate'],
			];
		}

		$this->sendJson(
			[
				'sourceTypes' => $this->formatBootstrapSelectArray($this->newsletterManager->get()->getSourceTypes()),
				'authorizedByUser' => $this->formatBootstrapSelectArray([
					'authorized' => 'authorized',
					'disabled' => 'disabled',
					'canceled' => 'canceled',
				]),
				'list' => $return,
				'paginator' => (new Paginator)
					->setItemCount($count)
					->setItemsPerPage($limit)
					->setPage($page),
			],
		);
	}


	public function postAddEmail(string $email, ?string $source = null): void
	{
		try {
			$this->newsletterManager->get()->register($email, $source);
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::WARNING);
			$this->sendError('Can not add contact "' . $email . '": ' . $e->getMessage());
		}
		$this->sendOk();
	}


	/**
	 * Analyse list of given e-mails and return result table.
	 * Return list in format (email => isKnown?).
	 */
	public function postAnalyseEmails(string $haystack): void
	{
		$emails = array_unique(Helpers::getEmailAddresses($haystack));

		/** @var string[][] $databaseContacts */
		$databaseContacts = $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->select('PARTIAL newsletter.{id, email}')
			->where('newsletter.email IN (:emails)')
			->setParameter('emails', $emails)
			->getQuery()
			->getArrayResult();

		$known = [];
		foreach ($databaseContacts as $databaseContact) {
			$known[$databaseContact['email']] = true;
		}

		$return = [];
		foreach ($emails as $email) {
			$return[$email] = isset($known[$email]);
		}

		$this->sendJson(
			[
				'emails' => $return,
			],
		);
	}


	/**
	 * Add contacts to database. If some contact already exist it will be skipped.
	 *
	 * @param array<int, string> $emails
	 */
	public function postImport(array $emails, ?string $source = null): void
	{
		$emails = array_unique($emails);
		/** @var array<int, array{id: int, email: string}> $databaseContacts */
		$databaseContacts = $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->select('PARTIAL newsletter.{id, email}')
			->where('newsletter.email IN (:emails)')
			->setParameter('emails', $emails)
			->getQuery()
			->getArrayResult();

		$used = [];
		foreach ($databaseContacts as $databaseContact) {
			$used[$databaseContact['email']] = true;
		}

		$counter = 0;
		foreach ($emails as $email) {
			if (isset($used[$email]) === false) {
				$this->entityManager->persist(new Newsletter($email, $source));
				if (($counter++) >= 100) {
					$this->entityManager->flush();
					$this->entityManager->clear();
					$counter = 0;
				}
			}
		}

		$this->entityManager->flush();
		$this->sendOk();
	}


	public function actionSendMail(string $id): void
	{
		try {
			$this->newsletterManager->get()->sendMail(
				$newsletter = $this->newsletterManager->get()->getNewsletterById($id),
			);
			$this->flashMessage(sprintf('E-mail was sent to "%s".', $newsletter->getEmail()), 'success');
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::WARNING);
			$this->sendError('Can not sent email.');
		}
		$this->sendOk();
	}


	public function actionAuthorize(string $id, bool $is = true): void
	{
		try {
			$newsletter = $this->newsletterManager->get()->getNewsletterById($id);
			if ($is === true) {
				$newsletter->authorize();
			} else {
				$newsletter->unAuthorize();
			}
			$this->entityManager->flush();
			$this->flashMessage('Contact was authorized.', 'success');
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::WARNING);
			$this->sendError('Can not authorize this contact.');
		}

		$this->sendOk();
	}


	public function actionDelete(string $id): void
	{
		try {
			$this->newsletterManager->get()->unregisterById($id);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('Contact does not exist.');
		}
		$this->sendOk();
	}


	public function actionCancel(string $id, ?string $message = null): void
	{
		try {
			$this->newsletterManager->get()->cancelById($id, $message);
		} catch (NoResultException | NonUniqueResultException $e) {
			$this->sendError('Contact does not exist.');
		}
		$this->sendOk();
	}


	public function actionSettings(): void
	{
		$this->sendJson(
			[
				'autoRemoveAuthorized' => $this->newsletterManager->get()->getAutoRemoveAuthorized(),
				'autoRemoveUnAuthorized' => $this->newsletterManager->get()->getAutoRemoveUnAuthorized(),
				'shouldRemoveRecords' => $this->newsletterManager->get()->isAutoRemoveActive(),
			],
		);
	}


	public function postSaveSettings(
		string $autoRemoveAuthorized,
		string $autoRemoveUnAuthorized,
		bool $autoRemoveActive = true,
	): void {
		try {
			$this->newsletterManager->get()->setAutoRemoveAuthorized($autoRemoveAuthorized);
			$this->newsletterManager->get()->setAutoRemoveUnAuthorized($autoRemoveUnAuthorized);
			$this->newsletterManager->get()->setAutoRemoveActive($autoRemoveActive);
		} catch (\InvalidArgumentException $e) {
			$this->sendError($e->getMessage());
		}
		$this->sendOk();
	}


	/**
	 * Export as simple list of e-mail contacts.
	 */
	public function actionCsvExport(): void
	{
		$list = $this->entityManager->getRepository(Newsletter::class)
			->createQueryBuilder('newsletter')
			->select('PARTIAL newsletter.{id, email, authorizedDate, insertedDate, source, canceled}')
			->orderBy('newsletter.insertedDate', 'DESC')
			->getQuery()
			->getArrayResult();

		$returnValues = [];
		foreach ($list as $value) {
			$returnValues[] = [
				'email' => $value['email'],
				'insertedDate' => DateTime::from($value['insertedDate'])->format('Y-m-d'),
				'authorizedDate' => DateTime::from($value['authorizedDate'])->format('Y-m-d'),
				'source' => $value['source'] ?? '',
				'active' => $value['authorizedDate'] !== null && $value['canceled'] === false,
			];
		}

		$domain = Url::get()->getNetteUrl()->getDomain(3);
		/** @var Response $httpResponse */
		$httpResponse = $this->container->getByType(Response::class);
		$httpResponse->setHeader('Content-type', 'text/csv; charset=utf-8');
		$httpResponse->setHeader(
			'Content-Disposition',
			'attachment; filename=' . $domain . '-newsletter-' . date('Y-m-d') . '.csv',
		);
		$httpResponse->setHeader('Pragma', 'public');
		$httpResponse->setHeader('Expires', '0');
		$httpResponse->setHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
		$httpResponse->setHeader('Content-Transfer-Encoding', 'binary');
		$httpResponse->setHeader('Content-Description', 'File Transfer');

		echo '"E-mail";"Authorized Date";"Inserted Date";"Source";"Active"' . "\n"
			. implode(
				"\n",
				array_map(
					static fn(array $item): string => '"' . $item['email'] . '";"'
						. $item['authorizedDate'] . '";"'
						. $item['insertedDate'] . '";"'
						. $item['source'] . '";"'
						. ($item['active'] ? 'y' : 'n') . '"',
					$returnValues,
				),
			);
		die;
	}
}
