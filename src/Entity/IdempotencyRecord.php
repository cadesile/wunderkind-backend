<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdempotencyRecordRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: IdempotencyRecordRepository::class)]
#[ORM\Table(name: 'idempotency_record')]
#[ORM\UniqueConstraint(
    name: 'uq_idempotency_record_user_method_path_key',
    columns: ['user_id', 'method', 'path', 'idempotency_key'],
)]
#[ORM\Index(columns: ['created_at'], name: 'idx_idempotency_record_created_at')]
class IdempotencyRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $userId;

    #[ORM\Column(type: 'string', length: 10)]
    private string $method;

    #[ORM\Column(type: 'string', length: 255)]
    private string $path;

    #[ORM\Column(type: 'string', length: 120)]
    private string $idempotencyKey;

    #[ORM\Column(type: 'string', length: 64)]
    private string $requestBodyHash;

    #[ORM\Column(type: 'smallint')]
    private int $responseStatus;

    #[ORM\Column(type: 'text')]
    private string $responseBody;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $userId,
        string $method,
        string $path,
        string $idempotencyKey,
        string $requestBodyHash,
        int $responseStatus,
        string $responseBody,
    ) {
        $this->id              = new UuidV7();
        $this->userId          = $userId;
        $this->method          = $method;
        $this->path            = $path;
        $this->idempotencyKey  = $idempotencyKey;
        $this->requestBodyHash = $requestBodyHash;
        $this->responseStatus  = $responseStatus;
        $this->responseBody    = $responseBody;
        $this->createdAt       = new \DateTimeImmutable();
    }

    public function getId(): UuidV7 { return $this->id; }
    public function getUserId(): string { return $this->userId; }
    public function getMethod(): string { return $this->method; }
    public function getPath(): string { return $this->path; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getRequestBodyHash(): string { return $this->requestBodyHash; }
    public function getResponseStatus(): int { return $this->responseStatus; }
    public function getResponseBody(): string { return $this->responseBody; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
