# WP Bouncer

A WordPress plugin for maintenance mode and coming soon pages, with IP/token bypass, proper HTTP status codes, and configurable branding.

## Features

- **Two modes** selectable from the admin panel:
  - **Maintenance Mode** — HTTP 503 with `Retry-After` header; search engines keep existing pages and retry later
  - **Coming Soon** — HTTP 200; the placeholder loads as a normal page
- **Not indexable while gated** — in both modes Bouncer serves a strict `robots.txt` (`Disallow: /`), sends `X-Robots-Tag: noindex, nofollow` on every response, and adds a `noindex` meta tag. When Bouncer is off it leaves `robots.txt` and SEO entirely to WordPress / your SEO plugin.
- Logged-in users always see the live site
- **IP allowlist** — specific IPs always bypass the bouncer
- **Named preview links** — create one link per person or team so you know exactly who has access
  - Each link has a name (e.g. "Acme Corp"), independent expiry, open count, and last-opened timestamp
  - Configurable duration per-site: 1h / 6h / 24h / 3d / 7d / 30d / No expiry
  - **Deactivate** kills access immediately while keeping the link in the list for your records
  - **Delete** removes the link permanently
- Admin bar indicator shows current status with one-click toggle
- Configurable `Retry-After` interval

## Installation

1. Copy the `bouncer/` folder into `wp-content/plugins/`
2. Activate from **Plugins** in the WordPress admin
3. Configure under **Settings → Bouncer**

## Settings

### Status
| Option | Description |
|---|---|
| Enable Bouncer | Gate the front end. While on, only logged-in users, Allowed IPs, and preview-link holders reach the live site; a strict `robots.txt` + `noindex` headers are served |
| Mode | Maintenance (503, for an existing indexed site that's temporarily down) or Coming Soon (200, for a site not launched yet). Neither is indexable while gated |
| Retry After | `Retry-After` value on the 503 — hint for how long engines should wait before rechecking; maintenance mode only |

### Access Control
| Option | Description |
|---|---|
| New Link Duration | How long newly created links stay active: 1h, 6h, 24h, 3d, 7d, 30d, or No expiry. Does not affect existing links. |
| Preview Links | Table of all named preview links. Create, copy, deactivate, or delete individual links. |
| Allowed IPs | One IP per line — those visitors always see the live site regardless of Bouncer status |

**Per-link status:**
- **Active** (green) — link is valid and has been opened at least once
- **Active — not opened yet** (grey) — link is valid but no one has visited yet
- **Expired** (yellow) — link passed its expiry date naturally
- **Deactivated** (grey) — link was manually deactivated by an admin

**Notes:**
- **Deactivate** blocks access immediately; the row stays visible so you have a record of who had access.
- **Delete** permanently removes the link and all its stats.
- "No expiry" links issue browser cookies capped at 14 days for security. The server-side link remains valid indefinitely until deactivated or deleted.
- The open counter only increments on a visitor's first arrival (without an existing valid cookie), so page refreshes don't inflate the count.

### Content
| Option | Description |
|---|---|
| Heading | Large display text |
| Secondary Text | Toggle to show a second text block below the main text — useful for bilingual sites, simplified summaries, or any additional copy |
| Main Text | Body copy shown to all visitors |
| Secondary Text | Shown below the main text when enabled |
| Contact Email | Linked in the page copy; obfuscated in the HTML source to deter spam harvesters; leave blank to omit |

### Display
| Option | Description |
|---|---|
| Dark Mode Toggle | Show a dark/light mode switch button |
| Website Button | Show a link button to an external URL |
| Website URL | Target URL for the website button |

## Files

```
bouncer/
  bouncer.php            # Plugin entry point, admin UI, bypass logic
  bouncer-template.php   # Frontend page template
```
