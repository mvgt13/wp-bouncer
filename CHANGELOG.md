# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.5.4]

### Added

- Initial public release of WP Bouncer.
- Maintenance Mode and Coming Soon modes with correct HTTP `503` and `200` responses.
- Configurable `Retry-After` header support for maintenance mode.
- Logged-in user bypass so site administrators can access the live site while Bouncer is enabled.
- IP allowlist support for trusted visitors.
- Named preview links with per-link expiry, activation state, usage counts, and last-used timestamps.
- Per-link management actions to create, copy, deactivate, and delete preview links.
- Admin bar status indicator with one-click enable and disable controls.
- Configurable visitor-facing content including heading, main text, optional secondary text, and contact email.
- Optional dark mode toggle and external website button on the Bouncer page.
- REST endpoint for deleting all revisions for a post.
- Legacy single-token migration into the current multi-token preview-link system.
