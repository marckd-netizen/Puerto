@echo off
rem Consulta un movimiento de stock en Lucia (awsmovstock). Solo consulta, no modifica nada.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0movstock_prueba.ps1"
echo.
pause
