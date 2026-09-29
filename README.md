# 2026 Scouting System — local prototype

## Run on Windows with Docker Desktop

1. Install and start Docker Desktop.
2. Extract this folder, open PowerShell in it, and run `docker compose up --build -d`.
3. Open http://localhost:8080 and sign in as `admin` with password `change-me-now`.
4. In Admin → Event Selection, choose a year, select Districts, Regionals, or Worlds, then choose an event. Districts also have a FIRST district dropdown (such as New England). The list loads from the public FIRST event page and is cached locally. Selecting an event imports its competing teams and available qualification match schedule from FIRST. Use Refresh official data to update them later; already scouted slots are protected. If the schedule is not published yet, the match table remains empty until a later refresh.
5. Create scout accounts, then open Matches and click Scout in a team cell. Try drawing an autonomous path, saving a draft, and submitting. Open Teams for robot photo cards and Pit Scout forms, then Strategy for alliance plans and the pick list for selection order.
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

## Current scope and limitations

This is an early local prototype based on the scoping document. It uses PHP and PostgreSQL, stores drafts and corrections with an audit trail, and preserves submitted records when reimporting a schedule. CSV import accepts `match,r1,r2,r3,b1,b2,b3`. The supplied schedule is fictional test data.

The 2026 game form uses general notes until game-specific fields are agreed. Video attachment, automatic account assignment, advanced photo management, cloud sync, offline browser queue, and remote access are not implemented. The dashboard shows a red warning because cloud sync has not been configured. A phone must reach the local server over a permitted network; this prototype does not transfer records between isolated cellular devices. Do not expose port 8080 to the internet.

Before real event use, change the initial admin password (currently done by creating a new admin account and removing the initial one in the database), replace the example database password, add HTTPS and robust backup/restore procedures. This build is for local testing, not live competition.

Robot photos (JPEG, PNG, WebP up to 4 MB) are saved in the local PostgreSQL database and appear on team cards and profiles.

The Admin page confirms the selected event and loaded record counts. The Matches table uses full page width, red and blue alliance columns, and a Scout button in each populated position.

The Match schedule keeps the Q-number column narrow. Click Q1, Q2, and so on to open a match detail page with Red and Blue alliance cards side by side. Each scheduled team shows its name, pit tags, submitted report count, event averages for match, auto, teleop, defense ability, and vulnerability, plus that match's submitted auto/teleop/total values when available. Each team has links to its profile and scouting form. On narrow screens, the alliance cards stack vertically.

Admin → Appearance accepts a JPEG, PNG, or WebP header logo up to 4 MB and has a site-wide Dark Mode switch. Both settings persist in local PostgreSQL; the logo appears beside “2370 · Scouting” on signed-in pages. The Strategy page opens directly with its editor and saved plans, without an introductory paragraph.

The full-width Pit Scouting Dashboard summarizes per-team averages from submitted match scouting entries. Scouts enter autonomous and teleop points; overall match score is calculated as their sum. Defensive Ability and Defensive Vulnerability use sliders from 0 to 5 in 0.1 steps. Click any dashboard column heading to sort. Metric cells transition from red for the lowest observed team average through orange, yellow, and green to blue for the highest; blank metrics have no submitted numeric observation.

The match scouting form begins with an autonomous path canvas. Draw with a mouse, stylus, or touch, and use Undo or Clear before saving a draft or submitting. Use Full screen to enlarge the field while drawing, then Exit full screen (or Escape) to return to the form. Saved strokes remain editable with a report correction. Team profiles overlay every submitted autonomous path for that team, with a separate color and legend entry for each match. Admins can upload a JPEG, PNG, or WebP field background (up to 4 MB) under **Admin → Active event**; it appears on all path canvases for that event. Existing reports without paths still display normally.

The **Strategy** page lets admins, mentors, and drive team accounts save multiple event plans. Choose up to three distinct alliance partners, assign each a path color, select a partner to draw its route on the same field image, and add strategy notes. **Saved plans** lists every plan by name with a Load button that restores its teams, colors, paths, and notes. Editors can delete a plan with its trash button after confirmation; other signed-in users may load plans in view-only mode. The three drawing layers can be edited independently with Undo and Clear. Full screen enlarges the field while retaining the partner and drawing controls; Exit full screen or Escape returns to the plan.

The **Pit scouting** form includes Robot Meta (Big Dumper, Turret, or Other with custom text). The **Pick list** has five side-by-side buckets: **S+**, **A**, **B**, **C**, and **DNP**, with an **Already Picked** container below them. Existing teams begin in B; existing Do Not Pick teams move into DNP. Each condensed card shows its team name and an always-visible **Picked** checkbox. Use the small expand button to reveal the robot photo, Robot Meta, match report count, average scores, defense metrics, and the Do Not Pick control. Admin, mentor, and drive accounts can drag the **⋮** handle to reorder cards within a bucket or move them between buckets, including an empty bucket. On desktop the whole card can also be dragged; on the handle, arrow keys move up and down or into the neighboring bucket. Cards shift during dragging; dropping saves the layout and canceling restores it. **Picked** moves the card into Already Picked while retaining its bucket and position; unchecking it returns the card there. **Do Not Pick** places an unpicked card in DNP with a light pink background; unchecking it moves the card into B. Existing stored notes are retained but hidden from this page.

Pit scouts can assign team tags using the Defense, Passing, L3 Climber, and Offense checkboxes, plus custom comma-separated tags. Tags appear on the team profile and on each condensed Pick list card. The WPI demo loader assigns sample tags to demo pit records; manually entered pit records remain untouched.

Tag colors are consistent across the pit form, team profile, and Pick list. Drivetrain, Intake, and Shooter type offer suggested values while allowing scouts to type a new value; saved values become suggestions for other teams at the event. The pit form also records width, length, and height in inches; weight in pounds; autonomous notes; and Driver and Human Player experience levels (New, Developing, Experienced, Veteran). The former Robot identity, freeform Dimensions, Mechanisms, and Scoring capabilities fields are hidden; existing values stay in the stored record when the updated form is saved.

## Try the 2026 WPI demo data

Select **2026 → Districts → New England → NE District WPI Event** in Admin, then click **Select event**. In the Active event section, click **Load WPI demo data**. The app loads the bundled official WPI team list and 78 qualification matches (39 teams), then fills each match position with synthetic scouting reports and gives each team a sample Robot Meta pit entry. Autonomous points are randomized from 10–100, teleop from 20–400, the match score is their sum, and both defense ratings are 0–5. All scheduled teams appear in the Dashboard and Teams pages with populated match reports. These numbers and Robot Meta entries are invented for display testing, not actual scouting results.

Existing manually entered match and pit records are left alone. Clicking the button again regenerates only the records marked as demo data. The bundled WPI schedule means this demo action works even when FIRST is temporarily unreachable. The Dashboard displays its submitted report count and a sorting status so you can tell whether the data and sorting script loaded.

After updating an existing ZIP installation, rebuild the Docker image with `docker compose up --build -d`. Copy the complete updated project, including `public/dashboard.js`, `fixtures/wpi-2026.json`, and `Dockerfile`; replacing only `public/index.php` will leave the table controls or bundled schedule out of date. The PostgreSQL Docker volume persists across an image rebuild.
