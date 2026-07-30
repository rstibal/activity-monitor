=== Activity Monitor ===
Contributors: rstibal
Tags: activity log, audit log, security, user activity, session management
Requires at least: 5.3
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.2.2
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

Repeated events (a burst of failed logins, rapid comment status churn) are automatically grouped into a single log entry with a repeat count, instead of flooding your log with duplicates. Every event is tagged with a severity level and an initiator (was this a logged-in user, an anonymous visitor, WP-Cron, WP-CLI, an unattended auto-update, a REST API call, or WordPress itself?) so you can filter down to exactly what you're looking for.

= Session management =

See every active login session across your site, revoke individual sessions, set a limit on how many concurrent sessions a user can hold, or lock the site down immediately by terminating every session except your own.

= Digests and exports =

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

1. The Activity Log screen, with filtering by level, initiator, event type, and date range.
2. Active Sessions, with per-session revoke and site-wide emergency lockdown.

== Changelog ==

= 2.2.2 =
* Fixed: upgrading now clears leftover settings rows belonging to features that have been removed, instead of leaving them in the database until the plugin is deleted. Nothing you can see or configure changes.

= 2.2.1 =
* Changed: Activity Log, Active Sessions, and Settings are now three separate screens listed under Activity Monitor in the admin menu, instead of tabs on a single page. Each has its own address, so any of them can be bookmarked or linked to directly. Links into the Activity Log — including the ones in digest emails and Slack alerts — are unchanged.
* Added: Event Sources in Settings — a checkbox per event category (posts, users, plugins, and so on) to stop that category being recorded. Existing entries are kept and stay visible and exportable; only future logging is affected. The underlying setting has been supported since 2.0 but had no admin screen until now.
* Added: the Activity Log now shows a removable chip when it's filtered to a single user. That filter is set by the "View this user's activity" button in the profile popup, and previously nothing on screen indicated it was on.
* Removed: the "Active session threshold" setting, which had no effect. It was saved but never read — the Active Sessions tab has always derived a session's state from its real expiration time. WordPress's session tokens record only login and expiry, with no last-activity time, so the setting could not have worked as described.
* Removed: three unused internal report queries left over from the Dashboard tab, plus dead CSS.

= 2.2.0 =
* Removed: page traffic tracking, in full — the Traffic tab, its live feed and page-view detail popup, and the Page Traffic settings block. Activity Monitor is now focused solely on the audit log and session management.
* Important: upgrading to this version **permanently deletes all stored page-view data**. Both traffic database tables are dropped automatically the first time 2.2.0 loads, along with the traffic settings and the nightly rollup task. This cannot be undone — if you want to keep that history, export it from the database before updating.
* Fixed: uninstalling the plugin now removes the traffic tables, options, and scheduled task it previously left behind, so deleting Activity Monitor really does leave nothing behind.

= 2.1.1 =
* Changed: everywhere a WordPress user is shown (Activity Log, Traffic tab, Active Sessions, and Slack/email notifications) now displays the profile's display name alongside the username, instead of the first/last name fields (which are often left blank).

= 2.1.0 =
* Removed: the Dashboard tab. The Activity Log is now the first/default tab.
* Added: two new initiator categories on the Activity Log — Auto-Update (an unattended background plugin/theme/core update, distinct from other WP-Cron activity) and REST API (a REST request with no logged-in browser session behind it, e.g. an external integration or application password client; a REST call from your own logged-in session, such as the block editor, still reads as User).
* Changed: Traffic tab — removed the "Live traffic" section heading above the live feed table.

= 2.0.88 =
* Build: No plugin code changes. Hardens the release pipeline to fail the build if the packaged zip ever contains backslash-separated paths, which WordPress's uploader can extract as corrupted flat filenames instead of nested files.

= 2.0.87 =
* Fixed: Activity Log table and Recent notable events read stored UTC timestamps without a UTC suffix, so they could display shifted times depending on server timezone. Now parsed consistently with the rest of the plugin.
* Removed: Unused `.am-pie-solo` CSS rule.

= 2.0.86 =
* Changed: Activity Log tab -- removed the Filters toggle button; the filters are always visible again. Moved the Search box to the top row (left-aligned, next to the pagination), which now stacks under the pagination on narrow screens instead of crowding one row.

= 2.0.85 =
* Changed: Activity Log tab — removed the Total Events stats bar, added a right-aligned pagination row above the table (matching the one below it), and made the filters collapsible behind a Filters button in that same top row, collapsed by default.

= 2.0.84 =
* Removed: The Period selector and stats grid (Total page views / Views today) from the Traffic tab. The Live traffic feed is unchanged.

= 2.0.83 =
* Changed: A Slack alert's deep link now also highlights the event's row on the Activity Log tab, so it's still easy to spot after the Details modal it opens automatically is closed.

= 2.0.82 =
* Changed: Slack alert notifications now link the site's domain itself straight to that event's row on the Activity Log tab, which opens its Details modal automatically. Removed the separate "View full log" link.

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

= 2.2.0 =
Page traffic tracking is removed. Upgrading permanently deletes all stored page-view data — both traffic tables are dropped on first load and cannot be recovered. Export that history first if you need it. Your activity log and sessions are unaffected.

= 2.0.73 =
Adds page traffic tracking (off until enabled in Settings) and replaces Stats & Insights with a Dashboard. Slack notification channels are available again.

= 2.0.0 =
Major rewrite with a new database schema. Existing log data is migrated automatically and non-destructively on activation; nothing is deleted. Slack notifications are removed — reconfigure any alerting using email channels.
