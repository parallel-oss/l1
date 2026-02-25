<?php

namespace Parallel\L1\Test;

use Illuminate\Support\Arr;
use JsonException;
use Parallel\L1\CloudflareD1Connector;
use Parallel\L1\D1\Pdo\D1Pdo;
use Parallel\L1\D1\Pdo\D1PdoStatement;
use PDOException;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Response;
use Stringable;

class D1PdoStatementTest extends TestCase
{
    public function testNonJsonErrorResponseThrowsPdoExceptionWithHttpContext(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connector->expects($this->once())
            ->method('databaseQuery')
            ->willReturn($this->makeResponse(
                failed: true,
                status: 400,
                payload: null,
                body: 'Syntax error'
            ));

        $pdo = new D1Pdo('sqlite::memory:', $connector);
        $statement = new D1PdoStatement($pdo, 'insert into "t" ("a", "b") values (?, ?)');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('D1 transport error (HTTP 400): Syntax error');

        $statement->execute(['uuid', 'status:missed']);
    }

    public function testRetriesTransientHttpFailureForReadOnlyQueryThenSucceeds(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connector->expects($this->exactly(2))
            ->method('databaseQuery')
            ->willReturnOnConsecutiveCalls(
                $this->makeResponse(
                    failed: true,
                    status: 503,
                    payload: null,
                    body: 'Service Unavailable'
                ),
                $this->makeResponse(
                    failed: false,
                    status: 200,
                    payload: [
                        'success' => true,
                        'result' => [
                            [
                                'results' => [
                                    ['id' => 1, 'name' => 'ok'],
                                ],
                                'meta' => ['changes' => 0],
                            ],
                        ],
                    ],
                )
            );

        $pdo = new D1Pdo('sqlite::memory:', $connector);
        $statement = new D1PdoStatement($pdo, 'select * from "t" where "id" = ?');

        $executed = $statement->execute([1]);

        $this->assertTrue($executed);
        $this->assertSame([['id' => 1, 'name' => 'ok']], $statement->fetchAll());
    }

    public function testDoesNotRetryTransientFailureForWriteQueryByDefault(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connector->expects($this->once())
            ->method('databaseQuery')
            ->willReturn($this->makeResponse(
                failed: true,
                status: 503,
                payload: null,
                body: 'Service Unavailable'
            ));

        $pdo = new D1Pdo('sqlite::memory:', $connector);
        $statement = new D1PdoStatement($pdo, 'insert into "t" ("a", "b") values (?, ?)');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('D1 transport error (HTTP 503): Service Unavailable');

        $statement->execute(['u1', 't1']);
    }

    public function testJsonErrorPayloadIsMappedToStablePdoException(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connector->expects($this->once())
            ->method('databaseQuery')
            ->willReturn($this->makeResponse(
                failed: true,
                status: 500,
                payload: [
                    'success' => false,
                    'errors' => [
                        [
                            'message' => 'D1 DB is overloaded. Requests queued for too long.',
                            'code' => 9001,
                        ],
                    ],
                ],
            ));

        $pdo = new D1Pdo('sqlite::memory:', $connector);
        $statement = new D1PdoStatement($pdo, 'insert into "t" ("a") values (?)');

        try {
            $statement->execute(['x']);
            $this->fail('Expected PDOException was not thrown.');
        } catch (PDOException $e) {
            $this->assertSame('D1 DB is overloaded. Requests queued for too long.', $e->getMessage());
            $this->assertSame(9001, $e->getCode());
        }
    }

    public function testUniqueConstraintErrorIsMappedToIntegrityConstraintViolation(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connector->expects($this->once())
            ->method('databaseQuery')
            ->willReturn($this->makeResponse(
                failed: true,
                status: 400,
                payload: [
                    'success' => false,
                    'errors' => [
                        [
                            'message' => 'UNIQUE constraint failed: users.email',
                            'code' => 7500,
                        ],
                    ],
                ],
            ));

        $pdo = new D1Pdo('sqlite::memory:', $connector);
        $statement = new D1PdoStatement($pdo, 'insert into "users" ("email") values (?)');

        try {
            $statement->execute(['test@example.com']);
            $this->fail('Expected PDOException was not thrown.');
        } catch (PDOException $e) {
            $this->assertStringStartsWith('SQLSTATE[23000]: Integrity constraint violation:', $e->getMessage());
            $this->assertSame(23000, $e->getCode());
        }
    }

    public function testStringableBindingsAreNormalizedBeforeRequest(): void
    {
        $connector = $this->createMock(CloudflareD1Connector::class);
        $connector->expects($this->once())
            ->method('databaseQuery')
            ->with(
                'insert into "t" ("entry_uuid", "tag") values (?, ?)',
                $this->callback(function (array $params): bool {
                    return $params === ['abc', 'status:missed'];
                })
            )
            ->willReturn($this->makeResponse(
                failed: false,
                status: 200,
                payload: [
                    'success' => true,
                    'result' => [
                        [
                            'results' => [],
                            'meta' => ['changes' => 1],
                        ],
                    ],
                ],
            ));

        $pdo = new D1Pdo('sqlite::memory:', $connector);
        $statement = new D1PdoStatement($pdo, 'insert into "t" ("entry_uuid", "tag") values (?, ?)');

        $tag = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'status:missed';
            }
        };

        $this->assertTrue($statement->execute(['abc', $tag]));
    }

    private function makeResponse(bool $failed, int $status, ?array $payload, string $body = ''): Response
    {
        $response = $this->createMock(Response::class);
        $response->method('failed')->willReturn($failed);
        $response->method('status')->willReturn($status);
        $response->method('body')->willReturn($body);

        if ($payload === null) {
            $response->method('json')->willThrowException(new JsonException('Syntax error'));
            return $response;
        }

        $response->method('json')->willReturnCallback(
            fn (string|int|null $key = null, mixed $default = null): mixed => $key === null
                ? $payload
                : Arr::get($payload, $key, $default)
        );

        return $response;
    }
}
