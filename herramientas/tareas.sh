#!/bin/bash
# Versión anterior de la tarea automática. Ahora conviene programar directamente:
#   php /ruta/al/sitio/cron/tareas.php
# (anota todo en data/cron.log, incluso si el PHP es viejo). Se deja por compatibilidad.
cd "$(dirname "$0")/.." || exit 1
"${PHP:-php}" cron/tareas.php
