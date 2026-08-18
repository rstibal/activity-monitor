# Activity Monitor

A complete WordPress activity log — tracks logins, content changes, plugin/theme/core updates, comments, taxonomy, menus, widgets, password changes, multisite events, and unauthorized access attempts.

Distributed on wordpress.org as a public plugin.

## Features

- Two admin screens under one menu (Activity Log, Settings)
- Two-table event schema (`am_events` + `am_event_context`) with occasion grouping (repeated events collapse into one entry with a count), PSR-3 severity levels, and initiator tagging (user / visitor / cron / WP-CLI / auto-update / REST API / system)
- Pluggable per-source logger architecture — one class per event type (posts, users, media, comments, plugins, themes, core, terms, menus, widgets, passwords, sites, security, fatal errors, file editor, maintenance mode, mail failures)
- Log export: CSV, JSON, HTML, and plain text, honoring the current filter set
- Email and Slack notification channels with configurable minimum severity
- Configurable date/time display format applied across every screen
- Configurable log retention with daily pruning via WP-Cron
- Cloudflare-aware IP resolution (validates `CF-Connecting-IP` against verified Cloudflare CIDR ranges rather than trusting it blindly)

## Requirements

- WordPress 5.3+
- PHP 7.4+

## Installation

Install via **Plugins → Add New → Upload Plugin**, or by uploading the `activity-monitor` folder to `/wp-content/plugins/`.

## Deployment workflow

All edits are made locally and packaged as a versioned ZIP for manual upload — no server-side editing of plugin files. See `readme.txt` for the wordpress.org-formatted changelog, and commit history for full version-by-version detail.

## Security

- IP spoofing protection via Cloudflare CIDR validation
- Parameterized queries (`$wpdb->prepare()`) throughout
- Output escaping on all email notification bodies
- `uninstall.php` cleanly removes all database tables, all plugin options, and all scheduled cron events

## License

GPL-2.0-or-later
