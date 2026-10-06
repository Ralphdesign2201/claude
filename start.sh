#!/bin/sh
cd "$(dirname "$0")"
(sleep 1; xdg-open http://localhost:4545 2>/dev/null || open http://localhost:4545 2>/dev/null) &
node server.js
