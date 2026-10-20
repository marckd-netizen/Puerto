# Descarga la descripcion (WSDL) de un webservice de Lucia, para ver que operaciones tiene.
# SOLO LEE: no ejecuta ninguna operacion ni modifica nada en Lucia.
# Se ejecuta con doble clic en lucia_wsdl.bat (en la misma carpeta).
# Deja el resultado en <nombre del servicio>_wsdl.xml, en esta carpeta.

$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$porDefecto = 'https://servicios.aduanas.gub.uy/awsstockporconsignatario.aspx?wsdl'

if (-not (Get-Command curl.exe -ErrorAction SilentlyContinue)) {
    Write-Host 'No se encontro curl.exe. Hace falta Windows 10 o mas nuevo.'
    exit 1
}

$url = (Read-Host "Direccion del WSDL [Enter = $porDefecto]").Trim()
if (-not $url) { $url = $porDefecto }
if ($url -notmatch '\?wsdl$') { $url = $url + '?wsdl' }
$nombre = ([uri]$url).Segments[-1] -replace '\.aspx$', ''
$salida = Join-Path $PSScriptRoot ($nombre + '_wsdl.xml')

# Direcciones a probar: la indicada y, si no esta bajo /luciaws/, tambien con /luciaws/ (donde estan los otros servicios).
$direcciones = @($url)
if ($url -notmatch '/luciaws/') {
    $direcciones += ($url -replace '(https://[^/]+)/', '$1/luciaws/')
}

function EsWsdl() {
    (Test-Path -LiteralPath $salida) -and ((Get-Content -LiteralPath $salida -Raw) -match '<(wsdl:)?definitions')
}

$cfg = $null
try {
    foreach ($d in $direcciones) {
        Write-Host "== $d"
        & curl.exe -sS -o $salida -w '   HTTP %{http_code}' $d
        Write-Host ''
        if (EsWsdl) { break }
        if (-not $cfg) {
            Write-Host '   Sin WSDL todavia: se prueba con el usuario de Lucia.'
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
        }
        Write-Host "== $d (con usuario)"
        & curl.exe -sS -K $cfg -o $salida -w '   HTTP %{http_code}' $d
        Write-Host ''
        if (EsWsdl) { break }
    }
} finally {
    if ($cfg) { Remove-Item -LiteralPath $cfg -ErrorAction SilentlyContinue }
}

Write-Host ''
if (EsWsdl) {
    Write-Host "Listo: se guardo $salida"
    Write-Host 'Operaciones que ofrece el servicio:'
    ([xml](Get-Content -LiteralPath $salida -Raw)).SelectNodes("//*[local-name()='portType']/*[local-name()='operation']") |
        ForEach-Object { Write-Host ('   - ' + $_.name) }
    Write-Host ''
    Write-Host 'Mandame ese archivo (describe el servicio; no tiene datos de clientes ni la clave).'
} else {
    Write-Host 'No se pudo obtener el WSDL. Respuesta recibida:'
    if (Test-Path -LiteralPath $salida) { Get-Content -LiteralPath $salida | Select-Object -First 30 | Write-Host }
}
