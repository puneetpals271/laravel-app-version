# Changelog

All notable changes to laravel-app-version are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-10-24

Initial release.

### Added

- `EnforceAppVersion` middleware (alias `app.version`): parses `X-App-Version`,
  `X-App-Build` and `X-App-Platform`, attaches the parsed `ClientVersion` to the
  request, answers `426 Upgrade Required` with the policy JSON below the minimum
  version, adds `Deprecation: @<unix-timestamp>` (RFC 9745, when `deprecated_at`
  is set), `Sunset` (RFC 8594, when `sunset` is set) and `Link ... rel="alternate"`
  (when `store_url` is set) below the recommended version, and adds
  `Vary: X-App-Version, X-App-Platform` to every response it shaped. Requests
  without the headers pass through unless strict mode is on; a malformed header
  (including a platform name that is not a short lower-case token) is a `400`
  whose message quotes at most 64 printable characters of the offending value.
- `GET /app/version-policy?platform=ios` endpoint with a configurable prefix,
  middleware list and `Cache-Control` max-age, named `app-version.policy`. The
  enforcing middleware is always excluded from it.
- Per-platform policies in `config/app-version.php` (`minimum`, `recommended`,
  `latest`, `store_url`, `deprecated_at`, `sunset`, `message`), each overridable
  from `.env`. A `sunset` earlier than `deprecated_at` is rejected. Dates without
  a time zone are read as UTC.
- `PolicyResolver` contract with `ConfigPolicyResolver` (default) and
  `CallablePolicyResolver`, plus `AppVersion::resolveUsing()` for policies read
  from a table or a remote configuration service.
- `Version` value object with semver precedence, including pre-release
  identifiers and build metadata, and `VersionPolicy` with the
  `minimum <= recommended <= latest` and `deprecated_at <= sunset` invariants
  enforced at construction.
- `Request::appVersion()` macro returning the parsed `ClientVersion` or null.
- `AppVersionSeen` event, opt-in via `app-version.usage.enabled`, with a
  cache-backed `CacheUsageRecorder` and the `app-version:report` command that
  summarises the versions seen per platform against the current policy.
- `app-version:policy` command that prints the policy currently being served.
- Log `Context` entries `app_version`, `app_build` and `app_platform` for every
  request that carried the headers.
- Support for PHP 8.2 to 8.4 with Laravel 12, and PHP 8.3 and 8.4 with Laravel 13.

[0.1.0]: https://github.com/puneetpals271/laravel-app-version/releases/tag/v0.1.0
