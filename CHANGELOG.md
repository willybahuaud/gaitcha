# Changelog

All notable changes to this project will be documented in this file.

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
