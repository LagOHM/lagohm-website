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
```

**Wichtig:** Auf diesem Netcup-Server ist `git clone https://...` kaputt (fehlende Systembibliothek `libngtcp2_crypto_gnutls.so.8` im Server-Image — ein Netcup-seitiges Problem, kein Fehler unsererseits). Deshalb läuft der Zugriff auf GitHub stattdessen über einen SSH-Schlüssel (Deploy Key), nicht über HTTPS:

```bash
mkdir -p -m 700 ~/.ssh
ssh-keygen -t ed25519 -C "lagohm-deploy" -f ~/.ssh/github_deploy_key -N ""
cat ~/.ssh/github_deploy_key.pub
```

Den ausgegebenen Schlüssel (beginnt mit `ssh-ed25519 ...`) kopieren und bei `https://github.com/LagOHM/lagohm-website/settings/keys` → „Add deploy key" einfügen (Titel z. B. „Netcup Server", **„Allow write access" NICHT ankreuzen**, da nur Lesezugriff nötig ist).

Danach im Terminal:

```bash
cat >> ~/.ssh/config << 'EOF'
Host github.com
  User git
  IdentityFile ~/.ssh/github_deploy_key
  IdentitiesOnly yes
  StrictHostKeyChecking accept-new
EOF
chmod 600 ~/.ssh/config

ssh -T git@github.com
```

Bei Erfolg erscheint „Hi LagOHM/lagohm-website! You've successfully authenticated, but GitHub does not provide shell access." Dann klonen:

```bash
git clone git@github.com:LagOHM/lagohm-website.git httpdocs
ls httpdocs
```

Es sollten `index.html`, `css/`, `js/`, `images/` usw. auftauchen. Danach `exit` zum Verlassen der SSH-Verbindung.

Der GitHub-Actions-Workflow (`.github/workflows/deploy.yml`) SSHt separat mit Passwort in den Server (Schritt 3) und führt dort `git fetch`/`git reset` aus — das nutzt automatisch denselben Deploy Key, weil der Server dafür lokal konfiguriert ist. Keine weitere Einrichtung nötig.

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

# Setup-Checkliste: Buchungs-Backend (Phase 2)

Die Datenbank `k430430_lagohm` hast du bereits angelegt. Jetzt noch drei Dinge, alles per SSH (so wie in Phase 1 eingeloggt):

## 1. Schema importieren

```bash
cd ~/lagohm.de/httpdocs
mysql -h 10.35.249.85 -u k430430_lagohm -p k430430_lagohm < sql/schema.sql
```

Nach Enter fragt es nach dem Datenbank-Passwort (das, was du beim Anlegen der Datenbank gesetzt hast). Keine Ausgabe = erfolgreich.

## 2. config.php anlegen

```bash
cp lib/config.sample.php lib/config.php
nano lib/config.php
```

Dort folgende Werte eintragen (alles andere kann so bleiben):
- `db.pass` — dein Datenbank-Passwort aus Schritt 1
- `smtp.pass` — das Passwort deines `helena@lagohm.de`-Postfachs
- `encryption_key_hex` — einen zufälligen Wert erzeugen mit:
  ```bash
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  ```
  und den Output hier einfügen (wird erst ab Phase 3 für den Google-Token gebraucht, aber gleich mit erledigen)

Speichern in nano: `Strg+O`, Enter, dann `Strg+X` zum Verlassen.

## 3. Admin-Konto erstellen

Im Browser öffnen: `https://lagohm.de/admin/setup.php` — einmaliges Formular, danach nie wieder erreichbar (sobald ein Konto existiert, blockiert es sich selbst). Eigene E-Mail und ein Passwort (mind. 10 Zeichen) eintragen — das tippe ich nie, das ist nur für dich in deinem Browser.

Danach einloggen unter `https://lagohm.de/admin/login.php` — dort siehst du die Buchungsliste und kannst die wöchentlichen Öffnungszeiten einstellen (Standard: Mo–Sa 9–19 Uhr, passend zu dem, was wir besprochen haben).

## Testen

Sobald das erledigt ist, teste ich die API direkt per curl (`/api/slots.php`, `/api/booking-create.php`) — dafür brauche ich keinen Zugriff auf deinen Server, das läuft über die normale Website-URL.
