<?php

declare(strict_types=1);

namespace Baraja\Newsletter\Entity;


use Baraja\Doctrine\Identifier\IdentifierUnsigned;
use Baraja\Newsletter\Helpers;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Index;
use Nette\Utils\Random;
use Nette\Utils\Validators;

/**
 * @ORM\Entity()
 * @ORM\Table(
 *    name="core__newsletter",
 *    indexes={
 *       @Index(name="core__newsletter__source", columns={"source"}),
 *       @Index(name="core__newsletter__canceled", columns={"canceled"}),
 *       @Index(name="core__newsletter__authorized_by_user", columns={"authorized_by_user"})
 *    }
 * )
 */
class Newsletter
{
	use IdentifierUnsigned;

	/** @ORM\Column(type="string", length=128, unique=true) */
	private string $email;

	/** @ORM\Column(type="string", length=16, unique=true) */
	private string $hash;

	/** @ORM\Column(type="string", nullable=true) */
	private ?string $ip = null;

	/** @ORM\Column(type="boolean") */
	private bool $authorizedByUser = false;

	/** @ORM\Column(type="boolean") */
	private bool $canceled = false;

	/** @ORM\Column(type="string", nullable=true) */
	private ?string $cancelMessage = null;

	/** @ORM\Column(type="string", length=32, nullable=true) */
	private ?string $source = null;

	/** @ORM\Column(type="datetime", nullable=true) */
	private ?\DateTime $authorizedDate = null;

	/** @ORM\Column(type="datetime", nullable=true) */
	private ?\DateTime $cancelDate = null;

	/** @ORM\Column(type="datetime") */
	private \DateTime $insertedDate;


	public function __construct(string $email, ?string $source = null)
	{
		$email = strtolower($email);
		if (Validators::isEmail($email) === false) {
			throw new \InvalidArgumentException('Email "' . $email . '" is not valid.');
		}
		if (mb_strlen($email, 'UTF-8') > 128) {
			throw new \InvalidArgumentException('Email "' . $email . '" is too long.');
		}

		$this->email = $email;
		$this->ip = Helpers::userIp();
		$this->hash = Random::generate(16);
		$this->source = $source === null ? null : mb_substr($source, 0, 32, 'UTF-8');
		$this->insertedDate = new \DateTime('now');
	}


	public function authorize(): void
	{
		$this->authorizedByUser = true;
		$this->authorizedDate = new \DateTime('now');
		$this->canceled = false;
		$this->cancelDate = null;
		if ($this->ip === null) {
			$this->ip = Helpers::userIp();
		}
	}


	public function unAuthorize(): void
	{
		$this->authorizedByUser = false;
	}


	public function isActive(): bool
	{
		return $this->authorizedByUser === true && $this->canceled === false;
	}


	public function isCanceled(): bool
	{
		return $this->canceled;
	}


	public function cancel(?string $message = null): void
	{
		$this->canceled = true;
		$this->cancelMessage = $message;
		$this->cancelDate = new \DateTime('now');
	}


	public function getEmail(): string
	{
		return $this->email;
	}


	public function getHash(): string
	{
		return $this->hash;
	}


	public function getIp(): ?string
	{
		return $this->ip;
	}


	public function setIp(?string $ip): void
	{
		$this->ip = $ip;
	}


	public function getSource(): ?string
	{
		return $this->source;
	}


	public function setSource(?string $source): void
	{
		$this->source = $source;
	}


	public function getCancelMessage(): ?string
	{
		return $this->cancelMessage;
	}


	public function setCancelMessage(?string $cancelMessage): void
	{
		$this->cancelMessage = $cancelMessage;
	}


	public function getCancelDate(): ?\DateTime
	{
		return $this->cancelDate;
	}


	public function setCancelDate(?\DateTime $cancelDate): void
	{
		$this->cancelDate = $cancelDate;
	}


	public function isAuthorizedByUser(): bool
	{
		return $this->authorizedByUser;
	}


	public function getAuthorizedDate(): ?\DateTime
	{
		return $this->authorizedDate;
	}


	public function getInsertedDate(): \DateTime
	{
		return $this->insertedDate;
	}
}
