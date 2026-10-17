# 2026 Scouting System — local prototype

## Run on Windows with Docker Desktop

1. Install and start Docker Desktop.
2. Extract this folder, open PowerShell in it, and run `docker compose up --build -d`.
3. Open http://localhost:8080 and sign in as `admin` with password `change-me-now`.
4. In Admin → Event Selection, choose a year, select Districts, Regionals, Worlds, or Offseason, then choose an event. Districts also have a FIRST district dropdown (such as New England). The list loads from the public FIRST event page and is cached locally. Offseason uses FIRST’s separate offseason list, including Week 0; it loads automatically even if regular-season events were already cached. Offseason teams and schedules can only be imported when the organizer publishes them to FIRST. Selecting an event imports its competing teams, qualification schedule, elimination matches from FIRST's Playoffs page, and video availability. Matches has separate collapsible Qualification Matches and Elimination Matches sections. A play icon opens FIRST's match video page when FIRST marks a video available; otherwise the row says “No Video.” Use Refresh official data to update teams, both match stages, and video links later; already scouted slots are protected. If a schedule is not published yet, its section remains empty until a later refresh.
5. Create scout accounts, then open Matches and click Scout in a team cell. Try drawing an autonomous path and submitting a report. Open Pit Scouting for robot photo cards and Pit Scout forms, then Strategy for alliance plans and the pick list for selection order.
   Admin → Site Appearance also lets an administrator change the site title shown in the header, sign-in page, and browser tab.
6. Stop with `docker compose down`. Data persists in the Docker volume. **Do not use `docker compose down -v` unless you intend to erase it.**

## Update an existing Windows installation

Download the current [`update-windows.ps1`](update-windows.ps1) into the same folder as `compose.yaml`, then run it with PowerShell. It downloads the current application files from GitHub, retries transient download errors, rebuilds and recreates the web container, and compares the files inside that container to the downloaded files. A failed download stops the process with an explicit error. The database volume is retained.

```powershell
$root = 'C:\Users\IBOTS\Documents\2026-Scouting-Vibetest-main\2026-Scouting-Vibetest-main'
$stamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
curl.exe -fL "https://raw.githubusercontent.com/IBOTS-AI/2026-Scouting-Vibetest/main/update-windows.ps1?v=$stamp" -o "$root\update-windows.ps1"
if ($LASTEXITCODE -ne 0) { throw 'Could not download the update script.' }
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\update-windows.ps1"
```

The page includes a content hash in each CSS and JavaScript URL, so the browser fetches a changed file after an update. If a normal update reports a container hash mismatch, rerun the script with `-CleanBuild` to rebuild Docker layers without cache.

Navigation uses a hamburger menu on all screen sizes. The former Teams page is called Pit Scouting. Dashboard, Pit Scouting, Pick List, and Strategy omit their page headings; the selected event stays in the header.

Admin sections appear in this order: Event Selection (including event confirmation and official refresh), User Management (add user, existing users, and password resets), Site Appearance, Year Specific Configuration (field background for the selected event), Pit Scouting Configuration, and Tag Management. Layouts stack on small screens. Existing accounts have an Admin-only password reset form; the new password is hashed before saving. The logo preview uses the same background as the header in light and dark modes.

## Current scope and limitations

This is an early local prototype based on the scoping document. It uses PHP and PostgreSQL, stores submitted reports and corrections with an audit trail, and preserves submitted records when refreshing an official schedule. Match scouting has one Submit action. Existing drafts remain editable; new entries are submitted. CSV schedule import has been removed.

The 2026 game form uses general notes until game-specific fields are agreed. User video uploads, automatic account assignment, advanced photo management, cloud sync, offline browser queue, and remote access are not implemented. The dashboard shows a red warning because cloud sync has not been configured. A phone must reach the local server over a permitted network; this prototype does not transfer records between isolated cellular devices. Do not expose port 8080 to the internet.

Before real event use, change the initial admin password using User Management → Existing users → Reset password, replace the example database password, add HTTPS and robust backup/restore procedures. This build is for local testing, not live competition.

Robot photos (JPEG, PNG, WebP up to 4 MB) are saved in the local PostgreSQL database and appear on team cards and profiles.

Team performance pages chart submitted scores (match, autonomous, and teleop) and 0–5 defense ratings by match. Chart points and match labels link to match details. Each statistic also has a horizontal range chart from minimum to maximum with an average marker and exact min/average/max values. Missing entries are excluded from ranges and leave gaps in trend lines; recorded zeros count. Qualification matches precede elimination matches. An expandable table provides all chart values, and teams without submitted reports show an empty state.

Admin → Site Appearance includes Highlighted Team, defaulting to 2370. That team's number uses a green badge on Matches, match details, Pit Scouting, and Pick List. Team numbers in the match schedule link to their team performance pages; the separate Scout button opens the match scouting form.

Admin → Pit Scouting Configuration manages Robot Meta, Intake, Shooter Type, Driver Experience Level, and Human Player Experience Level in individual collapsible editors, closed on page load. Tag Management is a separate top-level section with its own save button. Add, edit, or remove individual choices. Each tag has editable text and a color picker. Removing or renaming a choice that appears in pit records or match reports warns with record counts and requires confirmation; saved data remains intact, and previously saved values stay available on that team's form. The Pick List hides tags on cards and provides a tag filter above the buckets, including Already Picked.

The Admin page confirms the selected event and loaded record counts. The Matches table uses full page width, red and blue alliance columns, and a Scout button in each populated position.

The Match schedule keeps the Q-number column narrow. Click Q1, Q2, and so on to open a match detail page with Red and Blue alliance cards side by side. Each scheduled team shows its position as Red 1–3 or Blue 1–3 beside its linked team name, a compact robot thumbnail in the top right, pit tags, event averages for match, auto, teleop, defense ability, and vulnerability, plus that match's submitted auto/teleop/total values when available. The match detail cards omit submitted report counts and Scout buttons; use the Match schedule’s Scout buttons to enter reports. On narrow screens, the alliance cards stack vertically.

Match numbers are centered in their schedule column. Each colored alliance card header places the alliance name, its **Predicted Overall Score** (the sum of its three team average match scores), and a green **Predicted Winner** badge for the higher total together. A prediction waits until all three teams on both alliances have a submitted score average; equal totals do not show a winner.

Admin → Site Appearance accepts a JPEG, PNG, or WebP header logo up to 4 MB and has a site-wide Dark Mode switch. Both settings persist in local PostgreSQL; the logo appears beside “2370 · Scouting” on signed-in pages. The Strategy page opens directly with its editor and saved plans, without an introductory paragraph.

The full-width Pit Scouting Dashboard summarizes per-team averages from submitted match scouting entries. Scouts enter autonomous and teleop points; overall match score is calculated as their sum. Defensive Ability and Defensive Vulnerability use sliders with N/A at the far left, followed by 0 to 5 in 0.1 steps. New reports default to N/A; the N/A button resets either slider. N/A saves as JSON null and is excluded from averages, charts, and ranges; a recorded zero remains a valid rating. Click any dashboard column heading to sort. Metric cells transition from red for the lowest observed team average through orange, yellow, and green to blue for the highest; blank metrics have no submitted numeric observation.

The match scouting form begins with an autonomous path canvas. Draw with a mouse, stylus, or touch, and use Undo or Clear before submitting. Use Full screen to enlarge the field while drawing, then Exit full screen (or Escape) to return to the form. Saved strokes remain editable with a report correction. Team profiles overlay every submitted autonomous path for that team, with a separate color and legend entry for each match. Admins can upload a JPEG, PNG, or WebP field background (up to 4 MB) under **Admin → Year Specific Configuration**; it appears on all path canvases for that event. Existing reports without paths still display normally.

The **Strategy** page lets admins, mentors, and drive team accounts save multiple event plans. Plan Setup sits above Saved Plans and holds the title, partner selections, and colors. Choose a qualification or elimination match and Red or Blue alliance, then click Load match teams to populate the partners and title. Partners remain editable; loading different teams asks before clearing existing paths. Each partner dropdown sits beside its color selector. Choose up to three distinct alliance partners, assign each a path color, select a partner to draw its route on the same field image, and add strategy notes. **Saved plans** lists every plan by name with a Load button that restores its teams, colors, paths, and notes. Editors can delete a plan with its trash button after confirmation; other signed-in users may load plans in view-only mode. The three drawing layers can be edited independently with Undo and Clear. Full screen enlarges the field while retaining the partner and drawing controls; Exit full screen or Escape returns to the plan.

The **Pit scouting** form includes Robot Meta (Big Dumper, Turret, or Other with custom text). The **Pick list** has five side-by-side buckets: **S+** (green), **A** (orange), **B** (yellow), **C** (blue), and **DNP**, with an **Already Picked** container below them. Existing teams begin in B; existing Do Not Pick teams move into DNP. Each condensed card shows its team name and an always-visible **Picked** checkbox. Use the small expand button to reveal the robot photo, Robot Meta, match report count, average scores, defense metrics, and the Do Not Pick control. Admin, mentor, and drive accounts can drag the **⋮** handle to reorder cards within a bucket or move them between buckets, including an empty bucket. On desktop the whole card can also be dragged; on the handle, arrow keys move up and down or into the neighboring bucket. Cards shift during dragging; dropping saves the layout and canceling restores it. **Picked** moves the card into Already Picked while retaining its bucket and position; unchecking it returns the card there. **Do Not Pick** places an unpicked card in DNP with a light pink background; unchecking it moves the card into B. Existing stored notes are retained but hidden from this page.

Pit scouts can add up to 12 tags from the Admin-configured dropdown. Tags appear on the team profile and can filter the Pick List.

Tag colors are consistent across the pit form and team profile. Drivetrain offers suggested values while allowing scouts to type a new one. Robot Meta, Intake, Shooter Type, and both experience levels use Admin-configured dropdowns; previously saved values remain available on that team's form. The pit form also records width, length, and height in inches; weight in pounds; and autonomous notes. The former Robot identity, freeform Dimensions, Mechanisms, and Scoring capabilities fields are hidden; existing values stay in the stored record when the updated form is saved.

## Removing the old sample match data

The WPI sample match report loader has been removed. When the updated web container first connects to PostgreSQL, the schema removes match reports marked `demo: true`, clears their slot statuses, and removes their audit entries. It retains manually entered reports, teams, the official match schedule, and pit scouting records. Previously generated sample pit records remain because this cleanup is limited to match data.

Use `update-windows.ps1` to update an existing installation; it preserves the PostgreSQL Docker volume. The Dashboard shows submitted reports from real scouting entries after the cleanup.

On Matches, **Refresh Playoff Data** beside Elimination Matches is available to signed-in users. It fetches only the selected event’s FIRST playoff page, updates the round-by-round bracket with official teams and scores, and includes play icons for published videos. The elimination scouting table remains below the bracket. Unpublished results show a dash; refresh failures retain local data. Scouted slots retain their existing team assignments; the bracket uses official lineups, including backup substitutions.

## Custom pit scouting fields

Admin → Pit Scouting Configuration is a field editor. Add or remove fields, rename them, choose Dropdown, Number, Text input, Long text, Multiple select, Tags, or Image, and edit choices one per line. Drag the dotted handle on any field header to arrange the form without opening the editor, then Save Pit Scouting Configuration. Mouse, touch, and keyboard arrow keys on the handle are supported; Escape cancels a drag. The order also applies to pit notes on team profiles. Multiple select and Tags use checkboxes so scouts can choose several values on phones or laptops. Up to 50 fields and 12 image fields are supported; each image accepts JPEG, PNG, or WebP up to 4 MB and saves with the pit record. The original Robot photo field supplies thumbnails throughout the app.

Existing fields and choices are the initial configuration. Field IDs remain stable when names or positions change. Removing a field hides it without deleting saved data; changing its input type retains its stored value until a scout next saves that field. Retired dropdown choices already saved on a team stay available when editing that team. Existing legacy Robot Meta “Other” details and photos remain supported. Original and custom tag fields appear in pick-list tag filtering. Field definitions apply to all events and are included in database backups along with custom images; no database migration is needed. If an admin changes the configuration while a scout has a form open, the scout must reload before saving.

Choice fields (dropdowns, multiple select, and tags) also have per-option colors. Expand the field and use Option colors to pick a color or type HEX, RGB, HSV, or HSL, with a live preview. Examples: `#33aa66`, `rgb(51, 170, 102)`, `hsv(146, 70%, 67%)`, and `hsl(146, 54%, 43%)`. Colors save with the field configuration and appear on selection chips and team profiles. The original Tags field stays synchronized with Tag Management, and custom tag colors also appear in pick-list filtering.

Field backgrounds follow event selection on Strategy, match scouting, and team path canvases. An event uses its own uploaded image when available; otherwise it reuses the most recent uploaded background from the same season, including between regular-season and offseason events. Images are never borrowed from another season. Admin’s Field Background preview shows the same image as the canvases. Image URLs identify the source event and upload version so event switches and replacement uploads cannot reuse an unrelated cached image.

## Battery management

The navigation menu includes **Batteries**, a team-wide inventory that stays available when the selected event changes. Admins and mentors can add and edit battery labels, brand/model, serial numbers, purchase and first-service dates, rated capacity, and notes. Labels are unique across active and retired inventory. Retire a battery with a reason to retain its complete history; use Return to active inventory to undo a retirement. Search by label, brand, model, or serial number, filter active/retired/all, and sort the inventory columns.

Every signed-in user can record dated battery checks, measurements, and notes. Check types include inspection, load test, capacity test, robot use, charging, and other. Optional readings include resting and loaded voltage (V), test current (A), internal resistance (mΩ), measured capacity (Ah), state of charge (%), temperature (°C), and cycle count. Leave unmeasured values blank; zero is retained as a reading. Observed condition is entered by the user, with no automatic health classification. Optionally attach a stored event and a match/use reference. The battery detail page shows the latest recorded value and date for each metric plus the full chronological history. Users can correct their own checks; admins and mentors can edit all checks. Battery changes, retirement, and check edits are audited.

New backups include inventory and checks. Backups from before the battery feature can still be restored; since they contain no battery records, restoring one clears the battery inventory along with replacing the rest of the database. Download the current update-windows.ps1 before installing this feature so the new battery files are included.
