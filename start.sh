#!/bin/sh
# Startet das lokale Kanban und öffnet den Browser
cd "$(dirname "$0")"
command -v node >/dev/null 2>&1 || { echo "Node.js fehlt. Bitte Node.js 22.13 oder neuer installieren: https://nodejs.org"; exit 1; }
PORT="${PORT:-4545}"
(sleep 1; xdg-open "http://localhost:$PORT" 2>/dev/null || open "http://localhost:$PORT" 2>/dev/null) &
exec node server.js
