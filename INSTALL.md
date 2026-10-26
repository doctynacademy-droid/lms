# Doctyn Academy — FCCM Learning Portal (LMS)

Fellowship in Critical Care Medicine (FCCM) learning portal for **Doctyn Academy**. It is the same platform as the Theor Academy LMS (PHP edition) with the same features, rebranded and loaded with the FCCM programme.

Runs on ordinary hosting: **PHP 7.4 or newer** with the **PDO SQLite** extension, on Apache or LiteSpeed. No Node.js, no database server, no command line.

## Easiest install: one file
1. cPanel → **File Manager** → `public_html` → create a folder named **`fccm`** (or any name you like, e.g. a subdomain folder).
2. Open it → **Upload** → choose **`install.php`** only.
3. Open `https://YOUR-DOMAIN/fccm/install.php`, choose an Admin ID and a password (10+ characters) → **Install**. It creates every file, writes your login and deletes itself.
4. Click **Open the LMS** and sign in.

## Alternative: from the zip
Upload `Doctyn-FCCM-LMS-upload.zip` into the folder, extract so `index.html`, `app.php`, `config.php`, `css`, `js`, `app` sit directly inside it, edit `config.php` and replace `CHANGE-ME-TO-A-LONG-PASSWORD`, then open the folder URL (with the trailing slash). If the page just says "Loading…", open `check.php` for a list of what to fix, then delete `check.php`.

## What is pre-loaded
16 modules from the FCCM logbook: Orientation & Your Clinical Logbook, then the 15 training areas (CVC, arterial line, airway, ventilation, chest tube, CPR, NG/OG tube, urinary catheter, POCUS, tracheostomy, bronchoscopy assistance, ventilator-associated procedures, bedside procedures, pacemaker/defibrillator, ICU drugs, scores & supportive care). Each has a summary, learning outcomes, syllabus and "Logbook targets". Lessons (video, text, PDFs) are added by you in **Admin → Content**; there is one sample lesson to delete. Modules have no release dates; add them per module and switch on "lock by date" in Branding & Settings if you want a drip schedule.

Branding defaults to the Doctyn Academy logo with navy and green; change name, tagline, logo and colours in **Admin → Branding & Settings**.

## Features (unchanged from the Theor LMS)
Admin: add/rename/reorder/hide modules and lessons, rich-text lesson editor, YouTube/Vimeo/Drive/Loom links or uploaded video, downloadable files, students (add, CSV import, reset password, disable, progress and CSV export), announcements, overview stats, branding and rules.
Students: ID + password sign-in with forced first-time password change, dashboard with progress, lesson search, Mark as complete, My Account, printable certificate once every module is complete.

## Data and backups
The database and uploads live in **`doctyn-fccm-data`**, created next to `public_html` (outside the website). Back it up regularly.

## Security notes
Hashed passwords (bcrypt), hashed session tokens, prepared statements, login rate limiting, upload type restrictions, config and data folders blocked from the web. Use HTTPS.

## Status
Content comes from the FCCM logbook's "Objective of Clinical Training" list; the lesson descriptions are general and **should be reviewed by your clinical faculty** before students use them. The logbook forms themselves (Procedure List, Patient Registry, Case Discussion) remain the printed PDF; this portal does not store patient data. Not tested on live hosting yet.
