=== Activity Monitor ===
Contributors: rstibal
Tags: activity log, audit log, security, user activity, session management
Requires at least: 5.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete activity log for WordPress: track logins, content changes, plugin/theme updates, settings, and security-relevant events, with session management and scheduled email digests.

== Description ==

Activity Monitor is a WordPress audit log plugin that records what happens on your site and gives you the tools to act on it.

= What it tracks =

* User activity: logins, logouts, failed logins, registrations, profile updates, role changes, password resets
* Content: posts, pages, and custom post types (with before/after change details), media uploads and edits, comments, categories and tags, navigation menus, widgets
* Site management: plugin activation/deactivation/updates/deletion, theme switches and updates, Customizer saves, WordPress core updates
* Security: unauthorized access attempts to restricted admin pages, multisite site creation and deletion

= Noise control, not noise =

Repeated events (a burst of failed logins, rapid comment status churn) are automatically grouped into a single log entry with a repeat count, instead of flooding your log with duplicates. Every event is tagged with a severity level and an initiator (was this a logged-in user, an anonymous visitor, WP-Cron, WP-CLI, or WordPress itself?) so you can filter down to exactly what you're looking for.

= Session management =

See every active login session across your site, revoke individual sessions, set a limit on how many concurrent sessions a user can hold, or lock the site down immediately by terminating every session except your own.

= Stats, digests, and exports =

* A Stats & Insights screen shows activity trends, your busiest day and hour, top event types, and your most active users
* An optional scheduled email digest (daily, weekly, or monthly) summarizes recent activity and flags anything at warning level or above
* Export the log — filtered by date range, user, event type, or action — as CSV, JSON, HTML, or plain text

= Email alerts =

Configure one or more email channels, each with its own minimum severity threshold, to get notified the moment something notable happens.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/activity-monitor`, or install directly through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **Activity Monitor** in your WordPress admin menu to view the log and configure settings.

== Frequently Asked Questions ==

= Does this slow down my site? =

Activity Monitor only writes to its own database tables on the actions it tracks (an admin action, a login, a content change) — it does not run on every front-end page load for logged-out visitors beyond what's needed to record their own logged actions (e.g. leaving a comment).

= Where is session data stored? =

Active sessions are WordPress's own built-in session tokens — Activity Monitor reads and manages them directly rather than keeping a separate copy, so what you see always reflects the real, current session state.

= What happens to my data if I uninstall the plugin? =

Deleting the plugin through the Plugins screen removes its database tables, all stored settings, and any scheduled tasks. Simply deactivating the plugin does not delete anything, so you can safely reactivate later without losing your log.

= Can I control how long log entries are kept? =

Yes, retention is configurable and old entries are pruned automatically on a daily schedule.

== Screenshots ==

1. The Activity Log screen, with filtering by level, initiator, event type, user, and date range.
2. Stats & Insights: activity trends, busiest times, and top event types.
3. Active Sessions, with per-session revoke and site-wide emergency lockdown.

== Changelog ==

= 2.0.0 =
* Complete rewrite: new database schema (events + structured context, replacing a single wide table), a pluggable per-source logger architecture, and full event-type parity with the previous version.
* Added: occasion grouping (repeated events collapse into one entry with a count), severity levels, and initiator tagging (user / visitor / cron / WP-CLI / system) for every event.
* Added: configurable concurrent session limits and an emergency lockdown action.
* Added: Stats & Insights screen with activity trends, peak-activity detection, and top-event/user breakdowns.
* Added: scheduled email digest (daily/weekly/monthly) with in-browser preview and test send.
* Added: log export in CSV, JSON, HTML, and plain text, honoring the log screen's active filters.
* Removed: Slack notification channel (email channels remain and were rebuilt on the new severity scale).
* Fixed: email notifications, which had stopped firing during the rewrite, are now correctly wired to the new event pipeline.

= 1.4.0 =
* Security hardening pass, including IP-spoofing protection and an automated-context guard to reduce noise from scheduled/CLI-triggered events.

= 1.1.6 =
* Initial internal release.

== Upgrade Notice ==

= 2.0.0 =
Major rewrite with a new database schema. Existing log data is migrated automatically and non-destructively on activation; nothing is deleted. Slack notifications are removed — reconfigure any alerting using email channels.
