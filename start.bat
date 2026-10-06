@echo off
cd /d "%~dp0"
where node >nul 2>nul || (echo Node.js fehlt. Bitte Node.js 22.13 oder neuer installieren: https://nodejs.org & pause & exit /b 1)
start "" http://localhost:4545
node server.js
pause
