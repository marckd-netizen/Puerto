@echo off
rem Crea (o reemplaza) la tarea de Windows que ejecuta tareas.bat cada 2 horas.
rem Doble clic una sola vez. Para borrarla: schtasks /delete /tn "Puerto - lectura cada 2 horas" /f
schtasks /create /tn "Puerto - lectura cada 2 horas" /tr "\"%~dp0tareas.bat\"" /sc hourly /mo 2 /f
if errorlevel 1 (
    echo.
    echo No se pudo crear la tarea. Proba con clic derecho - Ejecutar como administrador.
) else (
    echo.
    echo Listo: la tarea se ejecuta cada 2 horas. Para probarla ahora:
    echo   schtasks /run /tn "Puerto - lectura cada 2 horas"
    echo El resultado queda en %~dp0..\data\cron.log
)
echo.
pause
