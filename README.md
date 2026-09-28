# 2026 Scouting System — local prototype

## Run on Windows with Docker Desktop

1. Install and start Docker Desktop.
2. Clone the repository or extract the downloaded ZIP. In PowerShell, change into the folder that contains `compose.yaml` (usually `2026-Scouting-Vibetest` for a GitHub download, or `scouting-app` for the ZIP shared in chat). Check with `Get-ChildItem compose.yaml` before running Docker.
3. Run `docker compose up --build -d` in that folder.
4. Open http://localhost:8080 and sign in as `admin` with password `change-me-now`.
5. In Admin, create an event (for example `2026 Test Event`, key `test2026`) and import `sample-schedule.csv`.
6. Create scout accounts, then open Matches and click a team number. Try saving a draft and submitting. Open Teams for pit notes and the pick list for strategy.
7. Stop with `docker compose down`. Data persists in the Docker volume. **Do not use `docker compose down -v` unless you intend to erase it.**

## Current scope and limitations

This is an early local prototype based on the scoping document. It uses PHP and PostgreSQL, stores drafts and corrections with an audit trail, and preserves submitted records when reimporting a schedule. CSV import accepts `match,r1,r2,r3,b1,b2,b3`. The supplied schedule is fictional test data.

The 2026 game form uses general notes until game-specific fields are agreed. Official FIRST schedule/team API import, video attachment, automatic account assignment, photos, metrics, cloud sync, offline browser queue, and remote access are not implemented. The dashboard shows a red warning because cloud sync has not been configured. A phone must reach the local server over a permitted network; this prototype does not transfer records between isolated cellular devices. Do not expose port 8080 to the internet.

Before real event use, change the initial admin password (currently done by creating a new admin account and removing the initial one in the database), replace the example database password, add HTTPS and robust backup/restore procedures. This build is for local testing, not live competition.
