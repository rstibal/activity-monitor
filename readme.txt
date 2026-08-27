=== Activity Monitor ===
Contributors: rstibal
Tags: activity log, audit log, security, user activity, event log
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.8.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete activity log for WordPress: track logins, content changes, plugin/theme updates, settings, and security-relevant events, with alerts and export — plus real-time visitor/traffic stats.

== Description ==

Activity Monitor is a WordPress audit log plugin that records what happens on your site and gives you the tools to act on it.

= What it tracks =

* User activity: logins, logouts, failed logins, registrations, profile updates, role changes, password resets
* Content: posts, pages, and custom post types (with before/after change details), media uploads and edits, comments, categories and tags, navigation menus, widgets
* Site management: plugin activation/deactivation/updates/deletion, theme switches and updates, Customizer saves, WordPress core updates
* Security: unauthorized access attempts to restricted admin pages, multisite site creation and deletion
* Site health: PHP fatal errors, warnings, notices and deprecation notices, theme/plugin file editor use, maintenance mode, and failed outgoing email

= Noise control, not noise =

Repeated events (a burst of failed logins, rapid comment status churn) are automatically grouped into a single log entry with a repeat count, instead of flooding your log with duplicates. Every event is tagged with a severity level and an initiator (was this a logged-in user, an anonymous visitor, WP-Cron, WP-CLI, an unattended auto-update, a REST API call, or WordPress itself?) so you can filter down to exactly what you're looking for.

= Exports =

* Export the log — filtered by date range, user, event type, or action — as CSV, JSON, HTML, or plain text

= Alerts =

Configure one or more notification channels — email or Slack — each with its own minimum severity threshold, to get notified the moment something notable happens.

= Visitor Stats =

Real-time front-end traffic: visits, unique visitors, top pages, referrers, and a browser/OS/device breakdown, on its own screen kept separate from the activity log. No cookies, and no IP address is ever stored — visitors are identified only by a one-way hash that rotates daily. Tracking can be turned off, restricted by role, and given its own retention period under Settings → Visitor Stats.

Optionally, Settings → Visitor Stats → Geolocation adds a Country breakdown. This uses a database of IP ranges downloaded from MaxMind (GeoLite2) using your own free account and stored on your server — every lookup happens locally, so no visitor's IP address is ever sent anywhere. The only outbound traffic is the plugin periodically checking MaxMind for a newer database, using the account credentials you provide.

The Visitor Stats screen also includes a paginated Recent Hits table — every individual pageview in the selected range (page, browser, OS, device, and country if geolocation is on), newest first.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/activity-monitor`, or install directly through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to **Activity Monitor** in your WordPress admin menu to view the log and configure settings.

== Frequently Asked Questions ==

= Does this slow down my site? =

Activity Monitor only writes to its own database tables on the actions it tracks (an admin action, a login, a content change) — it does not run on every front-end page load for logged-out visitors beyond what's needed to record their own logged actions (e.g. leaving a comment).

= What happens to my data if I uninstall the plugin? =

By default, deleting the plugin through the Plugins screen removes its database tables, all stored settings, and any scheduled tasks. You can change that under Settings → When the plugin is deleted: untick the box and the log and its settings stay in the database, so reinstalling picks up where it left off. Simply deactivating the plugin never deletes anything either way.

= Can I control how long log entries are kept? =

Yes. Settings → Logging → "Keep entries for" sets the retention period, from 30 days up to 2 years, or Forever to never delete anything. Entries past the period are removed automatically once a day, and the setting tells you how many entries you currently have and how far back they reach.

= Does the plugin send anything to a third party? =

Only if you ask it to. Clicking an IP address in the log looks it up via ipinfo.io, which means sending that one address and nothing else; you can turn that off under Settings → Privacy. Slack alerts post to the webhook URL you configure. If you turn on Visitor Stats' optional Geolocation feature, the plugin periodically contacts MaxMind to download an updated IP-to-country database using your own account credentials — no visitor IP address or other site data is ever included in that request; every actual visitor lookup happens locally against the downloaded copy. Nothing else leaves your site.

= Can I stop the plugin recording IP addresses? =

Yes. Settings → Privacy offers full addresses, anonymised addresses (the last part masked, using WordPress's own anonymisation), or none at all. It applies to entries recorded from that point on.

== Screenshots ==

1. The Activity Log screen, with filtering by level, initiator, event type, and date range.

== Changelog ==

= 2.8.13 =
* Changed: removed the 60ch max-width from the Visitor Stats Title/Page cells (.am-stats-truncate). It was redundant once those cells switched to wrap+clamp -- table-layout:auto already gives the column whatever width the row's other (mostly nowrap) columns don't need, same as the Activity Log's Message column, which has never had a max-width of its own.

= 2.8.12 =
* Fixed: GeoLite2 "Update Now" reliably failed on a new install or right after entering a new MaxMind license key, with "Last import failed: Download failed: HTTP 401". MaxMind's redirect chain can bounce through more than one of their own hosts before finally handing off to storage, and each of those still expects the Basic Auth header -- the code was dropping it unconditionally after the very first redirect hop instead of only once the chain actually left maxmind.com, turning a hop that still required credentials into an unauthenticated one. The header is now forwarded for as long as the chain stays on a maxmind.com host. Failure messages also now include MaxMind's own error detail (e.g. "AUTHORIZATION_INVALID") instead of just the bare HTTP status, to make a genuinely wrong account ID/license key easier to tell apart from a request bug like this one.

= 2.8.11 =
* Fixed: a GeoLite2 "Update Now" import logged `PHP Warning: rmdir(...am-stats-geo-import): Directory not empty` even though the import itself completed successfully. The zip's wanted CSVs get extracted into a version-stamped subdirectory and then moved up to the working dir's root, leaving that now-empty subdirectory behind; cleanup only unlinked files at the top level and never removed it, so the final rmdir() of the working dir failed. Cleanup now recurses into subdirectories.

= 2.8.10 =
* Fixed: on the Visitor Stats screen's Top Pages table, Title and URL rendered stacked on top of each other with Visits/Unique Visitors shoved to the right, instead of four aligned columns. 2.8.9's wrap+clamp fix had applied `display: -webkit-box` directly to those `<td>` elements, which overrides `display: table-cell` and knocks the cell out of the table's row/column layout. The clamp now applies to the inner link instead, matching how the Activity Log's own Message column avoids the same trap.

= 2.8.9 =
* Fixed: the Title/Page columns on the Visitor Stats screen's Top Pages and Recent Hits tables stayed wide at narrow browser widths instead of compressing the way the Activity Log's Message column does. A nowrap cell's minimum width under table-layout:auto is its full unbroken text, so the browser kept the column at that width and let it scroll horizontally rather than shrink it. These columns now wrap and clamp to 2 lines instead, matching the Message column's technique, so they compress with the available space.

= 2.8.8 =
* Changed: the Title/Page columns on the Visitor Stats screen's Top Pages and Recent Hits tables truncate at a more generous width (60ch instead of 320px) before showing an ellipsis, and the cap now lives on the table cell itself — the same technique the Activity Log's IP column uses — instead of an inner span.

= 2.8.7 =
* Changed: on the Visitor Stats screen, the Recent Hits and Top Pages tables now show each page title stripped of its trailing " » Site Name" suffix, and the title/URL cells link to the live page in a new tab. The stored title itself is unchanged.

= 2.8.6 =
* Changed: the Slack alert now includes the event's IP address ("... on injurylawyers.com from 203.0.113.4.") and identifies the acting user by their username alone instead of "Display Name (username)" — display name was often left at its default of matching the username, which made that clause redundant.

= 2.8.5 =
* Changed: the "Update Now" cooldown under Settings → Visitor Stats → Geolocation is now 5 minutes instead of 1 hour, and the "please wait" message says how much longer. The 1-hour version reset on every click regardless of whether the import actually succeeded, which made a genuine failure lock the button out for far longer than intended.

= 2.8.4 =
* Fixed: the GeoLite2 database import failed with "Download failed: HTTP 400". MaxMind's download endpoint redirects to a presigned Cloudflare storage URL; WordPress's HTTP API forwards the same Basic Auth header across that redirect by default, and the storage backend rejects a presigned URL that also carries an unexpected Authorization header. The redirect is now followed manually, with the auth header dropped once the request leaves MaxMind's own domain.

= 2.8.3 =
* Fixed: a successful "Update Now" click showed a red error notice reading just "1" instead of the intended success message. The click handler stored a PHP `true` in a transient to signal success, but WordPress's options storage stringifies scalars, so it came back as the string "1" and failed a strict boolean check on the way back out — read as an error instead of success. The GeoLite2 import itself was never affected; only the notice was wrong.

= 2.8.2 =
* Fixed: the "Update Now" button under Settings → Visitor Stats → Geolocation actually triggers the GeoLite2 import now. It was rendered as a `<form>` nested inside the Settings screen's own save form, which is invalid HTML — browsers collapse a nested form into the outer one, so clicking it saved settings and reloaded the page instead of starting the import. It's now its own form, same pattern the Clear Log button already used correctly.

= 2.8.1 =
* Changed: Visitor Stats screen layout — Recent Hits moved above Top Pages and reduced to 10 rows per page (was 50), Referrers and Countries now sit side by side in one row, Browsers/Operating Systems/Devices in the row below that.

= 2.8.0 =
* Added: a Recent Hits table on the Visitor Stats screen — every individual pageview in the selected range, newest first, paginated the same way the Activity Log paginates.

= 2.7.0 =
* Added: optional country-level geolocation for Visitor Stats. Settings → Visitor Stats → Geolocation, using a free MaxMind account — the IP-range database downloads to your own server and every visitor lookup happens locally there; no visitor IP address is ever sent to MaxMind or anyone else. The database updates automatically in the background (twice weekly, matching MaxMind's own release schedule) once configured, with a manual "Update Now" option.

= 2.6.0 =
* Added: Visitor Stats, a new screen showing real-time front-end traffic — visits, unique visitors, top pages, referrers, and a browser/OS/device breakdown — in its own tables, entirely separate from the activity log. No cookies are set and no IP address is ever stored; visitors are identified only by a one-way hash that rotates daily. Configurable under Settings → Visitor Stats: turn tracking off, exclude specific roles (administrators are excluded by default), and set its own retention period independent of the activity log's. Geolocation (visits by country) is not included in this release.

= 2.5.0 =
* Removed: the scheduled email digest, in full — the Settings screen's Email Digests list plus its Preview and test send controls, the underlying AM_Digest class, and the daily cron tick that sent it. Activity Monitor is now purely an activity log, with alerts and export.
* Fixed: the "Clear Entire Log" button on Settings is now actually red. Its danger-button CSS rule was a single class, which core's own `.wp-core-ui .button` rule (two classes) always outranked regardless of stylesheet order, so the red never painted.

= 2.4.12 =
* Changed: the event Details popup's "User" row is now "Username" and shows just the login name, linked to the same User Details popup the Username column opens, instead of stacking the display name and login.

= 2.4.11 =
* Changed: the Activity Log's Username cell is now blank rather than an em dash for events with no logged-in user, such as an anonymous failed login.
* Removed: the "Event sources" checkboxes on Settings, which let you stop recording an entire category (posts, users, plugins, and so on). Every event source is now always recorded; nothing else on that screen changed.

= 2.4.10 =
* Changed: the Activity Log and Settings screens are both headed "Activity Monitor" instead of repeating the screen name, and the left-hand menu no longer lists "Activity Log" as its own item — the top-level "Activity Monitor" link still goes straight to the log, Settings is unchanged.
* Changed: the log table's "User" column is now "Username" and shows the login name instead of the display name, matching what the column is actually named. The Details popup for an event still shows both.

= 2.4.9 =
* Changed: the user details popup is tidier. It's now titled "User Details" rather than repeating the person's name, the avatar-and-name banner across the top is gone, and the display name is a labelled row in the table with everything else — so every field about the user reads the same way instead of one of them being a heading.

= 2.4.8 =
* Changed: the scheduled email digest now counts every event, including PHP errors. 2.4.7 left them out of its totals and its "notable events" list, which made the digest the one place in the plugin where some entries silently didn't count — a number you couldn't reconcile against the log it links to. If PHP errors crowd out your digest, turn that event source off under Settings → Event Sources, or fix whatever is emitting them; either way you can see what you changed and what it did.

= 2.4.7 =
* Changed: the Debug Log screen added in 2.4.5 is gone, and everything it showed is back on the Activity Log. It was the same table with a fixed filter over it, and keeping two screens meant two places to look for one answer. To get the old view, pick "System" from the event type dropdown — PHP errors, warnings and notices now appear there alongside every other type.
* Changed: the severity links across the top (All, Debug, Info, Notice…) now list only the levels your log actually contains, with a count on each, and carry your other filters with them instead of clearing them. Previously all eight were shown on every site, so most of them led to an empty table.
* Changed: the scheduled email digest still leaves PHP errors out of its totals and its "notable events" list. This is now the only place they're treated differently, and it's deliberate: a single repetitive warning could otherwise fill that list and push the security events it exists to surface off the bottom.
* Nothing was deleted from your log, and no entry became unreachable — this only changes where things are shown.

= 2.4.6 =
* Fixed: PHP warnings that the code deliberately silences with the `@` operator are no longer recorded. WordPress itself silences a great many of these on purpose — a file that might not exist, an image that might not be readable — and logging them filled the Debug Log with warnings from code working exactly as intended. The new behaviour also respects whatever error reporting level your host has configured.
* Fixed: a warning raised while the plugin was in the middle of recording a warning could send it into an endless loop, ending in a crashed page request. Recording is now skipped for the duration of a write, so a warning from inside the logger can't retrigger it.
* Fixed: a warning firing thousands of times in a single page load no longer costs a pair of database queries every time. The same warning is now recorded once per page load; repeats across separate requests still collapse into one row as before.

= 2.4.5 =
* New Debug Log screen, alongside Activity Log and Settings: a filtered view of core/plugin/theme updates and PHP errors/warnings, separate from the day-to-day audit trail.
* New: PHP warnings, notices, and deprecation notices are now recorded (previously only fatal errors were). Repeats of the same warning group into one row rather than flooding the log; turn this event source off entirely from Settings → Event Sources if you'd rather not track it.

= 2.4.4 =
* Housekeeping only — nothing about how the plugin behaves has changed. The code now passes the project's WordPress coding-standards check cleanly: a number of the annotations marking already-reviewed database queries had drifted onto the wrong line and were silently doing nothing, so the check had stopped being a useful signal.
* Removed a table of per-event default severity levels that could never match anything it was looked up with, so it had silently done nothing since it was written. The levels you see in the log have always come from the individual event sources, and still do.
* Removed two unused stylesheet rules, and corrected a batch of code comments that described how the plugin worked several versions ago.

= 2.4.3 =
* Changed: Activity Monitor now requires WordPress 6.0 or later, up from 5.3. WordPress 5.3 was released in 2019 and this plugin was no longer tested against it. If your site is on an older version, update WordPress first — this plugin's requirements are unchanged in every other respect, including PHP 7.4.
* Changed: the Settings screen has been reorganised. Everything you can set is now in one form — Logging, Display, Privacy, and what happens when the plugin is deleted — with a single Save Changes button, instead of three separate save buttons scattered down the page. Notification channels and email digests keep saving as you add or edit them, and now sit below that form so it's clear which is which. Clearing the log moved to the bottom, where a destructive action belongs.
* Changed: the screen is now built from WordPress's own settings furniture, so it matches Settings → General rather than looking like a separate product embedded in the admin.
* Added: a retention setting — how long entries are kept, from 30 days to 2 years, or forever. Old entries have always been deleted automatically after 90 days; that was never adjustable, and now it is. The setting shows how many entries you have, how far back they reach, and when the next cleanup runs.
* Added: privacy settings for IP addresses. Store them in full as before, store an anonymised version, or don't store them at all. IP address lookups (which send the address you click to ipinfo.io) can also be turned off, and addresses then show as plain text.
* Added: a choice about what happens when the plugin is deleted. Deleting still removes everything by default, but you can now keep your log and settings instead.
* Added: a setting for repeat-event grouping. Identical repeated events collapse into one entry with a count — you can now change that window from five minutes to anything between one minute and an hour, or turn grouping off entirely.
* Added: "Entries per page" for the Activity Log, in the Screen Options panel at the top of that screen. It was fixed at 50, and it's per-person, so changing it doesn't affect other administrators.

= 2.4.2 =
* Fixed: the Activity Log table stopped short of the full page width, because the filter toolbar above it was taller than the height WordPress reserves for one and the table was flowing around it.
* Changed: the User column now shows just the display name. The username moved into the profile popup as its own Username row, under User ID — it was repeating on every row and taking width from the message.

= 2.4.1 =
* Fixed: the Details popup on the Activity Log opened and then immediately closed again, making event details impossible to read. Introduced in 2.3.0, when the table moved inside the filter form and the Details button began submitting it.

= 2.4.0 =
* Removed: session management, in full — the Active Sessions screen, per-session revoke, the concurrent-session limit, Revoke All Expired Sessions, and Emergency Lockdown. Activity Monitor is now purely an activity log, with alerts, digests, and export.
* Your logins are not affected. Sessions belong to WordPress itself, and this only removes the plugin's screen and controls for them — nobody is logged out by this upgrade, and WordPress continues to manage sessions exactly as it always has. Session entries already in your activity log are kept and still display normally.

= 2.3.0 =
* Changed: the admin screens now use WordPress's own styling instead of a custom look. The white panel that wrapped each screen is gone, so sections and tables sit on the standard gray background and read the same way the Plugins and Posts screens do.
* Changed: the log and session tables are now standard WordPress list tables, with the same fonts, spacing, row striping, and headings used elsewhere in the admin.
* Changed: the search box and filter dropdowns moved to their standard positions — search above the table on the right, filters in the toolbar on the left with a Filter button, matching the Plugins screen.
* Changed: the severity filter is now a row of status links above the table, the same control the Plugins screen uses for All / Active / Inactive. Severity colours are unchanged in the table's own Level column.
* Changed: pagination is simplified to WordPress's standard style.

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

= 2.4.0 =
Session management is removed — the Active Sessions screen, session revoke, the concurrent-session limit, and Emergency Lockdown all go. Nobody is logged out by this upgrade: sessions belong to WordPress, and it keeps managing them as before. Your activity log is unaffected.

= 2.2.0 =
Page traffic tracking is removed. Upgrading permanently deletes all stored page-view data — both traffic tables are dropped on first load and cannot be recovered. Export that history first if you need it. Your activity log and sessions are unaffected.

= 2.0.73 =
Adds page traffic tracking (off until enabled in Settings) and replaces Stats & Insights with a Dashboard. Slack notification channels are available again.

= 2.0.0 =
Major rewrite with a new database schema. Existing log data is migrated automatically and non-destructively on activation; nothing is deleted. Slack notifications are removed — reconfigure any alerting using email channels.
