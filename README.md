# LagOHM – Yoga & Massage

Website für LagOHM (Helena), Yoga & Massage in München-Harlaching.

## Struktur

- `index.html` – Startseite
- `impressum.html`, `datenschutz.html` – rechtliche Seiten (Platzhalter, bitte vor Veröffentlichung mit echten Angaben ergänzen)
- `css/style.css`, `js/script.js`
- `images/` – Logo, Icons, Fotos

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
