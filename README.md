# 2026 Scouting System — local prototype

## Run on Windows with Docker Desktop

1. Install and start Docker Desktop.
2. Extract this folder, open PowerShell in it, and run `docker compose up --build -d`.
3. Open http://localhost:8080 and sign in as `admin` with password `change-me-now`.
4. In Admin → Event Selection, choose a year, select Districts, Regionals, or Worlds, then choose an event. Districts also have a FIRST district dropdown (such as New England). The list loads from the public FIRST event page and is cached locally. Selecting an event imports its competing teams and available qualification match schedule from FIRST. Use Refresh official data to update them later; already scouted slots are protected. If the schedule is not published yet, the match table remains empty until a later refresh.
5. Create scout accounts, then open Matches and click a team number. Try saving a draft and submitting. Open Teams for robot photo cards and Pit Scout forms, then the pick list for strategy.
6. Stop with `docker compose down`. Data persists in the Docker volume. **Do not use `docker compose down -v` unless you intend to erase it.**

## Current scope and limitations

This is an early local prototype based on the scoping document. It uses PHP and PostgreSQL, stores drafts and corrections with an audit trail, and preserves submitted records when reimporting a schedule. CSV import accepts `match,r1,r2,r3,b1,b2,b3`. The supplied schedule is fictional test data.

The 2026 game form uses general notes until game-specific fields are agreed. Official FIRST schedule/team API import, video attachment, automatic account assignment, advanced photo management, metrics, cloud sync, offline browser queue, and remote access are not implemented. The dashboard shows a red warning because cloud sync has not been configured. A phone must reach the local server over a permitted network; this prototype does not transfer records between isolated cellular devices. Do not expose port 8080 to the internet.

Before real event use, change the initial admin password (currently done by creating a new admin account and removing the initial one in the database), replace the example database password, add HTTPS and robust backup/restore procedures. This build is for local testing, not live competition.

Robot photos (JPEG, PNG, WebP up to 4 MB) are saved in the local PostgreSQL database and appear on team cards and profiles.
