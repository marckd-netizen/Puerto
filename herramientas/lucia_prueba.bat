@echo off
rem Prueba del webservice de Lucia. Ejecuta lucia_prueba.ps1, que tiene que estar en la misma carpeta.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0lucia_prueba.ps1"
echo.
pause
