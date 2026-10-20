# Descarga la descripcion (WSDL) del webservice awsmovstock de Lucia, para ver que operaciones tiene.
# SOLO LEE: no ejecuta ninguna operacion ni modifica nada en Lucia.
# Se ejecuta con doble clic en movstock_wsdl.bat (en la misma carpeta).
# Deja el resultado en movstock_wsdl.xml, en esta carpeta.

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$url = 'https://servicios.aduanas.gub.uy/luciaws/awsmovstock.aspx?wsdl'
$salida = Join-Path $PSScriptRoot 'movstock_wsdl.xml'

if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
    Write-Host 'No se encontro curl.exe. Hace falta Windows 10 o mas nuevo.'
    exit 1
}

Write-Host '== 1. Sin usuario'
& curl.exe -sS -o $salida -w '   HTTP %{http_code}' $url
Write-Host ''
$codigo = 0
if (Test-Path -LiteralPath $salida) {
    $texto = Get-Content -LiteralPath $salida -Raw
    if ($texto -match '<(wsdl:)?definitions') { $codigo = 200 }
}

if ($codigo -ne 200) {
    Write-Host '   Pide usuario y clave (o no respondio el WSDL). Se prueba con el usuario de Lucia.'
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
    try {
        Write-Host '== 2. Con usuario'
        & curl.exe -sS -K $cfg -o $salida -w '   HTTP %{http_code}' $url
        Write-Host ''
    } finally {
        Remove-Item -LiteralPath $cfg -ErrorAction SilentlyContinue
    }
}

Write-Host ''
if ((Test-Path -LiteralPath $salida) -and ((Get-Content -LiteralPath $salida -Raw) -match '<(wsdl:)?definitions')) {
    Write-Host "Listo: se guardo $salida"
    Write-Host 'Operaciones que ofrece el servicio:'
    ([xml](Get-Content -LiteralPath $salida -Raw)).SelectNodes("//*[local-name()='portType']/*[local-name()='operation']") |
        ForEach-Object { Write-Host ('   - ' + $_.name) }
    Write-Host ''
    Write-Host 'Mandame el archivo movstock_wsdl.xml (no tiene datos de clientes ni la clave).'
} else {
    Write-Host 'No se pudo obtener el WSDL. Respuesta recibida:'
    if (Test-Path -LiteralPath $salida) { Get-Content -LiteralPath $salida | Select-Object -First 30 | Write-Host }
}
