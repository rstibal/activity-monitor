# Activity Monitor

Comprehensive WordPress audit log plugin — tracks logins, logouts, post/media/user/plugin/theme/comment/settings changes, and security-relevant events.

Built for use on client sites (not distributed via the WordPress.org plugin directory).

## Features

- Single tabbed admin page (Activity Log, Active Sessions, Settings) under the Dashboard
- Active Sessions view reads from WordPress's native `session_tokens` user meta
- Custom `wp_am_activity_log` table for event storage
- Configurable log retention with daily pruning via WP-Cron
- Optional Slack webhook notifications
- Cloudflare-aware IP resolution (validates `CF-Connecting-IP` against verified Cloudflare CIDR ranges rather than trusting it blindly)

## Requirements

- WordPress 6.0+
- PHP 8.0+

## Installation

This plugin is not distributed as a live-editable directory. Deploy via **Plugins → Add New → Upload Plugin** using a versioned release ZIP.

## Deployment workflow

All edits are made locally and packaged as a versioned ZIP for manual upload — no server-side editing of plugin files. See commit history for the version-by-version changelog.

## Security

v1.3.0 introduced a full security patch set:

- IP spoofing protection via Cloudflare CIDR validation in `get_ip()`
- `ajax_session_detail` re-fetches session data from the database instead of trusting POST input
- Parameterized queries (`$wpdb->prepare()`) throughout
- Output escaping (`wp_strip_all_tags()`) on email notification bodies
- `uninstall.php` cleanly removes the log table, plugin options (including any stored Slack webhook), and scheduled cron events

## License

GPL-2.0+

Another edit
