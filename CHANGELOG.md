# Changelog

All notable changes to this project will be documented in this file.

## [0.7.0] — 2026-06-10

### Added
- Proof-of-work layer (opt-in via `pow` config): the init endpoint now requires a solved HMAC-signed challenge before issuing tokens, making mass token harvesting costly
- `PoWChallengeGenerator` and `PoWVerifier` PHP classes; `pow_difficulty` (default 18 bits) and `pow_challenge_ttl` (default 90s) config options
- JS PoW solver: inline SHA-256 in a Blob-based Web Worker, with chunked main-thread fallback when workers are unavailable (strict CSP)
- Challenge nonces are consumed through the existing `TokenStoreInterface` when `anti_replay` is enabled (one solution = one token)
- Placeholder widget injected at page load: same dimensions as the active widget (zero layout shift), dimmed and non-interactive, exposes no field name or token
- New widget state `pending`, with spinner shown while the PoW is being solved

### Changed
- `AbstractEndpoint::handleInit()` now accepts the decoded JSON request body (required when `pow` is enabled — a `LogicException` is thrown otherwise)
- Behavioral event collection now starts at the first interaction signal instead of after the init response — the trajectory during PoW solving is captured
- Token auto-refresh failures are caught and logged instead of raising unhandled rejections

## [0.6.0] — 2026-03-12

### Added
- Touch-specific scoring signals: pressure variance, contact radius variance, tap gesture analysis (duration, force, radiusX/Y)
- Compact badge mode: brand name collapses to "g" overlay on shield icon in narrow containers (CSS container query)
- Widget theming documentation in README
- Widget reset API documentation in README

### Changed
- Widget max-width reduced from 280px to 260px
- Container query breakpoint for compact badge set to 180px
- Widget layout restructured: checkbox is standalone, content section (label + badge) uses flex with container query
- Label allows text wrapping (`white-space: normal`) instead of truncating
- Touch profile uses same trajectory signals as mouse plus touch-specific data
- Secondary profile scoring: highest score wins (benefit of the doubt)

### Fixed
- Prevent log serialization when checkbox is unchecked
- Widget width now fluid (`width: 100%`) instead of fixed

### Removed
- Temporary debug `error_log` calls in ValidationOrchestrator
