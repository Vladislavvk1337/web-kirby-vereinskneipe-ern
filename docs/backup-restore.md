# Datensicherung und Wiederherstellung

## Was gesichert werden muss

| Was | Ort auf dem Server | Warum |
| --- | --- | --- |
| Inhalte inkl. Bilder und Anfragen | `/var/www/<DOMAIN>/data/content/` | alles, was im Panel gepflegt wird |
| Benutzerkonten (inkl. Passwort-Hashes) | `/var/www/<DOMAIN>/data/storage/accounts/` | Zugänge der Redaktion |
| Kirby-Lizenz | `/var/www/<DOMAIN>/data/storage/.license` | Aktivierung |
| Geheimnisse und SMTP | `/var/www/<DOMAIN>/config/kirby.env` | ohne Salt/Cookie-Schlüssel werden Sitzungen und Vorschau-Links ungültig |
| Serverlokale Werte | `/etc/site.local.env` | z. B. fail2ban-Ausnahmen |
| Programmcode | GitHub | wird bei Bedarf neu geholt – keine Sicherung nötig |

**Nicht** gesichert werden `data/media/` (Kirby erzeugt die Bildgrößen neu),
Sitzungen und Cache.

## Automatische Sicherung

`kneipe-backup.timer` läuft täglich um ca. 02:30 Uhr und schreibt
`/var/backups/kneipe/kneipe-JJJJMMTT-HHMM.tar.gz` (nur root lesbar, 600).
Aufbewahrung: `BACKUP_KEEP_DAYS` in `deploy/site.env` (Standard 14 Tage).

```bash
sudo kneipe-backup                           # sofort sichern
sudo ls -lh /var/backups/kneipe/
systemctl list-timers kneipe-backup.timer
journalctl -u kneipe-backup -n 5 --no-pager
```

### Kopie außerhalb des Servers (empfohlen)

Eine Sicherung auf demselben Server schützt nicht vor dessen Ausfall. Die
Archive regelmäßig woandershin kopieren, z. B. vom eigenen Rechner:

```bash
rsync -av --rsync-path="sudo rsync" <ADMIN_USER>@<server>:/var/backups/kneipe/ ./kneipe-backups/
```

oder mit einem Backup-Dienst des Hosters (Snapshots). Die Archive enthalten
Passwort-Hashes, Geheimnisse und personenbezogene Anfragen – verschlüsselt
aufbewahren (z. B. `gpg --symmetric` oder ein verschlüsseltes Ziel wie
restic/Borg) und die Löschfristen beachten: Alte Sicherungen mit Anfragen
nicht länger aufheben als nötig (Vorschlag: 14 Tage auf dem Server, 30 Tage
extern).

## Wiederherstellen

### Einzelne Inhalte

```bash
mkdir /tmp/restore && cd /tmp/restore
sudo tar xzf /var/backups/kneipe/kneipe-<STAND>.tar.gz
# gewünschten Ordner zurückkopieren, z. B. einen Termin:
sudo rsync -a data/content/1_termine/<ordner>/ /var/www/<DOMAIN>/data/content/1_termine/<ordner>/
sudo chown -R www-data:www-data /var/www/<DOMAIN>/data/content
rm -rf /tmp/restore
```

### Vollständig (z. B. neuer Server)

1. Server nach [deployment.md](deployment.md) einrichten (Schritte 1–5).
2. Zeitgesteuerte Aktualisierung kurz anhalten:
   `sudo systemctl stop site-update.timer`
3. Sicherung einspielen:

   ```bash
   cd /var/www/<DOMAIN>
   sudo tar xzf /pfad/kneipe-<STAND>.tar.gz
   sudo chown -R www-data:www-data data/content data/storage
   sudo chown <ADMIN_USER>:www-data config/kirby.env && sudo chmod 640 config/kirby.env
   sudo rm -rf data/storage/cache/* data/media/*
   sudo systemctl restart php*-fpm
   sudo systemctl start site-update.timer
   ```

4. Prüfen: Startseite, Panel-Anmeldung, ein Termin, Bilder (werden neu
   erzeugt), Testanfrage.

### Probe-Wiederherstellung

Mindestens einmal im Jahr eine Sicherung auf einem Test- oder
Staging-System einspielen und prüfen, ob Panel und Website funktionieren.
