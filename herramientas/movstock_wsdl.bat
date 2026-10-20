@echo off
rem Descarga la descripcion (WSDL) del webservice awsmovstock de Lucia. Solo lee, no modifica nada.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0movstock_wsdl.ps1"
echo.
pause
