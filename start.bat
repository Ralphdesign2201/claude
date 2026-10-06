@echo off
cd /d "%~dp0"
start "" http://localhost:4545
node server.js
