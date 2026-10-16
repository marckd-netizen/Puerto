@echo off
rem Lee las terminales y despues consulta Lucia. Lo ejecuta el Programador de tareas cada 2 horas
rem (ver programar_tareas.bat). El resultado queda en data\cron.log.
cd /d "%~dp0.."
if not exist data mkdir data
echo ===== %date% %time% >> data\cron.log
php cron\actualizar.php >> data\cron.log 2>&1
php cron\contenedores.php >> data\cron.log 2>&1
