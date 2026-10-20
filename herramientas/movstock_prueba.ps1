# Consulta un movimiento de stock en Lucia (webservice awsmovstock, operacion Execute).
# SOLO CONSULTA: este servicio no permite endosar ni modificar nada.
# Se ejecuta con doble clic en movstock_prueba.bat (en la misma carpeta).
# Los datos del movimiento salen de la consulta del contenedor (seccion MOVIMSTOCK):
#   DEPOSITO, MOVSTOCK (numero), ANO y TIPO (ENT o SAL).
# Deja el pedido en movstock_pedido.xml y la respuesta en movstock_respuesta.xml.

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$url = 'https://servicios.aduanas.gub.uy/LuciaWS/awsmovstock.aspx'

if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
    Write-Host 'No se encontro curl.exe. Hace falta Windows 10 o mas nuevo.'
    exit 1
}

$deposito = (Read-Host 'Deposito (ej. 1694)').Trim()
$numero = (Read-Host 'Numero de movimiento de stock (ej. 20607)').Trim()
$anio = (Read-Host 'Anio del movimiento [Enter = este anio]').Trim()
if (-not $anio) { $anio = (Get-Date).Year }
$tipo = (Read-Host 'Tipo de movimiento [Enter = ENT]').Trim().ToUpper()
if (-not $tipo) { $tipo = 'ENT' }
if ($numero -notmatch '^[0-9]+$' -or "$anio" -notmatch '^[0-9]{4}$') {
    Write-Host 'El numero de movimiento y el anio tienen que ser numeros.'
    exit 1
}

$usr = Read-Host 'Usuario de Lucia'
$sec = Read-Host 'Clave de Lucia' -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
$clave = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

$utf8 = New-Object System.Text.UTF8Encoding($false)
$cfg = Join-Path $env:TEMP ('lucia_' + [guid]::NewGuid().ToString() + '.cfg')
$credencial = ($usr + ':' + $clave).Replace('\', '\\').Replace('"', '\"')
[IO.File]::WriteAllText($cfg, 'user = "' + $credencial + '"' + "`n", $utf8)
$clave = $null; $credencial = $null

$pedido = Join-Path $PSScriptRoot 'movstock_pedido.xml'
$respuesta = Join-Path $PSScriptRoot 'movstock_respuesta.xml'
$cuerpo = @"
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:lux="LUCIAx">
  <soap:Body>
    <lux:WSMovStock.Execute>
      <lux:Cgdepidstk>$deposito</lux:Cgdepidstk>
      <lux:Cgmovskid>$numero</lux:Cgmovskid>
      <lux:Cganomov>$anio</lux:Cganomov>
      <lux:Cgmovtpo>$tipo</lux:Cgmovtpo>
    </lux:WSMovStock.Execute>
  </soap:Body>
</soap:Envelope>
"@
[IO.File]::WriteAllText($pedido, $cuerpo, $utf8)

try {
    Write-Host ''
    Write-Host "== Movimiento $deposito / $numero / $anio / $tipo"
    & curl.exe -sS -K $cfg -H 'Content-Type: text/xml; charset=utf-8' -H 'SOAPAction: LUCIAxaction/AWSMOVSTOCK.Execute' --data-binary "@$pedido" -o $respuesta -w '   HTTP %{http_code}' $url
    Write-Host ''
} finally {
    Remove-Item -LiteralPath $cfg -ErrorAction SilentlyContinue
}

if (Test-Path -LiteralPath $respuesta) {
    try {
        $xml = [xml](Get-Content -LiteralPath $respuesta -Raw)
        $m = $xml.SelectSingleNode("//*[local-name()='Movimientostock']")
        if ($m) {
            foreach ($campo in 'ESTADO_MOV', 'NOM_CONSIG', 'DOC_CONSIG', 'NRO_DOC_CONSIG', 'LUGAR_ENDOSO', 'FECHA_ENDOSO', 'BULTOS', 'BULTOS_SALIDOS', 'DESCRIPCION_MOV', 'OPERACION_PENDIENTE') {
                $n = $m.SelectSingleNode("*[local-name()='$campo']")
                Write-Host ('   {0,-20} {1}' -f $campo, $(if ($n) { $n.InnerText.Trim() } else { '' }))
            }
        } else {
            Get-Content -LiteralPath $respuesta | Select-Object -First 40 | Write-Host
        }
    } catch {
        Get-Content -LiteralPath $respuesta | Select-Object -First 40 | Write-Host
    }
    Write-Host ''
    Write-Host "Respuesta completa en $respuesta"
    Write-Host 'Mandamela para integrarla al sitio (si tiene datos de clientes, podes tapar nombres y documentos).'
}
