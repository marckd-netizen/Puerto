# Prueba del webservice de Lucia (awsdatcont), sin PHP.
# Se ejecuta con doble clic en lucia_prueba.bat (que esta en la misma carpeta).
# Pide la clave una sola vez y hace tres consultas:
#   1. wsdl.xml                    descripcion del servicio (sin datos de clientes)
#   2. respuesta_datoscont.xml     metodo DATOSCONT con Quedatos = TOD
#   3. respuesta_fechas.xml        metodo DATOSCONTFECHAS con un rango de fechas amplio
# La clave se guarda solo durante la prueba en un archivo temporal que se borra al final.

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$url = 'https://servicios.aduanas.gub.uy/luciaws/awsdatcont.aspx'

if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
    Write-Host 'No se encontro curl.exe. Hace falta Windows 10 o mas nuevo.'
    exit 1
}

$cont = (Read-Host 'Numero de contenedor (ej. MSKU1234567)').Trim().ToUpper() -replace '[^A-Z0-9]', ''
$diasAtras = Read-Host 'Dias hacia atras [Enter = 60]'
if (-not $diasAtras) { $diasAtras = 60 }
$diasAdelante = Read-Host 'Dias hacia adelante [Enter = 30]'
if (-not $diasAdelante) { $diasAdelante = 30 }
$desde = (Get-Date).AddDays(-1 * [int]$diasAtras).ToString('yyyy-MM-dd')
$hasta = (Get-Date).AddDays([int]$diasAdelante).ToString('yyyy-MM-dd')

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

function Consultar($titulo, $accion, $cuerpo, $archivoPedido, $archivoRespuesta) {
    Write-Host ''
    Write-Host "== $titulo"
    # Rutas absolutas: curl no ve la carpeta que fija Set-Location.
    $pedido = Join-Path $PSScriptRoot $archivoPedido
    $respuesta = Join-Path $PSScriptRoot $archivoRespuesta
    [IO.File]::WriteAllText($pedido, $cuerpo, $utf8)
    & curl.exe -sS -K $cfg -H 'Content-Type: text/xml; charset=utf-8' -H "SOAPAction: $accion" --data-binary "@$pedido" -o $respuesta -w '   HTTP %{http_code}' $url
    Write-Host ''
    if (Test-Path -LiteralPath $respuesta) { Get-Content -LiteralPath $respuesta | Write-Host }
}

try {
    Write-Host ''
    Write-Host '== 1) Descripcion del servicio (WSDL)'
    & curl.exe -sS -K $cfg -o (Join-Path $PSScriptRoot 'wsdl.xml') -w '   HTTP %{http_code}' ($url + '?wsdl')
    Write-Host ''

    $datoscont = @"
<?xml version="1.0" encoding="utf-8"?>
<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/">
  <Body>
    <WSDatCont.DATOSCONT xmlns="LUCIAx">
      <Contenedor>$cont</Contenedor>
      <Quedatos>TOD</Quedatos>
    </WSDatCont.DATOSCONT>
  </Body>
</Envelope>
"@
    Consultar '2) DATOSCONT (TOD)' 'LUCIAxaction/AWSDATCONT.DATOSCONT' $datoscont 'pedido_datoscont.xml' 'respuesta_datoscont.xml'

    $fechas = @"
<?xml version="1.0" encoding="utf-8"?>
<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/">
  <Body>
    <WSDatCont.DATOSCONTFECHAS xmlns="LUCIAx">
      <Fechainicial>$desde</Fechainicial>
      <Fechafinal>$hasta</Fechafinal>
      <Contenedor>$cont</Contenedor>
    </WSDatCont.DATOSCONTFECHAS>
  </Body>
</Envelope>
"@
    Consultar "3) DATOSCONTFECHAS del $desde al $hasta" 'LUCIAxaction/AWSDATCONT.DATOSCONTFECHAS' $fechas 'pedido_fechas.xml' 'respuesta_fechas.xml'
}
finally {
    Remove-Item -LiteralPath $cfg -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host '-------------------------------------------'
Write-Host "Archivos guardados en: $PSScriptRoot"
Write-Host 'Compartir wsdl.xml, respuesta_datoscont.xml y respuesta_fechas.xml (tachando datos de clientes).'
