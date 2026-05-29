# amashukov/rector-php-rules

Fifteen portable custom Rector rules for PHP 8.3+ — a static-analysis code-quality gate enforcing strict assertions, no `@phpstan-ignore`, no superglobals, no env-checks, no constructor fallbacks, no deferred `TODO`/`FIXME` markers, PSR Clock, clean functional tests, and comment-free YAML.

[![CI](https://img.shields.io/github/actions/workflow/status/AndreyMashukov/rector-php-rules/ci.yml?branch=main&label=CI)](https://github.com/AndreyMashukov/rector-php-rules/actions)
[![PHPStan L9](https://img.shields.io/github/actions/workflow/status/AndreyMashukov/rector-php-rules/stan.yml?branch=main&label=PHPStan%20L9)](https://github.com/AndreyMashukov/rector-php-rules/actions)
[![Latest Version](https://img.shields.io/packagist/v/amashukov/rector-php-rules)](https://packagist.org/packages/amashukov/rector-php-rules)
[![Downloads](https://img.shields.io/packagist/dt/amashukov/rector-php-rules)](https://packagist.org/packages/amashukov/rector-php-rules)
[![PHP](https://img.shields.io/packagist/dependency-v/amashukov/rector-php-rules/php)](https://packagist.org/packages/amashukov/rector-php-rules)
[![License](https://img.shields.io/packagist/l/amashukov/rector-php-rules)](LICENSE)
[![Stars](https://img.shields.io/github/stars/AndreyMashukov/rector-php-rules?style=social)](https://github.com/AndreyMashukov/rector-php-rules)

`amashukov/rector-php-rules` is a set of **portable custom Rector rules for PHP 8.3+** that enforce a single, consistent quality bar across `src/`, `bundles/`, `tests/`, and your YAML config files: explicit failure modes in production code, deterministic and value-pinning assertions in tests, no environment branching, no superglobals, no `@phpstan-ignore`, no constructor fallbacks (`?? new ...`), no non-DI clock access, no deferred `TODO`/`FIXME` markers, and no narrative comments in PHP or YAML. Drop them into any project's `rector.php` and turn architectural conventions into an enforced, CI-gated coding standard. Fifteen rules in total.

## Features

- **Production-code guards** — ban `assert()`, superglobals + `getenv()`, runtime environment branching, and `@phpstan-ignore` suppressions in `src/`.
- **Strict-test enforcement** — forbid type-only / existence-only / inline-array `assertContains` assertions, and conditional assertions inside `if`/`match` branches.
- **Functional-test purity** — block direct DB mutations and direct event/message-bus dispatch in `tests/Functional/**`, forcing tests through the public API.
- **PSR Clock requirement** — ban `time()` / `new DateTime` in production, mandating injected `Psr\Clock\ClockInterface`.
- **No constructor fallbacks** — flag the `$dep ?? new ClassName(...)` pattern that hides a required dependency behind a default-construction trapdoor.
- **Comment hygiene** — strip every comment outside interface method doc-blocks, with a PHPStan-tag carve-out.
- **YAML comment hygiene** — extends the same no-comments policy to `*.yaml` / `*.yml` files via a dedicated Rector rule that walks the configured paths, strips comments in place, and fails the run.
- **Detection-only by default for PHP** — most PHP rules attach a `// RECTOR-BAN: …` marker so a dry-run surfaces the violation in CI without silently rewriting domain semantics. The YAML rule strips and re-throws.

The full rule catalogue with BAD/GOOD examples is documented under [Rule catalogue](#rule-catalogue) below.

## Installation

```bash
composer require --dev amashukov/rector-php-rules
```

## Usage

Register the rules you want in your `rector.php`:

```php
<?php

declare(strict_types=1);

use Amashukov\RectorRules\NoArrayAssertContainsInTestsRector;
use Amashukov\RectorRules\NoAssertCallInSrcRector;
use Amashukov\RectorRules\NoAssertInsideIfInFunctionalTestsRector;
use Amashukov\RectorRules\NoCommentsOutsideInterfaceMethodDocBlockRector;
use Amashukov\RectorRules\NoDirectDbMutationInFunctionalTestsRector;
use Amashukov\RectorRules\NoDirectDispatchInFunctionalTestsRector;
use Amashukov\RectorRules\NoEnvironmentCheckInSrcRector;
use Amashukov\RectorRules\NoExistenceOnlyAssertionsInTestsRector;
use Amashukov\RectorRules\NoNullCoalesceNewFallbackRector;
use Amashukov\RectorRules\NoSilentFallbackRector;
use Amashukov\RectorRules\NoPhpstanIgnoreRector;
use Amashukov\RectorRules\NoSuperglobalAccessRector;
use Amashukov\RectorRules\NoTodoCommentRector;
use Amashukov\RectorRules\NoTypeOnlyAssertionsInTestsRector;
use Amashukov\RectorRules\RequirePsrClockInterfaceRector;
use Amashukov\RectorRules\Yaml\YamlCommentStripper;
use Amashukov\RectorRules\Yaml\YamlCommentStripperInterface;
use Amashukov\RectorRules\Yaml\YamlNoCommentsChecker;
use Amashukov\RectorRules\Yaml\YamlNoCommentsCheckerInterface;
use Amashukov\RectorRules\Yaml\YamlNoCommentsRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->registerService(YamlNoCommentsChecker::class, YamlNoCommentsCheckerInterface::class)
    ->registerService(YamlCommentStripper::class, YamlCommentStripperInterface::class)
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withRules([
        NoCommentsOutsideInterfaceMethodDocBlockRector::class,
        NoPhpstanIgnoreRector::class,
        NoSuperglobalAccessRector::class,
        NoEnvironmentCheckInSrcRector::class,
        NoAssertCallInSrcRector::class,
        NoAssertInsideIfInFunctionalTestsRector::class,
        NoArrayAssertContainsInTestsRector::class,
        NoTypeOnlyAssertionsInTestsRector::class,
        NoExistenceOnlyAssertionsInTestsRector::class,
        RequirePsrClockInterfaceRector::class,
        NoDirectDbMutationInFunctionalTestsRector::class,
        NoDirectDispatchInFunctionalTestsRector::class,
        NoNullCoalesceNewFallbackRector::class,
        NoSilentFallbackRector::class,
        NoTodoCommentRector::class,
    ])
    ->withConfiguredRule(YamlNoCommentsRector::class, [
        YamlNoCommentsRector::PATHS => [__DIR__ . '/config'],
    ]);
```

Most rules are **detection-only**: they attach a `// RECTOR-BAN: …` marker comment to the offending node so that `rector process --dry-run` surfaces the violation in CI and `make rector` blocks the commit, without auto-rewriting the code (which would silently destroy domain semantics). The marker also makes the dry-run output self-explanatory for the reviewer.

The rules apply path filtering by directory convention (`/src/`, `/bundles/`, `/tests/`, with skips for `/migrations/`, `/.rector/`, `/var/`, `/vendor/`, `/bin/`, `/public/`). If your layout differs you can additionally scope rules through `RectorConfig::skip(...)` in your `rector.php`.

## Rule catalogue

### 1. `NoCommentsOutsideInterfaceMethodDocBlockRector`

**Strips every comment outside interface method doc-blocks.** Class-level prose, method-body inline comments, file headers, and class-level doc-blocks are removed. Only doc-blocks directly above methods declared inside an `interface { … }` block are preserved verbatim.

**Why:** code is self-documenting through naming, types, and tests. Prose comments rot — they drift away from the code they describe, become stale, and lie to the next reader. Genuine "why" lives in repository documentation (README, ADR), not next to the line. Interface method doc-blocks are the one exception because they form the public contract.

**PHPStan-tag carve-out.** Doc-blocks that contain `@param` / `@return` / `@var` / `@template*` / `@phpstan-*` / `@psalm-*` / `@throws` tags survive — the rule extracts only the tag lines and drops every prose line. Inline `// @phpstan-ignore-next-line` line comments survive for the same reason. This preserves type-narrowing annotations PHPStan depends on without leaving any prose behind.

```php
// BAD
final class Foo
{
    /** why this constant exists */
    private const int X = 1;

    /**
     * Multi-line prose about what doX does.
     */
    public function doX(): void
    {
        // step 1 — fetch
        $this->y();
    }
}

// GOOD
final class Foo
{
    private const int X = 1;

    public function doX(): void
    {
        $this->y();
    }
}
```

### 2. `NoPhpstanIgnoreRector`

**Forbids `@phpstan-ignore`, `@phpstan-ignore-next-line`, `@psalm-suppress`, and `phpstan-baseline` entries anywhere in PHP comments + doc-blocks.**

**Why:** silencing a static-analyzer error is a debt instrument: the underlying type mismatch stays, the next reader can't tell whether it's a known false-positive or a real bug, and the suppression survives long after the actual issue is fixed. Fix the type, not the report. If the analyzer is genuinely wrong, narrow the type at the source (typed wrappers, `instanceof` guards, dedicated VOs) rather than at the suppression site.

```php
// BAD
// @phpstan-ignore-next-line
$foo->bar();

// GOOD
if ($foo instanceof Bar) {
    $foo->bar();
}
```

### 3. `NoSuperglobalAccessRector`

**Forbids every PHP superglobal access** (`$_ENV`, `$_SERVER`, `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_FILES`, `$_SESSION`) **and `getenv()` / `putenv()` calls** anywhere under `src/`, `bundles/`, and `tests/`. Skips `migrations/`, `.rector/`, `var/`, `vendor/`, `bin/`, `public/`.

**Why:** runtime superglobal access bypasses the dependency-injection boundary. The class becomes untestable in isolation (the test has to mutate process-wide state via `putenv` and remember to restore it), config drift becomes invisible (you can't see which classes read which env vars from a wiring file), and request data flows through an untyped channel instead of the framework's typed `Request` object. Env values should land via the DI container; request data via the framework Request object; tests pull from the container.

```php
// BAD
$apiKey = $_ENV['API_KEY'] ?? getenv('API_KEY');

// GOOD
$apiKey = $this->apiKey; // injected by DI from container configuration
```

### 4. `NoEnvironmentCheckInSrcRector`

**Forbids runtime environment branching** (`'prod'`/`'dev'`/`'test'`/`'staging'` literal comparisons, `$_ENV['APP_ENV']` / `getenv('APP_ENV')` reads, `$kernel->getEnvironment()` calls) anywhere under `src/**`. Skips `tests/`, `migrations/`, `.rector/`.

**Why:** behaviour gating belongs at the DI / config / route-loader layer, not inside production classes. An inline `if ($env === 'test')` branch makes the production code do something different under test than under prod — which means the test suite isn't actually testing production behaviour. Env-scoped routes, env-scoped DI bundles, env-scoped services.yaml configurations are the right level to express "this only runs under test".

```php
// BAD
if ('test' !== ($_ENV['APP_ENV'] ?? '')) {
    return new JsonResponse(['error' => 'not_found'], 404);
}

// GOOD
// gate the route at the config layer (env-scoped route loader / DI compiler pass) instead.
```

### 5. `NoAssertCallInSrcRector`

**Forbids bare `assert()` / `\assert()` function calls anywhere under `src/**`.** Skips `tests/`, `migrations/`, `.rector/`.

**Why:** `assert()` is silent under production `zend.assertions=-1` — the line literally compiles to nothing. The narrowing PHPStan gets from `\assert($x instanceof Foo)` is identical to the narrowing it gets from `if (!$x instanceof Foo) throw new \LogicException(...)`, but the second form actually fires at runtime when the invariant breaks. Explicit `throw` makes the failure mode visible in stack traces, Sentry alerts, and code review; `assert()` makes it invisible.

```php
// BAD
\assert($value instanceof Foo || $value instanceof Bar);
$value->doThing();

// GOOD
if (!$value instanceof Foo && !$value instanceof Bar) {
    throw new \LogicException(sprintf('unsupported %s', $value::class));
}
$value->doThing();
```

### 6. `NoAssertInsideIfInFunctionalTestsRector`

**Forbids PHPUnit `assertX()` calls inside `if`/`elseif`/`else`/`match` branches in `tests/Functional/**`.** Skips `tests/Functional/Traits/` (shared test infrastructure, not real tests). Detection-only — re-emits the node unchanged so a reviewer refactors the conditional assertion into per-state helpers.

**Why:** a functional test must drive the production code to ONE deterministic state and assert exactly that state. A conditional assertion (`if (X) assertY()`) means the test doesn't actually know what state the code reached — it just shrugs and passes either way. That defeats the test's defensive purpose: a real regression where the production code reached the wrong branch wouldn't fire any assertion at all.

```php
// BAD
if (Status::READY === $entity->getStatus()) {
    $entity = $this->advance($entity);
    self::assertNotNull($entity);
}

// GOOD
$entity = $this->driveReadyToActive($entity);
self::assertSame(Status::ACTIVE, $entity->getStatus());
```

### 7. `NoArrayAssertContainsInTestsRector`

**Forbids `assertContains($actual, [A, B, ...])` / `self::assertContains(...)` calls where the second argument is an inline array literal.** Applies under `tests/**`; `.rector/` rules and `migrations/` skipped.

**Why:** the pattern is a smudged "either A or B" assertion that hides non-determinism. If the production code under test is correctly deterministic, the test should assert exactly one expected state via `assertSame`. If you can't pin one state, the test is racing the production code path or papering over a non-deterministic branch — fix the architecture (mock the right boundary, sequence the drive helpers, install a frozen clock), then assert the one true post-condition.

```php
// BAD
self::assertContains($entity->getStatus(), [Status::READY, Status::DONE]);

// GOOD
// pin the production path so it reaches ONE deterministic state, then:
self::assertSame(Status::DONE, $entity->getStatus());
```

### 8. `NoTypeOnlyAssertionsInTestsRector`

**Forbids PHPUnit type-only assertions** — every `assertIs*` and `assertIsNot*` variant: `assertIsArray`, `assertIsString`, `assertIsInt`, `assertIsBool`, `assertIsFloat`, `assertIsNumeric`, `assertIsObject`, `assertIsCallable`, `assertIsScalar`, `assertIsIterable`, `assertIsResource`, `assertIsClosedResource`. Applies under `tests/**`.

**Why:** type-only asserts pass on every wrong-but-typed value. `assertIsArray($result)` is green for an empty array, for `[null, null, null]`, for `['error' => 'oops']` — none of which is what production should return. A test that "shape is right" is not a test that the production code does the right thing. Pin the actual value via `assertSame`; the type check is then implicit in the equality comparison.

```php
// BAD
self::assertIsArray($body['data']);
self::assertIsString($json['id']);

// GOOD
self::assertSame(['enabled' => true, 'count' => 3], $body['data']);
self::assertSame('a1b2c3d4-...', $json['id']);
```

### 9. `NoExistenceOnlyAssertionsInTestsRector`

**Forbids PHPUnit existence-only assertions** — `assertNotEmpty`, `assertNotNull`, `assertArrayHasKey`, `assertObjectHasProperty`, `assertObjectHasAttribute`. Applies under `tests/**`.

**Why:** "value is present" without "value is X" passes on every wrong-but-present value. `assertNotNull($entity->getCompletedAt())` is green for any DateTime — wrong year, wrong day, wrong timezone — as long as it's not `null`. Pin the actual value via `assertSame`; the existence-vs-not is implicit in equality comparison.

```php
// BAD
self::assertNotNull($entity->getCompletedAt());
self::assertNotEmpty($body['items']);
self::assertArrayHasKey('id', $body);

// GOOD
self::assertSame('2026-05-21T12:00:00+00:00', $entity->getCompletedAt()?->format(DateTimeInterface::ATOM));
self::assertSame(3, count($body['items']));
self::assertSame('a1b2c3d4-...', $body['id']);
```

### 10. `RequirePsrClockInterfaceRector`

**Bans `time()` / `microtime()` / `new DateTime` / `new DateTimeImmutable` in production code.** Skips entity classes, migrations, DataFixtures, tests, and `.rector/`.

**Why:** non-DI clock access makes the test suite non-deterministic. A unit that internally calls `time()` can't be tested at a specific moment — the test either tolerates a wall-clock window (flaky) or freezes time globally (race condition between concurrent tests). Inject `Psr\Clock\ClockInterface` and use `$this->clock->now()`; in tests, bind a frozen clock VO via the container override. Stored ISO timestamps stay parseable via `DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $iso)`.

```php
// BAD
$age = time() - (int) $stored;
$expires = new DateTimeImmutable('+1 day');

// GOOD
$age = $this->clock->now()->getTimestamp() - (int) $stored;
$expires = $this->clock->now()->modify('+1 day');
```

### 11. `NoDirectDbMutationInFunctionalTestsRector`

**Forbids direct DB mutations** (`EntityManagerInterface::{flush,persist,remove,merge,refresh,detach,lock}`, `Doctrine\DBAL\Connection::{executeStatement,executeUpdate,insert,update,delete,prepare,executeQuery}`, `Doctrine\DBAL\Statement::{execute,executeStatement}`) inside `tests/Functional/**`. Carve-out: `tests/Functional/Repository/**` (repository tests inherently exercise persistence).

**Why:** every functional test should reach state EXCLUSIVELY through the public HTTP API + cron + messenger transports — direct DB writes bypass the controller / event-subscriber / antifraud-guard invariants the test is supposed to defend. A test that mutates rows directly defends a state the real user can never produce in production. Real bugs (controller validation, event-subscriber side effects, repository UPSERT race) slip past such a test and surface only after deploy.

```php
// BAD
$em->remove($entity);
$em->flush();

// GOOD
$this->client->request('POST', '/api/entity/' . $entity->getId() . '/cancel');
```

### 12. `NoDirectDispatchInFunctionalTestsRector`

**Forbids direct `EventDispatcher::dispatch` / `MessageBusInterface::dispatch` calls inside `tests/Functional/**`.**

**Why:** direct dispatch bypasses the production trigger (controller / external worker / dispatcher) that fires the event in production. The test ends up asserting the event-handling code in isolation while skipping the controller validation, header parsing, antifraud gates, and side-effects that ALSO fire on the real path. Drive via the API endpoint that wraps the publish, or mock the outbound adapter and let the real dispatcher emit the event end-to-end.

```php
// BAD
$container->get('event_dispatcher')->dispatch(new MyEvent(...));

// GOOD
// Mock the outbound adapter; let the real dispatcher emit the event end-to-end.
```

### 13. `NoNullCoalesceNewFallbackRector`

**Forbids the `$dep ?? new ClassName(...)` constructor-fallback pattern.**

**Why:** the pattern hides a required dependency behind a default-construction trapdoor — the caller silently runs the wrong wiring instead of failing at the boundary. Tests rely on this fallback to skip wiring; production crashes when the implicit default and the real one diverge. Make the parameter required, let the DI container or the test inject the real collaborator, and crash early if it is missing.

```php
// BAD
public function __construct(?Clock $clock = null)
{
    $this->clock = $clock ?? new SystemClock();
}

// GOOD
public function __construct(private readonly Clock $clock)
{
}
```

### 14. `NoSilentFallbackRector`

**Forbids every silent-default shape for a missing value:** `$x ?? $default`, `$x ??= $default`, `isset(...) ? $x : $default`, `!isset(...) ? $default : $x`, `array_key_exists(...) ? $x : $default`, and the short ternary `$x ?: $default`. The narrower `NoNullCoalesceNewFallbackRector` is left to handle `?? new ClassName(...)` so the two rules don't double-mark.

**Why:** every silent fallback is a place where a misconfigured environment, a stale upstream payload, or an AI-generated "safe default" papers over a missing input instead of failing on boot. Validate at the boundary (a `requireEnv()` / `requireConfigInt()` helper), narrow the type at the parser/deserializer, or branch explicitly. Use destructuring defaults and function parameter defaults for declared defaults — those are explicit, the inline fallback shapes are not.

```php
// BAD
$env  = $_ENV['APP_ENV'] ?? 'dev';
$port = $config['port'] ?? 8080;
$items ??= [];
$name = isset($user['name']) ? $user['name'] : 'anonymous';
$title = $row['title'] ?: 'Untitled';

// GOOD
$env  = self::requireEnv('APP_ENV');
$port = self::requireConfigInt($config, 'port');
if ($items === null) {
    throw new \LogicException('items must be set before this point');
}
if (!isset($user['name'])) {
    throw new \DomainException('user has no name');
}
$name = $user['name'];
```

Sibling rules in the family carry the same policy across the stack: `no-silent-fallback` in [`eslint-plugin-mess-detector`](https://github.com/AndreyMashukov/eslint-plugin-mess-detector) (TS/JS — `??`, `??=`, `||` with literal RHS); `nosilentfallback` in [`go-lint`](https://github.com/AndreyMashukov/go-lint) (Go `cmp.Or` with literal, post-read string/numeric fallback); `no_silent_fallback` in [`rust-lint`](https://github.com/AndreyMashukov/rust-lint) (Rust `.unwrap_or` / `.unwrap_or_else` / `.unwrap_or_default` / `.ok_or` / `.map_or`).

**Hydration boundary carve-out:** Doctrine entities bypass constructors, so `?? new ArrayCollection()` in property initialisers is sometimes structurally required. If your `src/Entity/` lives in the standard layout, scope the rule out for that directory in your own `rector.php`:

```php
->withSkip([
    NoSilentFallbackRector::class => [__DIR__ . '/src/Entity'],
])
```

### 14. `NoTodoCommentRector`

**Forbids `TODO` / `FIXME` / `XXX` / `HACK` markers in PHP comments and doc-blocks outright.**

**Why:** a deferred marker is work you decided against but left in the tree — and an owner (`@alice`) or a ticket (`PROJ-123`) does not redeem it, it only makes the rot look organized. Implement it now, or track it in an issue and link that from real documentation, but do not leave the stub in the code. Only a marker that *opens* a comment counts, so prose that merely mentions one (documentation about markers) is left alone.

```php
// BAD
// TODO(@alice): switch to the pooled client once PROJ-123 lands
$client = new Client();

// GOOD
$client = new PooledClient();
```

### 15. `YamlNoCommentsRector` *(YAML)*

**Forbids comments — whole-line or inline — in `*.yaml` / `*.yml` files at the configured paths. Strips them in place and fails the run.**

**Why:** YAML config files (`config/packages/*.yaml`, `services.yaml`, Doctrine `*.orm.yml`, GitLab/GitHub CI files) collect narrative comments the same way PHP files do — and Rector cannot rewrite them through its PHP AST. This rule walks the configured paths recursively on the first PHP node Rector visits, removes every comment it finds, and throws a `RuntimeException` listing the original `path:line:column` findings. The next run is clean.

Configuration is explicit and required. The rule depends on two
interfaces — `YamlNoCommentsCheckerInterface` and
`YamlCommentStripperInterface` — so the Rector DI container needs to
know which concrete implementations to instantiate. Bind them with
`registerService(concrete, alias)`:

```php
use Amashukov\RectorRules\Yaml\YamlCommentStripper;
use Amashukov\RectorRules\Yaml\YamlCommentStripperInterface;
use Amashukov\RectorRules\Yaml\YamlNoCommentsChecker;
use Amashukov\RectorRules\Yaml\YamlNoCommentsCheckerInterface;
use Amashukov\RectorRules\Yaml\YamlNoCommentsRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->registerService(YamlNoCommentsChecker::class, YamlNoCommentsCheckerInterface::class)
    ->registerService(YamlCommentStripper::class, YamlCommentStripperInterface::class)
    ->withConfiguredRule(YamlNoCommentsRector::class, [
        YamlNoCommentsRector::PATHS => [__DIR__ . '/config'],
        // Optional. Default: ['yaml', 'yml']
        // YamlNoCommentsRector::EXTENSIONS => ['yaml', 'yml', 'neon'],
    ]);
```

Without the two `registerService` calls Rector's container throws
`BindingResolutionException` because it cannot instantiate an
interface directly.

The scanner is YAML-aware: hashes inside quoted scalars (`note: "value with # hash"`) and inside non-whitespace tokens (`docs: https://example.com/page#section`) are not flagged.

```yaml
# BAD
framework:
  # async transport
  messenger:
    transports:
      async: '%env(MESSENGER_TRANSPORT_DSN)%' # async queue

# GOOD
framework:
  messenger:
    transports:
      async: '%env(MESSENGER_TRANSPORT_DSN)%'
```

## Requirements

- PHP 8.3+
- `rector/rector` ^2.0
- `nikic/php-parser` ^5.0
- `symplify/rule-doc-generator-contracts` ^11.2 || ^12.0

## Related packages

| Package | Layer |
|---------|-------|
| [amashukov/ton-php](https://github.com/AndreyMashukov/ton-php) | Umbrella TON SDK (Cell/BOC, wallet, toncenter) |
| [amashukov/eth-php](https://github.com/AndreyMashukov/eth-php) | Umbrella EVM SDK (Keccak, secp256k1, RLP, EIP-1559, ABI, RPC) |
| [amashukov/blockchain-context-bundle](https://github.com/AndreyMashukov/blockchain-context-bundle) | Symfony 7 bundle wiring the TON + EVM stacks |
| [amashukov/http-client-php](https://github.com/AndreyMashukov/http-client-php) | PSR-18 cURL HTTP client |

## Quality

- **PHPStan level 9** across `src/`.
- **php-cs-fixer** with the `@PER-CS` ruleset.
- **GitHub Actions CI** on every push.
- Each rule ships with a paired before/after fixture test.

## License

MIT — see [LICENSE](LICENSE).
