# Changelog

All notable changes to this project will be documented in this file, in reverse chronological order by release.

## 1.0.0 - 2026-06-02

First tagged release.

### Added

- `BunnyOptimizer` hook provider (and `ConfigProvider`) that filters
  `wp_get_attachment_image_attributes`, `wp_get_attachment_image` and `wp_prepare_attachment_for_js`
  to rewrite attachment image `src`/`srcset` URLs into Bunny Optimizer (bunny.net) requests, mapping a
  `bunny` attribute (`width`, `height`, `aspect_ratio`, `quality`, `sharpen`, `blur`, `brightness`,
  `saturation`, `hue`, `gamma`, `contrast`, `auto_optimize`) to CDN query parameters.

### Changed

- PHP requirement is `^8.2` (PHP 8.4 is the primary target).
- Modernized the dev toolchain (PHPStan 2, PHPUnit 11 schema, composer-require-checker 4); now depends
  on `kaiseki/php-coding-standard: ^1.0` with the shared PHPStan config; `kaiseki/config` and
  `kaiseki/wp-hook` pinned to `^2.0`. CI now runs via the reusable workflow in `kaisekidev/.github`.

### Fixed

- `ConfigProvider` now maps `BunnyOptimizer::class` to its `BunnyOptimizerFactory` (previously
  referenced an undefined `BunnyOpt::class`, which broke container resolution).
- PHPStan 2 (level max): added value types throughout `BunnyOptimizer` and narrowed `mixed` filter
  inputs with `is_array`/`is_string`/`is_int`/`is_numeric` guards (no suppressions or casts to silence
  the analyzer). Numeric parameter validation now accepts `0` for the zero-allowing parameters
  (`quality`, `blur`, `hue`) and the neutral `0` for `brightness`/`saturation`/`gamma`/`contrast`,
  which the previous truthiness check incorrectly discarded.
