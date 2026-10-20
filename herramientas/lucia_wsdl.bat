@echo off
rem Descarga la descripcion (WSDL) de un webservice de Lucia. Solo lee, no modifica nada.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0lucia_wsdl.ps1"
echo.
pause
