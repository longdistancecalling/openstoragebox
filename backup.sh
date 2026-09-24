#!/bin/bash
#
# Lagerverwaltung – Datenbanksicherung
#
# Erzeugt einen komprimierten SQL-Dump, hält ihn lokal vor und schiebt
# ihn optional per rclone auf einen Offsite-Speicher.
#
#   ./backup.sh              nur lokal sichern
#   ./backup.sh --offsite    zusätzlich hochladen
#
# In die Crontab (täglich 03:15):
#   15 3 * * * /home/marcel/docker/drk-lager/backup.sh --offsite >> /var/log/drk-lager-backup.log 2>&1

set -euo pipefail

# --- Einstellungen ---------------------------------------------------
PROJEKT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIEL="${BACKUP_ZIEL:-$HOME/backup/drk-lager}"
AUFBEWAHRUNG_TAGE=30
RCLONE_REMOTE="${RCLONE_REMOTE:-hetzner-crypt:drk-lager}"

cd "$PROJEKT"

# --- Vorprüfungen ----------------------------------------------------
if [[ ! -f .env ]]; then
    echo "FEHLER: .env nicht gefunden in $PROJEKT" >&2
    exit 1
fi

# cut -f2- statt -f2: Base64-Passwörter enden oft auf '=' und würden
# sonst abgeschnitten.
DB_NAME=$(grep '^DB_NAME='      .env | cut -d= -f2-)
DB_ROOT_PASS=$(grep '^DB_ROOT_PASS=' .env | cut -d= -f2-)

if ! docker compose ps --status running --services 2>/dev/null | grep -qx db; then
    echo "FEHLER: Container 'db' läuft nicht. Kein Backup erstellt." >&2
    exit 1
fi

mkdir -p "$ZIEL"
STAMP=$(date +%F_%H%M)
DATEI="$ZIEL/${DB_NAME}_${STAMP}.sql.gz"

# --- Dump ------------------------------------------------------------
#
# --single-transaction  konsistenter Stand ohne Tabellensperre (InnoDB).
#                       Ohne das wäre das Lager während des Dumps blockiert
#                       und ein gleichzeitiges Buchen könnte einen halben
#                       Vorgang in die Sicherung schreiben.
# --routines --events   nimmt Views und geplante Ereignisse mit.
# --default-character-set=utf8mb4   sonst landen Umlaute kaputt im Dump.
#
echo "[$(date '+%F %T')] Sichere $DB_NAME nach $DATEI"

docker compose exec -T db mariadb-dump \
    -u root -p"$DB_ROOT_PASS" \
    --single-transaction \
    --routines --events \
    --default-character-set=utf8mb4 \
    "$DB_NAME" \
  | gzip -9 > "$DATEI.teil"

# Erst nach erfolgreichem Durchlauf umbenennen. Bricht der Dump ab,
# bleibt eine .teil-Datei liegen und wird nie für gültig gehalten.
mv "$DATEI.teil" "$DATEI"

GROESSE=$(du -h "$DATEI" | cut -f1)

# --- Plausibilitätsprüfung -------------------------------------------
# Ein Dump ohne die Tabelle 'artikel' ist wertlos. Lieber jetzt merken
# als beim Wiederherstellen.
if ! zgrep -q 'CREATE TABLE .artikel.' "$DATEI"; then
    echo "FEHLER: Dump enthält keine Tabelle 'artikel'. Datei behalten, aber prüfen!" >&2
    exit 1
fi

ZEILEN=$(zgrep -c 'INSERT INTO' "$DATEI" || true)
echo "[$(date '+%F %T')] OK – $GROESSE, $ZEILEN INSERT-Anweisungen"

# --- Alte Sicherungen aufräumen --------------------------------------
find "$ZIEL" -name "${DB_NAME}_*.sql.gz" -mtime +$AUFBEWAHRUNG_TAGE -delete
find "$ZIEL" -name '*.teil' -mtime +1 -delete
echo "[$(date '+%F %T')] $(find "$ZIEL" -name "${DB_NAME}_*.sql.gz" | wc -l) Sicherungen vorhanden"

# --- Offsite ---------------------------------------------------------
if [[ "${1:-}" == "--offsite" ]]; then
    if ! command -v rclone >/dev/null; then
        echo "WARNUNG: rclone nicht installiert, Upload übersprungen." >&2
        exit 0
    fi
    echo "[$(date '+%F %T')] Übertrage nach $RCLONE_REMOTE"
    rclone copy "$DATEI" "$RCLONE_REMOTE" --stats-one-line
    # Auch entfernt aufräumen
    rclone delete "$RCLONE_REMOTE" --min-age "${AUFBEWAHRUNG_TAGE}d" || true
    echo "[$(date '+%F %T')] Offsite-Sicherung abgeschlossen"
fi
