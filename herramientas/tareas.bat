@echo off
rem Lee las terminales y despues consulta Lucia. Lo ejecuta el Programador de tareas cada 2 horas
rem (ver programar_tareas.bat). El resultado queda en data\cron.log.
cd /d "%~dp0.."
php cron\tareas.php
