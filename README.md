# Arribos Puerto de Montevideo

Sitio en PHP + MySQL que junta en una sola página las llegadas de buques que
publican las dos terminales (TCP y Montecon) y **destaca los cambios en la
operativa prevista** (ETA, ETD, estado y servicio).

![Captura](docs/captura.png)

## Qué hace

- Un proceso (`cron/actualizar.php`) lee cada 2 horas las páginas de las dos terminales.
- Compara con lo guardado y registra cada cambio (valor anterior → valor nuevo).
- La página principal muestra **una fila por buque**. Si el buque figura en las dos
  terminales, sus datos aparecen juntos. Columnas:
  **Buque · Estado · Terminal · ETA/ETD - TCP · ETA/ETD - MONTECON · Servicio · Último cambio**.
- Colores de las filas:
  - **Verde**: el buque está operando, según el texto del estado (lista configurable en `estados_operando`).
  - **Naranja**: tuvo un cambio hace menos de 2 horas.
  - **Naranja claro**: tuvo un cambio hace entre 2 y 24 horas.
  - **Sin color**: pasaron más de 24 horas sin cambios.

  Si un buque está operando y además cambió, la fila queda en verde y la celda
  "Último cambio" toma el tono naranja que corresponde.
- Mientras dura el resaltado, el dato que cambió se muestra en negrita, con el valor anterior tachado.
- Filtros por terminal, búsqueda, "solo con cambios" e "incluir ya zarpados". Arriba se ve
  la última lectura de cada terminal, en rojo si falló.
- `historial.php`: todos los cambios de los últimos días, o el historial completo de un buque.

Los cambios se detectan en cada lectura, así que la hora del cambio es la de la
lectura en que se vio. Con lecturas cada 2 horas, el cambio real pudo haber
ocurrido hasta 2 horas antes.

## Instalación

1. Requisitos: PHP 8.1 o superior con las extensiones `pdo_mysql`, `curl`, `dom` y `mbstring`, y MySQL o MariaDB.
2. Crear la base de datos y cargar el esquema:
   ```sql
   CREATE DATABASE puerto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'puerto'@'localhost' IDENTIFIED BY 'una-clave';
   GRANT ALL ON puerto.* TO 'puerto'@'localhost';
   ```
   ```bash
   mysql -u puerto -p puerto < sql/schema.mysql.sql
   ```
3. `cp config.example.php config.php` y completar los datos de la base.
4. Apuntar el servidor web a la carpeta `public/`. Solo esa carpeta debe quedar
   accesible desde internet.
5. Programar la lectura con cron:
   ```
   0 */2 * * * php /ruta/a/Puerto/cron/actualizar.php >> /ruta/a/Puerto/data/cron.log 2>&1
   ```
   Para probar a mano: `php cron/actualizar.php` (o `php cron/actualizar.php TCP` para una sola terminal).

## De dónde salen los datos

### TCP
Se usa la misma consulta que hace la página pública https://mitcp.katoennatie.com.uy/
(Line-up): `https://api.katoennatie.com.uy/public/tcp/mitcp/frontend/v1/api/line-up`,
con la `api-key` que esa página envía desde cualquier navegador. Devuelve JSON con
`vessel`, `week`, `service`, `eta`, `etb`, `ets`, `notes`, entre otros datos.

- TCP **no publica estado**. El estado se deduce de las fechas: "Operando" entre ETB y ETS
  (fila verde), "Zarpado" después de ETS, "Atraque confirmado" si ya tiene ETB y
  "Programado" si no la tiene.
- TCP **no publica número de viaje**. Cada escala se identifica por buque + semana (`Sem. 41`).
- La columna ETD de TCP muestra la **ETS** que publica la terminal.

Si la `api-key` cambiara algún día, la lectura de TCP quedaría en rojo. La nueva clave se
obtiene igual que antes: F12 → Network → consulta `line-up` → encabezado `api-key`. Se copia en `config.php`.

### Montecon
Pendiente: https://online2.montecon.com.uy/. Falta obtener la consulta o la tabla que trae los buques.

### Probar una terminal
```bash
php cron/diagnostico.php TCP
```
Muestra qué propiedades trae la respuesta, cuál se usa para cada campo y los primeros
buques leídos, sin guardar nada en la base.

### Agregar o ajustar una terminal
Cada terminal se configura en `config.php`, en `terminales`:
- `parser => 'json_api'`: para consultas que devuelven JSON. Busca sola la lista de buques
  y asigna cada propiedad según `columnas`.
- `parser => 'tabla_html'`: para páginas con una tabla HTML. Busca la tabla cuyos
  encabezados coinciden con `columnas`.

En los dos casos los nombres no distinguen mayúsculas ni tildes. Otras opciones de cada terminal:
- `encabezados`: encabezados HTTP que se envían con la consulta.
- `formato`: cómo se muestra un campo, por ejemplo `'Sem. %s'`.
- `estado_por_fechas`: calcula el estado a partir de la ETB y la ETS.

Para no vaciar la lista por un error, una lectura que no devuelve buques no
modifica los datos guardados.

## Pruebas

```bash
php tests/prueba.php
```
Usa respuestas de ejemplo (`tests/fixtures/`, incluido un line-up real de TCP) y SQLite en memoria. No necesita internet ni MySQL.

Para probar el sitio completo sin MySQL, en `config.php` se puede poner `'driver' => 'sqlite'`.
