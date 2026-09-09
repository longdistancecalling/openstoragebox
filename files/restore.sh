#!/bin/bash
#
# Lagerverwaltung – Wiederherstellung aus einer Sicherung
#
#   ./restore.sh                          neueste Sicherung anzeigen und wählen
#   ./restore.sh ~/backup/…/lager_….sql.gz   bestimmte Datei einspielen
#
# Das Skript legt VOR dem Überschreiben eine Sicherung des aktuellen
# Standes an. Wer sich in der Datei vertut, kann zurück.

set -euo pipefail

PROJEKT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ZIEL="${BACKUP_ZIEL:-$HOME/backup/drk-lager}"
cd "$PROJEKT"

DB_NAME=$(grep '^DB_NAME='      .env | cut -d= -f2-)
DB_ROOT_PASS=$(grep '^DB_ROOT_PASS=' .env | cut -d= -f2-)

# --- Datei bestimmen -------------------------------------------------
if [[ $# -ge 1 ]]; then
    DATEI="$1"
else
    echo "Vorhandene Sicherungen in $ZIEL:"
    mapfile -t LISTE < <(find "$ZIEL" -name "${DB_NAME}_*.sql.gz" | sort -r)
    if [[ ${#LISTE[@]} -eq 0 ]]; then
        echo "Keine Sicherung gefunden." >&2
        exit 1
    fi
    for i in "${!LISTE[@]}"; do
        printf "  [%d] %s  (%s)\n" "$i" "$(basename "${LISTE[$i]}")" "$(du -h "${LISTE[$i]}" | cut -f1)"
    done
    read -rp "Nummer eingeben: " NR
    DATEI="${LISTE[$NR]}"
fi

[[ -f "$DATEI" ]] || { echo "Datei nicht gefunden: $DATEI" >&2; exit 1; }

# --- Bestätigung -----------------------------------------------------
BESTAND=$(docker compose exec -T db mariadb -N -B -u root -p"$DB_ROOT_PASS" \
          "$DB_NAME" -e "SELECT COUNT(*) FROM artikel" 2>/dev/null || echo "?")

cat <<TXT

  Wiederherstellen aus : $(basename "$DATEI")
  Zieldatenbank        : $DB_NAME
  Aktuell enthalten    : $BESTAND Artikel

  Der jetzige Inhalt der Datenbank wird vollständig ersetzt.

TXT
read -rp "Zum Fortfahren 'JA' eingeben: " ANTWORT
[[ "$ANTWORT" == "JA" ]] || { echo "Abgebrochen."; exit 0; }

# --- Sicherheitsnetz -------------------------------------------------
mkdir -p "$ZIEL"
VORHER="$ZIEL/${DB_NAME}_vor-restore_$(date +%F_%H%M).sql.gz"
echo "Sichere aktuellen Stand nach $(basename "$VORHER")"
docker compose exec -T db mariadb-dump -u root -p"$DB_ROOT_PASS" \
    --single-transaction --routines --events --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip > "$VORHER"

# --- Einspielen ------------------------------------------------------
#
# Datenbank leeren statt nur zu überschreiben: sonst blieben Tabellen
# stehen, die es zum Zeitpunkt der Sicherung noch nicht gab, und der
# View v_artikel würde auf alte Spalten zeigen.
#
echo "Setze Datenbank zurück und spiele Sicherung ein"
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASS" -e \
    "DROP DATABASE IF EXISTS \`$DB_NAME\`;
     CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

zcat "$DATEI" | docker compose exec -T db mariadb \
    -u root -p"$DB_ROOT_PASS" --default-character-set=utf8mb4 "$DB_NAME"

# --- Kontrolle -------------------------------------------------------
echo
echo "Ergebnis:"
docker compose exec -T db mariadb -u root -p"$DB_ROOT_PASS" "$DB_NAME" -e "
    SELECT 'Artikel'  AS Tabelle, COUNT(*) AS Anzahl FROM artikel
    UNION SELECT 'Kisten',    COUNT(*) FROM kiste
    UNION SELECT 'Entnahmen', COUNT(*) FROM entnahme
    UNION SELECT 'Inventuren',COUNT(*) FROM inventur;"

echo
echo "Fertig. Der Stand von vor der Wiederherstellung liegt in:"
echo "  $VORHER"
