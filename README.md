# L1 - Cloudflare bindings for Laravel

![CI](https://github.com/parallel-oss/l1/workflows/CI/badge.svg?branch=master)
[![Latest Stable Version](https://poser.pugx.org/parallel-oss/l1/v/stable)](https://packagist.org/packages/parallel-oss/l1)
[![License](https://poser.pugx.org/parallel-oss/l1/license)](https://packagist.org/packages/parallel-oss/l1)

Extend your PHP/Laravel application with Cloudflare bindings.

This package offers support for:

- [x] [Cloudflare D1](https://developers.cloudflare.com/d1)
- [ ] [Cloudflare KV](https://developers.cloudflare.com/kv/)
- [ ] [Cloudflare Queues](https://developers.cloudflare.com/queues)

## 🚀 Installation

You can install the package via Composer:

```bash
composer require parallel-oss/l1
```

## 🙌 Usage

### D1 with raw PDO

Though D1 is not connectable via SQL protocols, it can be used as a PDO driver via the package connector. This proxies the query and bindings to the D1's `/query` endpoint in the Cloudflare API.

```php
use Parallel\L1\D1\D1Pdo;
use Parallel\L1\D1\D1PdoStatement;
use Parallel\L1\CloudflareD1Connector;

$pdo = new D1Pdo(
    dsn: 'sqlite::memory:', // irrelevant
    connector: new CloudflareD1Connector(
        database: 'your_database_id',
        token: 'your_api_token',
        accountId: 'your_cf_account_id',
    ),
);
```

### D1 with Laravel

In your `config/database.php` file, add a new connection:

```php
'connections' => [
    'd1' => [
        'driver' => 'd1',
        'prefix' => '',
        'database' => env('CLOUDFLARE_D1_DATABASE_ID', ''),
        'api' => 'https://api.cloudflare.com/client/v4',
        'auth' => [
            'token' => env('CLOUDFLARE_TOKEN', ''),
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID', ''),
        ],
    ],
]
```

Then in your `.env` file, set up your Cloudflare credentials:

```
CLOUDFLARE_TOKEN=
CLOUDFLARE_ACCOUNT_ID=
CLOUDFLARE_D1_DATABASE_ID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

The `d1` driver will proxy the PDO queries to the Cloudflare D1 API to run queries.

### D1 compatibility notes

This package keeps D1 usage close to native Laravel/SQLite, while accounting for Cloudflare-specific runtime limits:

- Multi-row `INSERT` queries are automatically chunked to D1's bind-parameter limit.
- Statements above D1 limits that cannot be safely rewritten fail fast with explicit errors.
- Transport and API error responses are normalized into stable PDO/Laravel exceptions.
- Retry behavior is conservative by default (safe/read-only and explicitly idempotent-safe paths).

These constraints still require app-level design choices:

- D1 SQL/statement limits (for example statement size, row/blob size, function argument limits).
- Workload shaping for long-running writes/migrations (batching and index strategy).
- Transaction expectations that differ from local SQLite when execution is remote/request-scoped.

See [docs/d1-sqlite-compatibility.md](docs/d1-sqlite-compatibility.md) for the full behavior matrix.

## 🐛 Testing

Run all tests (the built-in D1 worker is started automatically):

```bash
composer test
```

This starts the Worker that simulates the Cloudflare D1 API, runs PHPUnit, then stops the worker. The first run will install worker dependencies (`npm ci` in `tests/worker`) if needed.

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## 🔒 Security

If you discover any security related issues, please open an issue on GitHub.

## 🎉 Credits

- [Alex Renoki](https://github.com/rennokki) – original author
- [Parallel](https://github.com/parallel-oss) – maintainer
- [All Contributors](../../contributors)
