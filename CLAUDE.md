# Activity Monitor — project notes

Custom WordPress plugin: audit logging, with alerts and export.
Not distributed via wordpress.org — released as a GitHub zip instead (see
`.github/workflows/release.yml`). Still held to wordpress.org's plugin
standards (readme.txt format, WordPress-Extra/PHPCompatibilityWP coding
standards via `phpcs`) regardless, since those are good discipline
independent of where the plugin ships.

**The repo root is the plugin root.** `.github/workflows/claude.yml` and
`.gitignore` also live here and are *not* part of the distributable plugin —
never sync by clearing the tree and dropping in a plugin folder, or the CI
workflow disappears.

## Conventions

- **Versioning.** Plain patch increments, no prerelease tags. Any change to
  package contents bumps the version in *three* places: the header comment in
  `activity-monitor.php`, the `AM_VERSION` constant just below it, and
  `Stable tag:` in `readme.txt`. They must agree.
- **PHP 7.4 is the floor** (`Requires PHP: 7.4`) — no `match`, `?->`,
  `str_contains()`, named args, enums, or constructor promotion. A modern PHP
  binary won't catch these (see Verifying below). Raising it was considered
  and declined: the gain is ~6 `strpos()` calls and 5 `switch` blocks
  (cosmetic), while PHP version is the host's decision, not the user's, and a
  higher floor blocks *updates* for sites already below it — stranding
  exactly the neglected installs an audit log is most useful on.
- **WordPress 6.0 is the floor** (`Requires at least: 6.0`), raised from 5.3
  in 2.4.3. Opposite calculus from PHP: a core-version bump is one click,
  auto-updates are on by default, and 5.3 (Nov 2019) was a claim nothing here
  had ever tested. It paid for itself immediately by deleting a
  `version_compare()` around the screen-option save hook. Keep the floor at
  something actually exercised; `Tested up to:` should track it.
- **`<code>` is only for actual code** (HTML, JS, SQL). Never for data values
  — IPs, URLs, slugs, IDs, hashes render as plain text. No CSS override of
  core's grey `<code>` background; real code wants it.
- **Anywhere a WordPress user is shown, use only `display_name` and
  `user_login`** — never first/last name (`AM_Admin::real_name()` was
  removed; those fields are often blank). Current behavior by location:
  - **Activity Log Username column**: `user_login` alone (since 2.4.10; was
    `display_name` through 2.4.9), linking to the profile modal.
  - **Profile modal**: titled flatly "User Details", no avatar/name header
    (dropped alongside 2.4.9's header) — `display_name` therefore needs its
    own row. Both fields get one each, **Username** then **Display Name**,
    under User ID.
  - **Event detail modal**: Username row shows `user_login` alone (since
    2.4.12; before that it stacked `display_name` bold over `user_login`,
    which was the one place inconsistent with the column above).
  - **Single-line contexts** (email): `"display_name (user_login)"`.
  - **Slack alerts** (2.8.6+): `user_login` alone — dropped per Rob's
    request, since a display name matching the login read as redundant
    ("rstibal (rstibal)"). See `AM_Notifications::send_slack()`.

  `display_name` is read live via `get_userdata()`/`WP_User` where the user
  still exists, else from the `am_events.user_display_name` snapshot column
  (written alongside `user_login` at write time in `AM_Event_Writer::log()`,
  backfilled for migrated v1.x rows in `AM_Schema::migrate_legacy_row()`).
  Rows written before that column existed default to `''` — always fall back
  to `user_login` when empty.
- **Timestamps** go through `AM_Date_Format::combined()`. CSV/JSON export
  deliberately bypasses it, keeping raw UTC to stay machine-readable.
- **"Ledger Console" is the plugin's visual identity (2.9.0).** Reverses the
  prior rule (2.3.0–2.8.x: "build on wp-admin's own furniture, don't restyle
  it," adopted after an earlier restyle of buttons/inputs/tables made the
  plugin read as a separate product bolted onto wp-admin). 2.9.0 is a
  deliberate design system instead of one-off decoration: a case-file-ledger
  look (fitting for a security audit log) with its own type system (IBM Plex
  Sans for headings/chrome, Public Sans for body/table text, IBM Plex Mono
  for data — timestamps, IPs, versions — via Google Fonts in
  `enqueue_assets()`), an 8-step severity color ramp (gray → blue → teal →
  amber → orange → red → magenta → wine, debug through emergency, so
  severity reads as a position on a scale), and a per-user light/dark toggle
  (`am_theme` usermeta via `AM_Admin::user_theme()`/`ajax_save_theme()`, same
  storage pattern as `am_log_per_page` below). The old rule's real lesson
  still holds and is what keeps this from repeating 2.3.0's mistake: **stay
  scoped**. Every `admin.css` rule lives under `.am-wrap` — the wp-admin bar,
  left-hand menu, and other plugins' screens are untouched. Within that
  scope, restyling core's own furniture (buttons, inputs, `.tablenav-pages`,
  `.form-table`) is now the deliberate choice.

  Every Settings section renders inside a real `.am-card` div:
  `AM_Admin::render_settings_sections()` is a drop-in replacement for core's
  `do_settings_sections()`, opening a card before each section and closing
  it after, while still reading the same `$wp_settings_sections`/
  `$wp_settings_fields` globals and calling the section's own callback plus
  core's `do_settings_fields()` — registration (`register_sections_and_fields()`)
  is unchanged. (2.9.0 first did this as pure CSS sibling-selectors over
  core's unwrapped output; 2.9.4 replaced it with real containers once real
  containers were wanted throughout, matching Notification Channels/Clear
  Log below the form, which were always real `.am-card` divs.) Visitor
  Stats' breakdown tables (Referrers, Countries, Browsers, OS, Devices) got
  a "Share" column with a bar sized relative to the page's largest value
  (`AM_Admin::bar_cell()`/`max_visits()`).

  **Dark-mode bug pattern: the theme toggle needs an explicit rule on every
  core element it has to reach, not just a token redefinition on
  `.am-wrap`.** CSS inheritance only fills a value when nothing else in the
  cascade specifies one for that element — an ancestor's `color:
  var(--am-ink)` never reaches a `<td>` core already colors explicitly,
  however the token is redefined under `[data-am-theme="dark"]`. Four
  instances found so far, each with its own wrinkle:
  - **2.9.1** — `.wp-list-table` (Activity Log/Visitor Stats/Notification
    Channels tables) and `.form-table`/`.description` (Settings) stayed
    light: core sets explicit colors the toggle never targeted. Fixed with
    explicit `var(--am-*)` rules on those elements too. *If a core element
    looks unchanged after toggling, check whether the rule targeting it uses
    a token at all — not whether the token itself is defined for dark.*
  - **2.9.2a** — tokens were declared on `.am-wrap[data-am-theme="dark"]`,
    so ancestor chrome (`#wpcontent`/`#wpbody`/`#wpbody-content`, which
    *wraps* `.am-wrap` rather than sitting inside it) had no way to read
    them, leaving a light halo around an otherwise-dark screen — custom
    properties only cascade down, never up. Fixed by hoisting the token set
    to `body.wp-admin` (light) / `body.wp-admin.am-theme-dark` (dark).
    `AM_Admin::filter_admin_body_class()` sets the body class from
    `user_theme()` on full page loads; the toggle's JS also sets it
    (`$('body').toggleClass('am-theme-dark', …)`), alongside the
    `data-am-theme` attribute it already set on `.am-wrap` (still needed —
    hardcoded per-severity dark colors key off that attribute, not a token).
    The two are kept in sync in exactly one place: the click handler.
  - **2.9.2b** — a `<select>`'s open dropdown list is a native OS popup, not
    a styled child of the box, so coloring the closed `<select>` doesn't
    reliably reach it; some browser/OS combos kept the open list white,
    making dark-toned text unreadable. Fixed with an explicit `.am-wrap
    select option { background; color; }` rule on every dropdown.
  - **2.9.3** — a `<select>` stayed unreadable right after picking a new
    option, until it lost focus. The browser repaints a select's own
    chrome/focus-ring using system colors it assumes are light, regardless
    of our `background`/`color` — the same assumption `color-scheme`
    overrides, and the only fix that could reach that repaint. Fixed with
    `--am-color-scheme` (`light`/`dark`, alongside the other token pairs)
    applied as `color-scheme: var(--am-color-scheme)` on `.am-wrap`, which
    inherits to every control inside it. *If a third form-control quirk like
    this turns up (a checkbox/radio's native tick, a native
    `<input type="date">` picker), check `color-scheme` before reaching for
    more color rules — it's the general fix for "the browser draws this
    itself and doesn't know it should be dark."*

  **2.9.5 — a self-inflicted regression from the 2.9.1 fix, not a new
  browser quirk.** `.am-log-table td { border-color: var(--am-border)
  !important; }` (added for 2.9.1's grid lines) is a *shorthand* that also
  sets `border-left-color` — exactly what the per-row severity spine
  (`.am-row-am-<level> td:first-child { border-left: 3px solid <color>; }`)
  sets on the same cell. `!important` beats a non-`!important` declaration
  regardless of selector specificity, so every row's spine silently
  flattened to the neutral gray border, no matter its level. Fixed by
  narrowing the grid-line rule to `border-top/right/bottom-color`, leaving
  `border-left-color` to the rule that already owned it. **Generalize:
  before reaching for `!important` on a shorthand (`border-color`,
  `background`, `font`, `margin`), check whether any longhand side of it is
  already independently owned by a more specific rule elsewhere — the
  shorthand wins that side too, `!important` or not, and silently.**
- **Dead code gets deleted, not commented out or marked unused.**

## Architecture

Core: `AM_Schema`, `AM_Event_Writer`, `AM_Event_Query`, `AM_Log_Levels`
(8 PSR-3 levels), `AM_Initiator_Detector`, `AM_Logger_Manager` plus one
`AM_Logger_*` per domain, `AM_Event_Labels`, `AM_Date_Format`.

Admin screens: two submenu pages under one top-level menu — Activity Log
(`activity-monitor`, the default) and Settings (`activity-monitor-settings`).
The tabbed single page they replaced went in 2.2.1; `am_tab` no longer means
anything and old links carrying it just land on the log.

**There was briefly a third screen, and folding it back is the lesson.**
2.4.5 added a Debug Log (`activity-monitor-debug`) for system/technical
events; 2.4.7 deleted it. It was never a different view — same table, same
`render_event_row()`, same modal, same form wiring, differing only in its
`WHERE`. Measured before removal: `get_debug_events()` was 69 lines against
`get_events()`'s 94, ~114 of which were byte-identical, and
`render_debug_screen()` was a 117-line strict subset of the 335-line
`render_log_screen()`. Worse, 2.4.7 had briefly defined the split in *two
opposite directions* — the screen included `event_type = 'system'` (any
action) while the log excluded four *named* actions — so a new
`system.php_*` event would have appeared on both until someone updated the
second list. **If a proposed screen is the existing one with a fixed filter
over it, it's a filter, not a screen.** Ship it as a dropdown option.

**Nothing is filtered out of anything any more.** No query in
`AM_Event_Query` excludes an event type — the screen and the export both see
the whole table. This was tested hardest by the email digest (removed
entirely in 2.5.0): 2.4.7 kept a `PHP_ERROR_ACTIONS` exclusion on its
`get_notable_events()` query alone (it selected WARNING-and-above, and
`php_warning` is WARNING while `fatal_error` is ERROR, so one repetitive
warning could fill all ten slots); 2.4.8 deleted it, because it made the
digest the one place where some rows silently didn't count, invisible from
the UI and irreconcilable against the screen it linked to. **Volume is a
settings problem, not a query problem** — `am_occasion_window_seconds`
collapses repeats. A hidden `WHERE` is neither. (A per-logger Event Sources
toggle used to sit alongside that; removed in 2.4.11 — see below.)

**`get_events()` and `get_level_counts()` share `build_where()`**, which
takes a `$skip` list; the counts query passes `array( 'level' )`, since a
tally *per level* has to run across everything the other filters allow. Keep
new filters in `build_where()` so both stay in step.

Session management (the Active Sessions screen, per-session revoke, the
concurrent-session limit, Revoke Expired, Emergency Lockdown, `AM_Sessions`
itself) was removed in 2.4.0. Sessions live in WordPress's own
`session_tokens` user meta, which this plugin never owned — so cleanup drops
only the `am_session_concurrent_limit` option and **must never touch
`session_tokens`**, which would log out every user on the site. The
`session.*` entries in `AM_Event_Labels` are deliberately kept: upgraded
sites still have those rows in `am_events` and they have to keep rendering.

**The log keeps the bare `activity-monitor` slug** because the plain-text
alert email links to it. Don't rename it.

`AM_Admin::$screen_hooks` collects the return values of `add_menu_page()` /
`add_submenu_page()`, and both `enqueue_assets()` and `show_notices()` test
membership against it. Never hardcode a hook string like
`toplevel_page_activity-monitor` — WordPress builds a submenu's hook from the
sanitized *parent menu slug*, so a hand-written literal can be wrong in a way
that fails silently: assets simply never enqueue on that screen.

**On the Activity Log, the table sits inside `<form id="am-filter-form">`**
— the filter/search controls and the rows are one form, matching core's
list-table layout. So every `<button>` in a row needs an explicit
`type="button"`: the HTML default is `type="submit"`, which submits the
filter form and reloads the page. That's what broke the Details modal in
2.3.0 (it opened, then the reload wiped it). Row-level click handlers should
also call `preventDefault()`.

Each screen renders through `render_page_*()` → `render_screen_open()` → its
`render_*_screen()` body → `render_screen_close()`. The close helper emits
the shared modal overlay both screens need — Settings opens modals into it
too.

**Settings runs on the Settings API** as of 2.4.3: one option group
(`AM_Admin::SETTINGS_GROUP`), four sections, one `options.php` POST, one Save
Changes button, and core's own "Settings saved." notice — which is why
`render_settings_screen()` calls `settings_errors()` itself (a custom
top-level menu doesn't get core's automatic call). Every scalar option is
registered with a `sanitize_callback`, so adding one means
`register_options()` + `register_sections_and_fields()` + a `field_*()`
renderer and nothing else: no handler, no nonce, no redirect, no notice.
Before this there were three `admin_post_` handlers with three redirects and
three custom notices; if you find yourself adding a fourth, you want a
settings field instead.

**One thing on that screen is deliberately not a settings field.**
Notification channels are a *list of records*, added and edited through
modals that save over AJAX immediately, so it renders *below* the Save
button — everything above it is a field, everything below carries its own
control. Clear Log stays an `admin_post` action for the same reason, and
sits last because it's destructive. Don't fold either into the form.

**The scheduled email digest was removed entirely in 2.5.0** — `AM_Digest`,
its Settings UI (config list, Preview and test-send controls), the AJAX
handlers, and the daily `am_send_digest` cron tick. It was the digest
configs' list-of-records entry that used to sit alongside notification
channels above. `AM_Event_Query`'s three period-summary queries
(`get_totals_for_period()`, `get_breakdown_by_event_type()`,
`get_notable_events()`) went with it — the digest was their only caller.
Options are cleaned up in `am_run_upgrade_cleanup()`'s 2.5.0 block and were
already listed in `uninstall.php`.

**The per-logger Event Sources toggle was removed in 2.4.11.** Every
registered logger's `register_hooks()` now runs unconditionally in
`AM_Logger_Manager::init()`; there is no `is_enabled()`, no
`am_disabled_loggers` option, and `AM_Logger_Base` no longer declares
`slug()`/`label()` (they existed solely to key and label that UI). It was
never used, and its inverted-storage logic (`sanitize_disabled_loggers()`,
deleted with it) was one of the more fragile corners of the settings form.
If per-logger noise control is wanted back, it needs its own slug/label
contract reintroduced on `AM_Logger_Base` — don't graft a half-measure onto
something else.

**Every Visitor Stats table is capped at `AM_Admin::PER_PAGE` (10) rows,
fixed, not per-user.** The Activity Log briefly shared that same fixed 10
(2.8.15, replacing a per-user Screen Option that had existed since 2.4.3)
but got its own per-user control back in 2.8.20: a plain `<select
name="am_per_page">` next to its pagination (`AM_Admin::LOG_PER_PAGE_CHOICES`
— 10/15/20/50/100), not a Settings-screen field or the Screen Options API.
Read/persisted by `AM_Admin::log_per_page()` in usermeta (`am_log_per_page`,
cleaned up in `uninstall.php` via `delete_metadata()` across all users) — a
request carrying a valid `am_per_page` both applies and re-saves it,
everything else falls back to the stored preference. This is a deliberate
exception, not a precedent: Visitor Stats' seven tables stay fixed.

**Paging, filtering, and searching the Activity Log, and paging or changing
the date range on any of Visitor Stats' seven tables, all happen over AJAX,
not a page reload.** `AM_Admin::render_log_content( array $raw )` and
`render_stats_content( int $days, array $pages )` are the shared bodies:
`render_log_screen()` / `render_stats_screen()` call them once from `$_GET`
on a normal page load, echoing the result inside `#am-log-app` /
`#am-stats-content`; `ajax_log_table()` / `ajax_stats_content()` call the
same methods from a query string posted by `admin.js`, `parse_str()`'d back
into the same shape `$_GET` would have been, and return just that
container's inner HTML — one rendering path, no AJAX-specific branch to
drift out of sync. `admin.js` intercepts clicks on pagination/filter links
and the two forms inside those containers, POSTs the link's query string (or
the form's `serialize()`) to `am_log_table` / `am_stats_content`, swaps the
container's `innerHTML`, and pushes the same query string into the address
bar with `pushState()` so back/forward still work. **Every
`paginate_links()` call in both methods must pass an explicit base URL**
(`$current_url`, built from `$raw`/`$pages` rather than left to
`add_query_arg()`'s implicit current-URL default) — that default is
`$_SERVER['REQUEST_URI']`, which during the AJAX request is
`admin-ajax.php`, not the screen's own URL, so an implicit base would
silently point every pagination link at the wrong place the first time a
table is paginated from an AJAX-rendered page. Visitor Stats' seven tables
each page independently — `AM_Admin::STATS_PAGE_PARAMS` maps a short key
(`hits`, `top`, `ref`, `country`, `browser`, `os`, `device`) to its own query
param, so paging one table never resets another's; changing the date range
resets all seven, which is the one thing `admin.js`'s range-form handler
does that a plain link click doesn't.

All modals share one overlay, the `openModal()` JS helper, and the
`am_ajax` nonce.

**`uninstall.php` returns early when `am_delete_data_on_uninstall` is off**
(Settings → When the plugin is deleted; on by default, so an untouched site
behaves as it always did). When it's off the file does *nothing* — including
not deleting that option itself, or the choice wouldn't survive. Any new
option added anywhere needs a matching `delete_option()` in that file's
list; `am_datetime_format` and `am_maintenance_mode_last_state` were both
missing from it until 2.4.3.

**Cleaning up after removed features** goes in `am_run_upgrade_cleanup()`
(`activity-monitor.php`), which drops tables, options, and cron events left
by features that no longer exist. It's keyed on a stored `am_cleanup_version`,
not a boolean per removal: add a block guarded by
`version_compare( $done, '<x.y.z>', '<' )` for the version that dropped the
feature. **Every step must be idempotent** — the 2.2.2 switch from the old
`am_traffic_cleanup_done` boolean re-runs the 2.2.0 block once on sites that
already ran it. `uninstall.php` repeats the drops defensively, since it
can't assume any upgrade path ever ran.

Page traffic was removed in 2.2.0 — `AM_Traffic*`, the Traffic tab, and the
`am_traffic_log`/`am_traffic_daily` tables are all gone. Don't reintroduce
page-view capture as a parallel subsystem — if the forensic value is ever
wanted back, the audit-relevant subset (404 storms, `wp-login.php`/
`xmlrpc.php` probing, anonymous hits on restricted paths) belongs in a
logger writing through `AM_Event_Writer`, where it inherits the existing
filters, grouping, and export.

`AM_Event_Writer` collapses repeat events within a window keyed on
`event_type` + `action` + `object_id` + `initiator`. The window is
`am_occasion_window_seconds` (Settings → Logging; 5 minutes by default, 0
turns grouping off), still filterable on top. Loggers with no meaningful
object id (file-editor, fatal-errors) pass `'group' => false`.
`AM_Logger_Php_Warnings` goes the other way and *synthesizes* one —
`crc32( "$file:$line" ) & 0x7FFFFFFF`, since `object_id` is an int column —
so repeats of the same warning collapse while a different warning still gets
its own row.

## Decisions worth not re-litigating

- **The Activity Log's status links are built from the data, not from
  `AM_Log_Levels::ORDER`.** `AM_Event_Query::get_level_counts()` returns
  only levels that actually have rows *under the other active filters*,
  with counts, rendered in core's `.subsubsub` shape. Rendering all eight
  PSR-3 levels unconditionally meant most sites showed five links leading to
  an empty table. Two consequences that look like bugs but are load-bearing:
  the links carry the other filters forward (otherwise filter-aware counts
  would disagree with where the click lands), and the *currently selected*
  level stays listed even at zero (otherwise selecting it removes the only
  control that unselects it). The whole list is hidden when there's one
  level or fewer — unless a level filter is on.
- **Severity is not a proxy for "technical".** Any future attempt to split
  or filter this log by `level >= WARNING` will be wrong for the same reason
  the Debug Log's whitelist was written to avoid it: ordinary audit events
  (failed logins, password resets, plugin/theme deletions) already use
  WARNING. Level says how much it matters, not what kind of thing it is.
- **`AM_Logger_Php_Warnings` is the only logger that has to defend itself
  against its own logging.** It runs on `set_error_handler()`, called
  synchronously mid-request, so three things that are non-issues elsewhere
  are load-bearing here, all fixed in 2.4.6:
  - **`error_reporting() & $errno` gate.** Without it, everything the `@`
    operator suppresses gets logged — and core suppresses constantly
    (`@fopen`, `@unlink`, `@getimagesize`). `@` means the author knows that
    call can fail and handled it; logging it is noise by construction.
  - **A re-entrancy flag around the write.** A warning raised *inside* the
    log path (IP resolution, `$wpdb`, a deprecation in a core function it
    calls) re-enters the handler and recurses until the stack dies.
    `try/catch` doesn't help — warnings aren't `Throwable`. The guard wraps
    only the logging; the chain to `$previous_handler` always runs.
  - **A per-request `$seen` set keyed on action+file+line.** Occasion
    grouping collapses the *rows* but still costs a SELECT + UPDATE per
    occurrence, so a warning in a hot loop is thousands of queries in one
    page load. Consequence: `repeat_count` on these rows counts requests,
    not raw occurrences.

  Contrast `AM_Logger_Fatal_Errors`, which needs none of this — a shutdown
  handler fires once, after everything is already over.
- **A logger sets its own level; there is no per-event-type default table.**
  `AM_Event_Writer::log()` defaults `level` to `AM_Log_Levels::INFO` flat;
  anything that isn't routine passes `'level'` explicitly. There *was* an
  `AM_Log_Levels::EVENT_TYPE_DEFAULTS` table consulted here, keyed on
  `post.delete`, `plugin.update` and the like — but `$event_type` is only
  ever the type half (`post`, `plugin`), so no key could ever match and the
  lookup always returned INFO. Deleted in 2.4.4 rather than re-keyed: several
  loggers already pass a level differing from what that table intended for
  their type, so making it live would silently reclassify existing events,
  and two places defining levels is just somewhere for them to disagree. If
  a level looks wrong, fix it in the logger.
- **`AM_Date_Format` presets store separate date and time halves**, not one
  combined string, and `combined()` joins them rather than the reverse. Both
  callers that once needed a half on its own are gone (the two-line Date
  column in 2.0.70, the live traffic feed in 2.2.0) — but a combined string
  can't be split back reliably, so the pair stays the source of truth. Don't
  "simplify" it into one string.
- **The Type filter's combined value uses a pipe** (`media|uploaded`), not a
  dot — `event_type` can itself contain a dot on migrated v1 rows
  (`post.delete`), so a dot separator would misread a stored slug as a
  type/action pair. `sanitize_key()` strips both dots and pipes, hence
  `AM_Admin::sanitize_type_filter()`.
- **The user profile modal keys on `user_id`, not the stored login** —
  logins can be renamed and reused, so a login lookup could show the wrong
  person.
- **`am_ip_storage` is applied at write time, in
  `AM_Event_Writer::get_ip()`, never at display time.** The point of
  choosing "anonymised" or "none" is that the address never reaches the
  database — filtering it on the way out would leave the data sitting there.
  `AM_DB_Legacy_IP::resolve()` itself stays untouched (security-reviewed in
  v1.3.0); the setting layers on top of what it returns. Consequence:
  `ip_address` can now be `''`, so anything rendering it goes through
  `AM_Admin::ip_cell_html()`, which handles the empty case and the
  lookups-disabled case together.
- **Options read outside the admin always pass an explicit default to
  `get_option()`.** `register_setting()`'s `default` is implemented as a
  `default_option_*` filter registered on `admin_init`, and most log writes
  (failed logins, comments, cron, fatal errors) happen on requests that
  never load the admin — relying on it silently yields `false` there. This
  bites `am_ip_storage` and `am_occasion_window_seconds` specifically.

## Legacy v1.x data (a recurring source of surprises)

`AM_Schema::migrate_legacy_row()` copies v1's `event_type` **verbatim** and
sets `action` to `''`, because v1 folded the action into a single slug. An
upgraded site therefore has a *mixed* `event_type` column: clean v2 types
(`plugin`), legacy undelimited slugs (`pluginupdate`), and legacy dotted ones
(`post.delete`).

`AM_Event_Labels` handles all of it: `MAP` (type.action pairs), `TYPE_MAP`
(v2 types), `LEGACY_MAP` (exact v1 slugs mapped to their v2 phrasing, so
`authlogin` and `user.login` both read "User Logged In"), `LEGACY_TYPE_MAP`
(v1-only types `auth` and `option`), and `LEGACY_ACTIONS` +
`split_on_action()` as a general fallback that splits an unmapped slug on a
trailing action word. Guards: action ≥4 chars, type half ≥3, matching sorted
longest-first at runtime.

If labels look wrong, re-run this audit: parse every `$this->log()` /
`AM_Event_Writer::log()` call under `includes/loggers/` and compare the
emitted `event_type.action` pairs against `MAP`, and the types against
`TYPE_MAP`. Last run: 47 pairs, all mapped. The Activity Log's Type dropdown
is also the complete list of distinct `event_type` values in the database.

## Known issues

None currently tracked. The one long-standing entry was resolved in 2.2.1:
the user filter now renders a removable chip in the filter bar (still set
only from the profile modal, never a visible input — which is why the chip
matters).

## Verifying changes

There is no test suite. Before committing:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l    # syntax
composer install                                      # once, pulls phpcs + standards into vendor/
vendor/bin/phpcs .                                     # escaping, nonces, prepared statements, 7.4 floor
```

`phpcs` auto-discovers `phpcs.xml.dist` at the repo root — no `--standard`
flag needed. That ruleset runs `WordPress-Extra` (security/correctness) plus
`PHPCompatibilityWP` (enforces the 7.4 floor; `php -l` against a modern
binary happily accepts syntax that fatals on 7.4) in one pass, with
formatting/doc-block sniffs excluded — this codebase doesn't conform to
WPCS's structured doc-block or whitespace style, and that's a style choice,
not a defect.

**`phpcs` runs clean as of 2.4.9, and is meant to stay that way** — a run
with findings in it can't tell you which are new. If a genuinely new finding
is a plugin-constant table name interpolated into SQL text (not a
placeholder), that's accepted project-wide; suppress it rather than
touching the ruleset.

Suppress it *on the line the sniff actually reports*, which for `$wpdb`
queries is the SQL string, **not** the `$wpdb->prepare()` call above it. Six
annotations sat one line off and silently suppressed nothing until 2.4.4;
`phpcs --report=json` gives you the exact line and `source` to match. Two
forms, both in `includes/`:

- Interpolation on the string's *first* line — a `phpcs:ignore` immediately
  above the string, inside the `prepare()` call:
  `// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant.`
- Interpolation on a *later* line of a multi-line string (or spanning two
  statements) — an inline ignore can't reach it, so wrap the statement in a
  `// phpcs:disable <sniff> -- <reason>` / `// phpcs:enable <sniff>` pair.
  Always name the sniff on both, never a bare `disable`, and always close
  it. Where the statement is a `return`, prefer reflowing the SQL so the
  interpolated name lands on line one and a single `phpcs:ignore` covers it,
  rather than leaving an `enable` stranded after the return.

Name the *right* sniff, too: an ignore citing a sniff that isn't firing
reads as handled and isn't. `Generic.Files.LineLength` was once cited for an
empty `catch` (the actual sniff is
`Generic.CodeAnalysis.EmptyStatement.DetectedCatch`) and survived that way
for several versions. **Never write the sniff name from memory** — get it
from `--report=csv` (or `=json`), which prints the exact `source`. The 2.4.6
`error_reporting()` gate was first annotated with a plausible-looking
invented name and suppressed nothing; it also turned out to trip *two*
sniffs in different categories, which one guess could never have covered. A
comma-separated list on one `phpcs:ignore` handles that.

`phpcs.xml.dist`'s `minimum_supported_wp_version` feeds the deprecation
sniffs and has to track `Requires at least:` — it was still 5.3 two versions
after the floor moved to 6.0.

Most sites run with `WP_DEBUG` off, which hides undefined-array-key
warnings; a `display_name` key was once read but never built in a render
loop, and went unnoticed for a long time before a debug-enabled environment
surfaced it. When touching a render loop, check every key read against what
the builder actually creates.
