#!/usr/bin/env bash
#
# install.sh – Ersteinrichtung von OpenStorageBox
#
# Erzeugt eine .env mit sicheren Zugangsdaten für den Docker-Stack.
# Die Datenbank-Passwörter werden zufällig erzeugt; Name, Benutzer und
# Ports lassen sich anpassen. Eine bereits vorhandene .env wird NICHT
# überschrieben (außer mit --force), damit ein laufendes System nicht
# versehentlich seine Zugangsdaten verliert.
#
# Aufruf:
#   ./install.sh              interaktiv (fragt Name, Benutzer, Ports)
#   ./install.sh --yes        ohne Rückfragen, alle Vorgaben + Zufallspasswörter
#   ./install.sh --force       vorhandene .env überschreiben (mit Rückfrage)
#   ./install.sh --up          nach dem Anlegen den Stack gleich starten
#   ./install.sh --help        diese Hilfe
#
set -euo pipefail

# In den Ordner wechseln, in dem dieses Skript liegt (= Projektwurzel).
# So funktioniert der Aufruf auch von woanders aus.
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

ENV_DATEI=".env"
VORLAGE=".env.example"

# --- Vorgaben ---------------------------------------------------------
DB_NAME="lager"
DB_USER="lager_app"
WEB_PORT="8080"
ADMINER_PORT="8081"

# --- Schalter ---------------------------------------------------------
OHNE_FRAGEN=0
UEBERSCHREIBEN=0
STARTEN=0

for arg in "$@"; do
    case "$arg" in
        -y|--yes)   OHNE_FRAGEN=1 ;;
        --force)    UEBERSCHREIBEN=1 ;;
        --up)       STARTEN=1 ;;
        -h|--help)
            sed -n '3,20p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Unbekannte Option: $arg (siehe --help)" >&2
            exit 1
            ;;
    esac
done

# --- Sicherheitsnetz: liegen wir in der Projektwurzel? ----------------
if [ ! -f "docker-compose.yml" ]; then
    echo "Fehler: docker-compose.yml nicht gefunden." >&2
    echo "Das Skript muss im Projektordner liegen und von dort laufen." >&2
    exit 1
fi

# --- Vorhandene .env schützen -----------------------------------------
if [ -f "$ENV_DATEI" ] && [ "$UEBERSCHREIBEN" -eq 0 ]; then
    echo "Es gibt bereits eine .env – ich fasse sie nicht an."
    echo "Zum bewussten Neuanlegen: ./install.sh --force"
    exit 0
fi
if [ -f "$ENV_DATEI" ] && [ "$UEBERSCHREIBEN" -eq 1 ] && [ "$OHNE_FRAGEN" -eq 0 ]; then
    printf "Vorhandene .env wirklich überschreiben? Die alten Zugangsdaten gehen verloren. [j/N] "
    read -r antwort
    case "$antwort" in
        j|J|ja|Ja) : ;;
        *) echo "Abgebrochen."; exit 0 ;;
    esac
fi

# --- Passwort-Erzeugung -----------------------------------------------
# 32 Zeichen aus Buchstaben und Ziffern – bewusst ohne Sonderzeichen,
# damit die Werte in der .env, in `mariadb -p"…"` und in den Backup-
# Skripten überall ohne Maskierung funktionieren.
passwort() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 16
    else
        local pw
        pw=$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom 2>/dev/null | head -c 32) || true
        printf '%s' "$pw"
    fi
}

# --- Interaktive Abfragen ---------------------------------------------
frage() {
    # frage <Beschriftung> <Vorgabe> ; Ergebnis steht in $ANTWORT
    local text="$1" vorgabe="$2" eingabe
    printf "%s [%s]: " "$text" "$vorgabe"
    read -r eingabe
    ANTWORT="${eingabe:-$vorgabe}"
}

if [ "$OHNE_FRAGEN" -eq 0 ]; then
    echo "OpenStorageBox einrichten – leer lassen übernimmt die Vorgabe."
    echo
    frage "Name der Datenbank"        "$DB_NAME";      DB_NAME="$ANTWORT"
    frage "Datenbank-Benutzer"        "$DB_USER";      DB_USER="$ANTWORT"
    frage "Port für die Weboberfläche" "$WEB_PORT";     WEB_PORT="$ANTWORT"
    frage "Port für Adminer (DB-Tool)" "$ADMINER_PORT"; ADMINER_PORT="$ANTWORT"
    echo
fi

# Ports auf Zahlen prüfen – ein Tippfehler hier legt den Start lahm.
for p in "$WEB_PORT" "$ADMINER_PORT"; do
    case "$p" in
        ''|*[!0-9]*) echo "Fehler: '$p' ist kein gültiger Port." >&2; exit 1 ;;
    esac
done

DB_PASS="$(passwort)"
DB_ROOT_PASS="$(passwort)"

if [ -z "$DB_PASS" ] || [ -z "$DB_ROOT_PASS" ]; then
    echo "Fehler: Passwort-Erzeugung fehlgeschlagen (weder openssl noch /dev/urandom nutzbar)." >&2
    exit 1
fi

# --- .env schreiben ---------------------------------------------------
# Erst in eine temporäre Datei, dann umbenennen – so entsteht nie eine
# halb geschriebene .env, falls etwas dazwischenkommt.
TMP="$(mktemp)"
cat > "$TMP" <<EOF
# Von install.sh erzeugt am $(date '+%Y-%m-%d %H:%M').
# Diese Datei enthält Zugangsdaten und gehört NICHT ins Repository
# (steht in .gitignore). Für ein Backup separat und sicher aufbewahren.

DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
DB_ROOT_PASS=$DB_ROOT_PASS

WEB_PORT=$WEB_PORT
ADMINER_PORT=$ADMINER_PORT
EOF

chmod 600 "$TMP"
mv "$TMP" "$ENV_DATEI"

echo "✓ .env angelegt (Rechte 600, nur für dich lesbar)."
echo "  Datenbank : $DB_NAME"
echo "  Benutzer  : $DB_USER"
echo "  Web-Port  : $WEB_PORT   (http://localhost:$WEB_PORT)"
echo "  Adminer   : $ADMINER_PORT"
echo "  Passwörter: zufällig erzeugt und in .env gespeichert."

# .env.example nachziehen, falls sie fehlt – als Vorlage ohne Geheimnisse.
if [ ! -f "$VORLAGE" ]; then
    cat > "$VORLAGE" <<'EOF'
# Vorlage – echte Werte gehören in .env (siehe install.sh).
DB_NAME=lager
DB_USER=lager_app
DB_PASS=CHANGEME_APP
DB_ROOT_PASS=CHANGEME_ROOT

WEB_PORT=8080
ADMINER_PORT=8081
EOF
    echo "✓ .env.example als Vorlage angelegt."
fi

# --- Optional: Stack starten ------------------------------------------
starte_stack() {
    # docker compose (v2) oder docker-compose (v1) finden
    if docker compose version >/dev/null 2>&1; then
        COMPOSE="docker compose"
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE="docker-compose"
    else
        echo "Docker Compose nicht gefunden – bitte den Stack von Hand starten:" >&2
        echo "  docker compose up -d --build" >&2
        return 1
    fi
    echo
    echo "Starte den Stack ($COMPOSE up -d --build) …"
    # Ohne Root darf der Docker-Socket oft nicht angesprochen werden.
    if ! $COMPOSE up -d --build; then
        echo
        echo "Start fehlgeschlagen – vermutlich fehlen Rechte am Docker-Socket." >&2
        echo "Dann mit sudo erneut:  sudo $COMPOSE up -d --build" >&2
        return 1
    fi
    echo
    echo "✓ Läuft. Öffne http://localhost:$WEB_PORT und richte den ersten Admin ein."
}

if [ "$STARTEN" -eq 1 ]; then
    starte_stack || true
elif [ "$OHNE_FRAGEN" -eq 0 ]; then
    printf "\nStack jetzt starten (Container bauen und hochfahren)? [j/N] "
    read -r antwort
    case "$antwort" in
        j|J|ja|Ja) starte_stack || true ;;
        *)
            echo
            echo "Nächste Schritte:"
            echo "  docker compose up -d --build"
            echo "  danach im Browser: http://localhost:$WEB_PORT"
            ;;
    esac
else
    echo
    echo "Nächster Schritt: docker compose up -d --build"
fi
