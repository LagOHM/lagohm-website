# LagOHM – Yoga & Massage

Website für LagOHM (Helena), Yoga & Massage in München-Harlaching.

## Struktur

- `index.html` – Startseite (Deutsch), `en/index.html` – englische Version
- `impressum.html`, `datenschutz.html` – rechtliche Seiten
- `css/style.css`, `js/script.js`
- `images/` – Logo, Icons, Fotos
- `sql/schema.sql` – Datenbank-Schema für das Buchungssystem
- `lib/` – PHP-Backend-Klassen (DB, Auth, CSRF, Verfügbarkeits-Logik, Mailer); `lib/config.php` existiert nur auf dem Server, nie im Repo
- `api/` – Öffentliche Endpunkte (`slots.php`, `booking-create.php`, `booking-cancel.php`)
- `admin/` – Login-geschützter Bereich: Buchungsliste, Verfügbarkeiten verwalten

## Lokal ansehen

Einfach `index.html` über einen lokalen Server öffnen (z. B. per VS Code „Live Server"-Erweiterung), da die Seite relative Pfade nutzt.

## Deployment

Die Seite läuft auf Netcup Webhosting (Plesk), Domain `lagohm.de`. Deployment läuft automatisch über GitHub Actions (`.github/workflows/deploy.yml`):

1. Push nach GitHub (Branch `main`).
2. GitHub Actions verbindet sich per SSH mit dem Netcup-Server und führt dort `git fetch && git reset --hard origin/main` im Webroot (`~/lagohm.de/httpdocs`) aus.
3. Ein kurzer Smoke-Test prüft danach, ob `https://lagohm.de/` erreichbar ist.

Jeder Push auf `main` aktualisiert die Live-Seite automatisch — genau wie zuvor bei Netlify.

Die dafür nötigen Zugangsdaten liegen ausschließlich als GitHub Secrets (`NETCUP_SSH_HOST`, `NETCUP_SSH_USER`, `NETCUP_SSH_PASSWORD`), nie im Repo. Einmalige Einrichtung: siehe [SETUP.md](SETUP.md).

Ab Phase 2 kommt ein PHP/MySQL-Backend für das Buchungssystem dazu (siehe Projektplan). Serverseitige Geheimnisse (DB-Zugang, Google-API-Schlüssel) leben ausschließlich in einer `config.php` direkt auf dem Server, die nie committet wird (`.gitignore`).

### Frühere Konfiguration (Netlify, abgelöst)

Bis zum Umzug auf Netcup lief die Seite auf Netlify mit GitHub-Auto-Deploy. Diese Anbindung wird nach erfolgreicher Migration gekündigt (siehe SETUP.md, Punkt „Netlify kündigen").

## Cache-Busting für CSS/JS

Zusätzlich setzt `.htaccess` im Hauptordner `Cache-Control: no-cache` für HTML/CSS/JS. Alle Verweise auf eigene CSS- und JS-Dateien haben einen Versions-Anhang (`style.css?v=20261003`). Ohne beides behalten Browser (vor allem am Handy) alte Dateien oft tagelang. **Nach jeder Änderung an einer CSS- oder JS-Datei die Version überall hochsetzen**, z. B.:

```bash
grep -rl --include=*.html --include=*.php '?v=20261003' . | xargs sed -i 's/?v=20261003/?v=NEUES_DATUM/g'
```
