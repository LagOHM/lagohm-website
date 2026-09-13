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

Dieses Repository ist für GitHub → Netlify eingerichtet:

1. Push nach GitHub (Branch `main`).
2. In Netlify: „Add new site" → „Import an existing project" → GitHub-Repo auswählen.
3. Build-Einstellungen: kein Build-Befehl nötig, Publish-Verzeichnis ist das Projekt-Root (`/`).

Jeder Push auf `main` aktualisiert die Live-Seite automatisch.
