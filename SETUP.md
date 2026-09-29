# Setup-Checkliste: Umzug zu Netcup (Phase 1)

Diese Schritte kannst nur du selbst ausführen, da dabei Passwörter/Zugangsdaten getippt werden. Ich (Claude) sehe diese Werte nie. Einfach der Reihe nach abarbeiten.

## 1. SSH-Passwort setzen

1. Im Netcup Kundenservicecenter (customercontrolpanel.de) einloggen → Produkte → Hosting248742 → WCP Auto-Login.
2. „Websites & Domains" → bei `hosting248742.af954.netcup.net` auf das Zahnrad-Symbol (⚙) klicken → „Anmeldedaten für Systembenutzer".
3. Ein neues Passwort eintragen (oder über „Erstellen" generieren lassen) und speichern.
4. Dieses Passwort notierst du dir kurz zwischen — du brauchst es gleich zweimal (Schritt 2 und Schritt 4).

## 2. Einmalig per SSH einloggen und die Seite klonen

Auf Windows z. B. über PowerShell:

```bash
ssh hosting248742@af954.netcup.net
```

Passwort aus Schritt 1 eingeben. Danach im Server-Terminal:

```bash
cd ~/lagohm.de
mv httpdocs httpdocs_alt
git clone https://github.com/LagOHM/lagohm-website.git httpdocs
```

Das sichert eventuell vorhandene Platzhalter-Dateien in `httpdocs_alt` (kann später gelöscht werden) und lädt die echte Website hinein.

Kurzer Check, ob es geklappt hat:

```bash
ls httpdocs
```

Es sollten `index.html`, `css/`, `js/`, `images/` usw. auftauchen. Danach `exit` zum Verlassen der SSH-Verbindung.

## 3. GitHub Secrets eintragen

Auf github.com im Repo `LagOHM/lagohm-website` → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**, dreimal:

| Name | Wert |
|---|---|
| `NETCUP_SSH_HOST` | `af954.netcup.net` |
| `NETCUP_SSH_USER` | `hosting248742` |
| `NETCUP_SSH_PASSWORD` | das Passwort aus Schritt 1 |

## 4. SSL-Zertifikat aktivieren (HTTPS)

1. Im WCP (wie Schritt 1) → „Websites & Domains" → bei `lagohm.de` auf das Zahnrad-Symbol.
2. Dort unter „SSL/TLS-Zertifikate" bzw. „Let's Encrypt" (eigener Menüpunkt in der Plesk-Seitenleiste) ein neues Let's-Encrypt-Zertifikat für `lagohm.de` **und** `www.lagohm.de` anfordern (kostenlos, wenige Klicks, meist ein Häkchen bei beiden Domainnamen plus „Sichern"-Button).
3. Zurück in den „Hosting-Einstellungen" von `lagohm.de` → Abschnitt „SSL/TLS-Unterstützung" → im Dropdown „Zertifikat" das neu ausgestellte Zertifikat auswählen → Speichern.
4. Optional, aber empfohlen: Haken bei „Besucher über eine SEO-freundliche 301-Umleitung von HTTP zu HTTPS weiterleiten" setzen.

## 5. Testen, ob alles läuft

- `https://lagohm.de` im Browser öffnen — die Seite sollte erscheinen, Schloss-Symbol für HTTPS.
- Eine Kleinigkeit im Code ändern lassen (das mache ich), pushen, und in GitHub unter „Actions" prüfen, ob der Deploy grün durchläuft und die Änderung auf `lagohm.de` sichtbar wird.

## 6. Erst danach: Netlify kündigen

Erst wenn Schritt 5 zuverlässig funktioniert (gerne ein paar Tage beobachten), bei Netlify die Seite/das DNS trennen. Nicht vorher.

---

Bei Problemen in einem der Schritte: mir kurz Bescheid geben, wo genau es hakt (z. B. "SSH-Login funktioniert nicht" oder "Zertifikat nicht in der Liste") — ich helfe dann gezielt weiter, ohne dass du mir Passwörter nennen musst.
