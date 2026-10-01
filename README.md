# Gaitcha

English · [Français](README.fr.md)

Gaitcha is a self-hosted behavioral captcha for PHP and JavaScript. Visitors check a box; your server evaluates the mouse, keyboard or touch interactions around it. There are no image puzzles to solve and no third-party captcha account to set up.

The PHP library handles token verification and behavioral scoring. You connect it to your forms and can enable proof of work to add a computational cost to token requests. Interaction data goes to your server, without passing through a captcha provider.

[Website](https://gaitcha.com/) · [Live demo](https://gaitcha.com/#try-it) · [PHP guide](https://gaitcha.com/docs/) · [WordPress plugin](https://gaitcha.com/wordpress/)

## Install

The PHP library requires PHP 7.4 or later. In your application:

```bash
composer require willybahuaud/gaitcha
```

Build the JavaScript client from a checkout of **this repository**:

```bash
npm ci
npm run build
```

Copy `dist/gaitcha.min.js` to your application's public assets directory. The PHP library and the JavaScript bundle need to use compatible versions.

On WordPress, use [Gaitcha for WordPress](https://github.com/willybahuaud/gaitcha-for-wp). It includes the client and the form connectors.

## Connect a form

Set a random secret of at least 32 characters in your application's environment as `GAITCHA_SECRET`. Keep it on the server and use the same value for initialization and validation. For example, generate a value once with:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

The examples below assume your application has loaded Composer's `vendor/autoload.php`.

### Init endpoint

Route `/captcha/init` to a PHP handler:

```php
use Gaitcha\AbstractEndpoint;
use Gaitcha\Config;

class CaptchaEndpoint extends AbstractEndpoint
{
    /** Send the initialization payload as JSON. @param array $data Response payload. @return void */
    protected function sendJsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
    }

    /** Read the request body, including a proof-of-work solution when present. @return void */
    public function handle(): void
    {
        $request = json_decode((string) file_get_contents('php://input'), true);
        $this->sendJsonResponse($this->handleInit(is_array($request) ? $request : []));
    }
}

$config = new Config(['secret' => (string) getenv('GAITCHA_SECRET')]);
(new CaptchaEndpoint($config))->handle();
```

Keep this endpoint on the same origin as the form. Exclude it from caching and apply request-size and rate limits in your application or web server.

### HTML

```html
<form data-gaitcha data-gaitcha-endpoint="/captcha/init" method="POST" action="/submit">
    <label>Name <input type="text" name="name" required></label>
    <label>Email <input type="email" name="email" required></label>
    <button type="submit">Send</button>
</form>
<script src="/assets/gaitcha.min.js" defer></script>
```

### Submission handler

Run verification before processing the form:

```php
use Gaitcha\Config;
use Gaitcha\ValidationOrchestrator;

$config = new Config(['secret' => (string) getenv('GAITCHA_SECRET')]);
$result = (new ValidationOrchestrator($config))->validate($_POST);

if ($result->isAccepted()) {
    // Continue with your application's field validation and form processing.
} else {
    // Show a retry message. Use $result->getReason() for diagnostics.
}
```

This minimal example uses the core defaults: **proof of work and anti-replay are off**. The [PHP integration guide](https://gaitcha.com/docs/) shows a shared configuration with both enabled and storage outside the public directory.

## Proof of work and configuration

To enable proof of work, set `pow` to `true` in the configuration used by your endpoint. The first init request receives a signed SHA-256 challenge. The client solves it, then sends the solution to obtain a token. The bundled client handles these requests.

The calculation runs in a Web Worker, with a chunked main-thread fallback if workers are blocked. Its duration depends on the device and the configured difficulty. Each extra difficulty bit doubles the expected number of attempts.

Pair proof of work with `anti_replay` and a token store. Without a store, a solved challenge remains reusable until it expires.

```php
use Gaitcha\Config;
use Gaitcha\FileTokenStore;

$config = new Config([
    'secret'      => (string) getenv('GAITCHA_SECRET'),
    'pow'         => true,
    'anti_replay' => true,
    'token_store' => new FileTokenStore('/private/writable/path/gaitcha-tokens.json'),
]);
```

Replace the storage path with a private, writable location on your server. `FileTokenStore` is intended for moderate traffic; another store can implement `TokenStoreInterface`. The endpoint must pass the decoded JSON body to `handleInit()` when PoW is enabled.

| Option | Core default | Purpose |
|---|---|---|
| `secret` | Required | Server secret, at least 32 characters |
| `ttl` | `120` | Token lifetime in seconds |
| `score_threshold` | `0.5` | Minimum accepted score, between 0 and 1 |
| `debug` | `false` | Include scoring details in the result |
| `no_js_fallback` | `'reject'` | Reject a missing token, or bypass verification with `'allow'` |
| `token_field_name` | `'_ct'` | Hidden input holding the token |
| `field_prefix` | `'_gc_'` | Prefix for random field names |
| `pow` | `false` | Require a computation before issuing a token |
| `pow_difficulty` | `18` | Required leading zero bits, from 8 to 26 |
| `pow_challenge_ttl` | `90` | Challenge lifetime in seconds |
| `anti_replay` | `false` | Enable checks for previously used tokens and challenges |
| `token_store` | `null` | Required when anti-replay is enabled |

The WordPress plugin enables proof of work and anti-replay by default. These are different defaults from the standalone library.

## Widget and styles

The widget first appears as a non-interactive placeholder. Mouse, touch, focus or keyboard activity starts initialization. Once the token is available, the checkbox becomes interactive. The client captures the interaction log when the visitor checks it.

The scorer has mouse, keyboard and touch profiles. It uses signals such as trajectory changes, speed variation, key timing and available touch characteristics.

Choose the appearance independently of the scoring:

- **Theme:** `light`, `dark`, or `auto` to follow the OS preference
- **Style:** `default` for the classic widget, or `minimal` for thinner borders and no shadows

CSS is injected by the client and scoped to `.gaitcha-widget`. It uses `!important` to resist form-plugin overrides, so CSS customization needs to account for that. No separate CSS file is required.

| HTML attribute | Purpose |
|---|---|
| `data-gaitcha` | Initialize the form automatically |
| `data-gaitcha-endpoint` | Init URL; default `/captcha/init` |
| `data-gaitcha-label` | Text inside the widget |
| `data-gaitcha-container` | ID of the element that should contain the widget |
| `data-gaitcha-theme` | `light`, `dark` or `auto` |
| `data-gaitcha-style` | `default` or `minimal` |

For manual initialization, omit `data-gaitcha` from the form:

```js
const form = document.querySelector('#contact-form');
const instance = Gaitcha.init(form, '/captcha/init', {
    label: 'Check before sending',
    theme: 'auto',
    style: 'minimal',
});
```

`instance.reset()` unchecks the widget, clears the log and requests a fresh token. Use it after a rejected AJAX submission so the visitor can try again. `Gaitcha.reset(form)` also resets an initialized form. `instance.destroy()` removes the instance's widget and listeners.

## Data and troubleshooting

Interaction data travels from the visitor's browser to your server. The client does not set tracking cookies or create a persistent visitor fingerprint. Anti-replay requires temporary state; hosting and application logs are separate. See [the data flow](https://gaitcha.com/privacy/).

JavaScript is required by default. `no_js_fallback: 'allow'` accepts submissions without a token, including automated submissions. Keep `'reject'` if every submission must pass verification.

Rejection reasons include `token_absent`, `token_invalid`, `token_expired`, `token_already_used`, `score_insufficient` and `log_malformed`. Before changing the threshold, check token expiry, request fields and AJAX serialization. [Troubleshooting guide](https://gaitcha.com/guides/troubleshooting/).

## Limits

Client-side interaction data can be fabricated, so targeted automation can still pass verification. Proof of work adds computational cost; it does not prove that a visitor is human. Use Gaitcha alongside rate limiting and your application's usual validation.

Before launch, test your forms with keyboard navigation, mobile devices and assistive technology. Provide a retry when a submission is rejected.

## Development

```bash
composer install
npm ci
composer test
npm run build
```

For the demo, run `npm run dev` in one terminal and `npm run serve` in another, then open `http://localhost:8080`.

Report reproducible problems in [Issues](https://github.com/willybahuaud/gaitcha/issues). Include the version, integration and input method. Remove visitor data and secrets from examples.

## Author and license

Built by [Willy Bahuaud](https://wabeo.fr). Licensed under [GPL-2.0-or-later](LICENSE).
