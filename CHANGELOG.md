# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.5.6]

### Fixed

- A gated site could still be indexed: on `template_redirect` Bouncer swallowed WordPress's `do_robots()`, so `/robots.txt` returned the HTML placeholder (indexable, HTTP 200 in Coming Soon mode) and the site had no valid robots.txt. Bouncer now serves a real `robots.txt` (`User-agent: * / Disallow: /`, `text/plain`) while the gate is on.

### Added

- Every gated response now carries an `X-Robots-Tag: noindex, nofollow` header (both modes), and the Bouncer page includes a `<meta name="robots" content="noindex, nofollow">` tag.

### Changed

- Expanded the inline help text on the settings page (Enable, Mode, Retry After, Allowed IPs, Heading, Main Text, Contact Email, Display options).
- When Bouncer is disabled it does not touch `is_robots()` or send any headers — `robots.txt` and SEO are left entirely to WordPress / the site's SEO plugin.

## [2.5.5]

### Changed

- Contact email on the Bouncer page is now obfuscated in the HTML source (reversed + base64 in a data attribute, rehydrated to a `mailto:` link by an inline script) so address-harvesting spam bots can't scrape it. No-JS visitors get a readable `user [at] host` fallback.

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
