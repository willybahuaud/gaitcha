# Gaitcha

Self-hosted behavioral captcha. A simple checkbox analyzes how the user interacts with it — mouse trajectory, keyboard timing, physiological signals — to tell humans from bots. No third-party dependency, no tracking, no friction.

## Why

Most captcha solutions either rely on third-party services (sending user data to external servers) or use proof-of-work challenges that automated browsers can solve trivially.

Gaitcha takes a different approach: it watches **how** the user reaches and checks a visible checkbox. Humans hesitate, deviate, decelerate, click slightly off-center. Bots click perfectly, instantly, without inertia. The behavioral log is scored server-side — no external API, no user fingerprinting, fully stateless.

## Quick Start

### Install

```bash
composer require willybahuaud/gaitcha
```

### HTML

```html
<form data-gaitcha data-gaitcha-endpoint="/captcha/init" method="POST" action="/submit">
    <input type="text" name="name" required>
    <input type="email" name="email" required>
    <button type="submit">Send</button>
</form>

<script src="gaitcha.min.js"></script>
```

### PHP — Init endpoint

```php
use Gaitcha\Config;
use Gaitcha\AbstractEndpoint;

$config = new Config([
    'secret' => 'your-secret-key-at-least-32-characters',
]);

class CaptchaEndpoint extends AbstractEndpoint
{
    protected function sendJsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public function handle(): void
    {
        $this->sendJsonResponse($this->handleInit());
    }
}

$endpoint = new CaptchaEndpoint($config);
$endpoint->handle();
```

### PHP — Validation

```php
use Gaitcha\Config;
use Gaitcha\ValidationOrchestrator;

$config       = new Config(['secret' => 'your-secret-key-at-least-32-characters']);
$orchestrator = new ValidationOrchestrator($config);
$result       = $orchestrator->validate($_POST);

if ($result->isAccepted()) {
    // Process the form.
} else {
    // $result->getReason():
    // token_absent | token_invalid | token_expired
    // token_already_used | score_insufficient | log_malformed
}
```

### Manual JS init

```js
Gaitcha.init(document.querySelector('#my-form'), '/captcha/init', {
    label: 'I am not a robot',
});
```

## How It Works

1. The form loads normally — no captcha field
2. On the first interaction signal (mousemove, touch, focus), an Ajax request fetches a signed token and a random field name
3. A visible checkbox is injected into the form
4. The JS collects interaction events (mouse moves, keyboard tabs, timing)
5. On submit, the behavioral log is sent alongside the token
6. The server verifies the token (signature + TTL) and scores the behavior across multiple signals

The scoring engine detects three profiles (mouse, keyboard, touch) and uses whichever scores highest. Several "kill signals" cause immediate rejection: interaction too fast, no movement before click, pixel-perfect center click, no keyboard events before check.

## Configuration

| Option | Type | Default | Description |
|---|---|---|---|
| `secret` | string | **required** | HMAC secret key (min 32 characters) |
| `ttl` | int | `120` | Token validity duration (seconds) |
| `score_threshold` | float | `0.5` | Minimum behavioral score (0.0–1.0) |
| `debug` | bool | `false` | Include scoring details in the response |
| `no_js_fallback` | string | `'reject'` | `'reject'` or `'allow'` when JS is disabled |
| `token_field_name` | string | `'_ct'` | Hidden field name for the signed token |
| `field_prefix` | string | `'_gc_'` | Prefix for generated field names |
| `anti_replay` | bool | `false` | Reject reused tokens (requires a `token_store`) |
| `token_store` | TokenStoreInterface | `null` | Storage backend for anti-replay |

### Anti-replay

```php
use Gaitcha\Config;
use Gaitcha\FileTokenStore;

$config = new Config([
    'secret'       => 'your-secret-key-at-least-32-characters',
    'anti_replay'  => true,
    'token_store'  => new FileTokenStore('/tmp/gaitcha-tokens.json'),
]);
```

`FileTokenStore` works for moderate traffic. For high-traffic sites, implement `TokenStoreInterface` with Redis or your database — the `checkAndAdd()` method must be atomic (e.g. `SETNX` for Redis, `INSERT ... ON CONFLICT` for SQL).

### HTML attributes

| Attribute | Description |
|---|---|
| `data-gaitcha` | Enables Gaitcha on the form |
| `data-gaitcha-endpoint` | Init endpoint URL (default: `/captcha/init`) |
| `data-gaitcha-label` | Checkbox label (default: "Je ne suis pas un robot") |

## Limits

- Not bulletproof against targeted attacks with headed browsers and behavioral simulation — but that level of effort is better addressed by rate limiting
- Requires JavaScript (configurable fallback for no-JS users)
- Designed to stop mass spam, not to protect high-value targets

## Development

```bash
composer install && npm install

# PHP tests
composer test

# Build JS
npm run build

# Dev (watch + PHP server)
npm run dev &
npm run serve
# → http://localhost:8080
```

## Author

[Willy Bahuaud](https://wabeo.fr) — WordPress Architect

## License

GPL-2.0-or-later
