=== Activity Monitor ===
Contributors: rstibal
Tags: activity log, audit log, security, user activity, page traffic
Requires at least: 5.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.81
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
* Site health: PHP fatal errors, theme/plugin file editor use, maintenance mode, and failed outgoing email

= Noise control, not noise =

Repeated events (a burst of failed logins, rapid comment status churn) are automatically grouped into a single log entry with a repeat count, instead of flooding your log with duplicates. Every event is tagged with a severity level and an initiator (was this a logged-in user, an anonymous visitor, WP-Cron, WP-CLI, or WordPress itself?) so you can filter down to exactly what you're looking for.

= Session management =

See every active login session across your site, revoke individual sessions, set a limit on how many concurrent sessions a user can hold, or lock the site down immediately by terminating every session except your own.

= Page traffic =

Optional, self-hosted page-view tracking with no third-party service and no cookies: per-page views and unique visitors, a live feed of hits as they arrive, top pages, and a traffic-source breakdown (direct, search, social, referral, internal). Raw hits are rolled up daily and pruned on a retention schedule you set, so the tables stay small on busy sites.

= Dashboard, digests, and exports =

* A Dashboard shows totals and trends at a glance: daily activity stacked by severity, peak activity, pages needing attention, top pages, and where your traffic came from
* An optional scheduled email digest (daily, weekly, or monthly) summarizes recent activity and flags anything at warning level or above. Multiple digests can be configured independently, each with its own schedule and recipients
* Export the log — filtered by date range, user, event type, or action — as CSV, JSON, HTML, or plain text

= Alerts =

Configure one or more notification channels — email or Slack — each with its own minimum severity threshold, to get notified the moment something notable happens.

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

1. The Dashboard: daily activity by severity, peak activity, top pages, and traffic sources.
2. The Activity Log screen, with filtering by level, initiator, event type, and date range.
3. Page traffic, with a live feed of incoming hits.
4. Active Sessions, with per-session revoke and site-wide emergency lockdown.

== Changelog ==

= 2.0.81 =
* Removed: Multi-Site Monitoring settings and the Active Installs tab (the hub/reporter check-in feature added in 2.0.77). Existing installs have their am_installs table and related options cleaned up automatically on full uninstall. Being rethought for a future release.

= 2.0.80 =
* Changed: Slack alert notifications now read as one sentence — the event message is followed by "by {user} on {domain}" (or just "on {domain}" for system-initiated events with no logged-in user) instead of the bare message.

= 2.0.79 =
* Fixed: Added the missing `phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared` annotations to the Active Installs table's queries, matching the convention used elsewhere for plugin-constant table names.

= 2.0.78 =
* Changed: Active Installs tab — removed the per-row Remove action (a site simply reappears the next time it checks in, so removing it had no lasting effect), linked each Site URL to the actual site, and shortened the version column headers to Plugin/WordPress/PHP.

= 2.0.77 =
* Added: Active Installs tab — an opt-in hub feature where one site can collect periodic check-ins (site URL, plugin/WordPress/PHP versions, last check-in time) from other sites running the plugin. Configured under Settings → Multi-Site Monitoring; nothing reports anywhere unless explicitly turned on.

= 2.0.76 =
* Changed: Slack alert notifications now show just the event message, with "View full log" linked inline at the end, instead of a header/fields card.

= 2.0.75 =
* Changed: removed the poll-interval/feed-size explainer sentence above the Traffic tab's live feed.

= 2.0.74 =
* Fixed: the Active Sessions tab produced an "Undefined array key" warning for every row, and showed no name alongside the username. Sessions now show the user's real name where one is set, falling back to the username.

= 2.0.73 =
* Added: page traffic tracking — per-page views and unique visitors, a live feed, top pages, and a traffic-source breakdown (direct / search / social / referral / internal), with its own daily rollup and retention setting.
* Added: Dashboard replacing the Stats & Insights screen — daily activity stacked by severity level, peak activity, a "needs attention" count, top pages, and traffic sources.
* Added: new loggers for PHP fatal errors, theme/plugin file editor use, maintenance mode, and failed outgoing email (a wp_mail() failure is no longer invisible).
* Added: a Date & Time Display setting controlling how timestamps are shown throughout the plugin.
* Added: clickable usernames in the activity log, opening a profile card with the user's role, registration date, logged event count, and last activity.
* Added: an event Type column, and readable event names throughout — the filter now offers both whole categories and specific events.
* Restored: Slack notification channels, removed during the 2.0.0 rewrite.
* Changed: email digests can now be configured as multiple independent schedules rather than a single global one.
* Fixed: page view totals showed zero for today and yesterday, because the daily rollup only ever processes completed days.
* Fixed: filtering or exporting by an event type carried over from a pre-2.0 install matched nothing.
* Fixed: notification sends were never checked for failure.

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

= 2.0.73 =
Adds page traffic tracking (off until enabled in Settings) and replaces Stats & Insights with a Dashboard. Slack notification channels are available again.

= 2.0.0 =
Major rewrite with a new database schema. Existing log data is migrated automatically and non-destructively on activation; nothing is deleted. Slack notifications are removed — reconfigure any alerting using email channels.
