#!/bin/bash
# Lee las terminales y después consulta Lucía. Para el cron del hosting (cPanel → Cron Jobs), cada 2 horas:
#   0 */2 * * * /bin/bash /home/USUARIO/ruta/al/sitio/herramientas/tareas.sh
# El resultado queda en data/cron.log.
#
# En cPanel el "php" del cron suele ser otra versión que la del sitio: se busca uno de 8.1 o más nuevo.
# Para usar uno en particular: PHP=/ruta/a/php /bin/bash tareas.sh

cd "$(dirname "$0")/.." || exit 1
mkdir -p data

if [ -z "$PHP" ]; then
    for candidato in /opt/cpanel/ea-php8[0-9]/root/usr/bin/php /usr/local/bin/php /usr/bin/php php; do
        if "$candidato" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);' >/dev/null 2>&1; then
            PHP="$candidato"
        fi
    done
fi

{
    echo "===== $(date '+%Y-%m-%d %H:%M:%S')"
    if [ -z "$PHP" ]; then
        echo "No se encontró PHP 8.1 o más nuevo. Indicalo así en el cron: PHP=/ruta/a/php /bin/bash $0"
        exit 1
    fi
    "$PHP" cron/actualizar.php
    "$PHP" cron/contenedores.php
} >> data/cron.log 2>&1
