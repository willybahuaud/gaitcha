# Gaitcha

Self-hosted behavioral captcha. A simple checkbox analyzes how the user interacts with it — mouse trajectory, keyboard timing, touch gestures — to tell humans from bots. No third-party dependency, no tracking, no friction.

## Why

Most captcha solutions either rely on third-party services (sending user data to external servers) or use proof-of-work alone — which automated browsers solve without breaking a sweat.

Gaitcha takes a different approach: it watches **how** the user reaches and checks a visible checkbox. Humans hesitate, deviate, decelerate, click slightly off-center. Bots click perfectly, instantly, without inertia. The behavioral log is scored server-side — no external API, no user fingerprinting, fully stateless.

An optional [proof-of-work layer](#proof-of-work) sits in front: the client must burn CPU before the server even hands out the captcha field. Behavioral analysis catches the fakes; proof of work makes faking expensive at scale.

## Quick Start

### Install

```bash
composer require willybahuaud/gaitcha
```

Then build the JS client:

```bash
npm install && npm run build
```

This generates `dist/gaitcha.min.js` — copy it to your public assets directory and include it in your HTML.

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
        // Pass the decoded JSON body — required when 'pow' is enabled,
        // harmless otherwise.
        $request = json_decode((string) file_get_contents('php://input'), true);

        $this->sendJsonResponse($this->handleInit(is_array($request) ? $request : []));
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
const instance = Gaitcha.init(document.querySelector('#my-form'), '/captcha/init', {
    label: 'I am not a robot',
    container: document.getElementById('captcha-slot'), // optional target element
    theme: 'auto', // 'light' (default), 'dark', or 'auto' (follows OS preference)
    style: 'minimal', // 'default' or 'minimal' (sober monochrome, no shadows)
});
```

`init()` returns an instance with `destroy()` and `reset()` (see [Widget reset](#widget-reset) below).

## How It Works

1. The form loads with a **placeholder widget** — same dimensions as the final captcha (zero layout shift), visibly dimmed, with no field name and no token. It announces the captcha without exposing anything
2. On the first interaction signal (mousemove, touchstart, focus, keydown), the client contacts the init endpoint. If proof of work is enabled, the server answers with a challenge that a Web Worker solves in the background (a spinner shows in the placeholder meanwhile)
3. The init endpoint then delivers a signed token and a random field name; the placeholder is upgraded in place into the real, interactive widget
4. The JS collects interaction events: mouse moves, touch moves (with pressure and contact radius when available), keyboard tabs, and timing data
5. When the user checks the widget, the behavioral log is serialized immediately — ready for both classic form submits and AJAX-based plugins
6. The server verifies the token (signature + TTL) and scores the behavior across multiple signals

The scoring engine detects three profiles and uses the one that matches the check event:

- **Mouse** — trajectory shape, non-linearity, speed variation, angular jitter, direction reversals, endpoint deceleration, click offset, anti-Bezier signals, anti-CDP signals (coalesced events average, screen coordinate delta)
- **Keyboard** — focus-to-key timing, dwell time variance, navigation pattern (Tab/Shift+Tab)
- **Touch** — same trajectory signals as mouse, plus touch-specific data: pressure variance across the swipe, contact radius variance, and tap gesture analysis (duration, force, radiusX/Y on the final tap)

Multiple "kill signals" cause immediate rejection: interaction under 100ms, no movement before click, pixel-perfect center click/tap. If the primary profile doesn't kill, a secondary profile is scored when data exists — the highest score wins (benefit of the doubt for the human).

## Widget

The widget is a self-contained UI component: custom checkbox with animated states (pending, idle, loading with spinner, checked with bounce), a "gaitcha" badge, and hidden inputs for the token and behavioral log. All styles are injected via a single `<style>` tag — no external CSS file needed.

A placeholder version is injected as soon as the page loads: same dimensions as the active widget, dimmed checkbox and label, non-interactive, and **empty** — no field name, no token, nothing for a bot to scrape. When the user first interacts with the form, it upgrades in place. No layout shift, and the user knows from the start that a verification step exists.

### Theming

Two orthogonal axes: **theme** (ambiance) and **style** (visual language). Both available as `Gaitcha.init()` options or as HTML attributes (`data-gaitcha-theme`, `data-gaitcha-style`).

**Theme:**

| Value | Behavior |
|---|---|
| `'light'` | Light background (default) |
| `'dark'` | Dark background, forced |
| `'auto'` | Follows OS preference via `prefers-color-scheme` |

**Style:**

| Value | Behavior |
|---|---|
| `'default'` | Soft radii, subtle shadows, blue accent, green success state |
| `'minimal'` | Sober monochrome: 2px radii, 1px hairline borders, no shadows, no ripple, ink-on-paper checked state. Designed to blend into editorial and high-end sites |

The two combine freely — `minimal` + `dark` gives a deep-ink widget with an inverted (white box, dark check) checked state.

All CSS variables are scoped to `.gaitcha-widget` (no `:root` pollution). Every property uses `!important` to survive third-party form plugin CSS that tends to override everything.

### Responsive layout

The widget is fluid (`width: 100%`, `max-width: 260px`). On narrow containers, a CSS container query on the content area switches the badge to compact mode — the brand name collapses to a "g" overlay on the shield icon. No media queries, so it adapts to the actual available space regardless of viewport size.

### Widget reset

After a server-side rejection on AJAX forms, the widget needs to go back to an unchecked state so the user can retry. Two ways to do it:

```js
// Via the instance returned by init()
const instance = Gaitcha.init(form, endpoint, options);
// ... after rejection:
instance.reset();

// Or via the static API
Gaitcha.reset(form);
```

`reset()` unchecks the widget, clears the behavioral log, and fetches a fresh token from the server. The user gets a clean slate for a new attempt.

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
| `pow` | bool | `false` | Require a proof of work before issuing tokens |
| `pow_difficulty` | int | `18` | Leading zero bits required (8–26) |
| `pow_challenge_ttl` | int | `90` | Challenge validity duration (seconds) |

### Proof of work

Without it, `/captcha/init` is free: a bot can harvest the random field name and a valid token at will, then forge a plausible behavioral log. The PoW layer changes the economics — every token costs CPU time first.

```php
$config = new Config([
    'secret' => 'your-secret-key-at-least-32-characters',
    'pow'    => true,
]);
```

How it flows, on a single endpoint:

1. First call to `/captcha/init` → the server answers `{ pow_challenge: { nonce, difficulty, expires, signature } }` instead of a token. The challenge is HMAC-signed and stateless
2. The client must find a counter such that `sha256(nonce + '.' + counter)` starts with `difficulty` zero bits. This runs in a Web Worker (with a chunked main-thread fallback if workers are blocked by CSP) — the user never notices, it overlaps with their first interaction
3. The client calls `/captcha/init` again with `{ pow: { ...challenge, solution } }` → the server verifies the signature, expiry and solution, then issues the token

At the default difficulty of 18 bits, solving takes roughly 100–500 ms on desktop, up to a couple of seconds on low-end mobile. Each behavioral check costs a human ~0 (background work), but harvesting 10,000 tokens costs a bot real CPU time. Raise `pow_difficulty` if you're under attack — each +1 doubles the cost.

Two things to know:

- **Your endpoint must pass the decoded JSON request body to `handleInit()`** (see the endpoint example above). With `pow` enabled, `handleInit()` throws a `LogicException` if you don't — better than failing silently
- **Pair it with `anti_replay`** to consume each challenge nonce on first use (one solution = one token). Without a `token_store`, a solved challenge stays reusable until it expires (`pow_challenge_ttl`)

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
| `data-gaitcha-container` | ID of a DOM element where the checkbox should be injected |
| `data-gaitcha-theme` | Widget theme: `light` (default), `dark`, or `auto` |
| `data-gaitcha-style` | Widget style: `default` or `minimal` |

## Limits

- Not bulletproof against targeted attacks with headed browsers and behavioral simulation — but that level of effort is better addressed by rate limiting
- Requires JavaScript (configurable fallback for no-JS users)
- Designed to stop mass spam, not to protect high-value targets

## Development

```bash
composer install && npm install

# PHP tests
composer test

# Build JS (→ dist/gaitcha.min.js)
npm run build

# Demo (watch + PHP server)
npm run dev &
npm run serve
# → http://localhost:8080
```

## WordPress plugin

Using WordPress? Check out [Gaitcha for WordPress](https://github.com/willybahuaud/gaitcha-for-wp) — a ready-made plugin with connectors for CF7, Gravity Forms, WPForms, Fluent Forms, Formidable, Ninja Forms, WS Form, Elementor Pro, and native WordPress forms (login, register, lost password, comments).

## Author

[Willy Bahuaud](https://wabeo.fr) — WordPress Architect

## License

GPL-2.0-or-later
