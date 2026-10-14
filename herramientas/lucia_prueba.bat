@echo off
rem ==========================================================================
rem  Prueba del webservice de Lucia (DATOSCONT) sin PHP, usando curl de Windows.
rem  Doble clic sobre este archivo. Deja en esta misma carpeta:
rem    wsdl.xml       descripcion del servicio (sin datos de clientes)
rem    pedido.xml     lo que se envio
rem    respuesta.xml  lo que contesto Lucia (puede tener datos de clientes)
rem ==========================================================================
setlocal
cd /d "%~dp0"
set "URL=https://lucia.srv-luciatmgx16/Luciaws/awsdatcont.aspx"

where curl.exe >nul 2>nul
if errorlevel 1 (
    echo No se encontro curl.exe. Hace falta Windows 10 o mas nuevo.
    pause
    exit /b 1
)

echo.
echo 1) Descargando la descripcion del servicio (WSDL)...
curl.exe -sS -o wsdl.xml -w "   HTTP %%{http_code}\n" "%URL%?wsdl"
echo.

set /p CONT=2) Numero de contenedor (ej. MSKU1234567): 
set "QUE=LMF"
set /p QUE=   Que datos pedir: LMF o TOD [Enter = LMF]: 
set "USR="
set /p USR=   Usuario de Lucia [Enter = sin usuario]: 

(
echo ^<?xml version="1.0" encoding="utf-8"?^>
echo ^<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/"^>
echo   ^<Body^>
echo     ^<WSDatCont.DATOSCONT xmlns="LUCIAx"^>
echo       ^<Contenedor^>%CONT%^</Contenedor^>
echo       ^<Quedatos^>%QUE%^</Quedatos^>
echo     ^</WSDatCont.DATOSCONT^>
echo   ^</Body^>
echo ^</Envelope^>
) > pedido.xml

echo.
echo 3) Consultando Lucia...
if defined USR (
    rem Con -u usuario y sin clave, curl pide la clave sin mostrarla en pantalla.
    curl.exe -sS -u "%USR%" -H "Content-Type: text/xml; charset=utf-8" -H "SOAPAction: \"LUCIAxaction/AWSDATCONT.DATOSCONT\"" --data-binary @pedido.xml -o respuesta.xml -w "   HTTP %%{http_code}\n" "%URL%"
) else (
    curl.exe -sS -H "Content-Type: text/xml; charset=utf-8" -H "SOAPAction: \"LUCIAxaction/AWSDATCONT.DATOSCONT\"" --data-binary @pedido.xml -o respuesta.xml -w "   HTTP %%{http_code}\n" "%URL%"
)

echo.
echo ---------------- Respuesta ----------------
if exist respuesta.xml type respuesta.xml
echo.
echo -------------------------------------------
echo Se guardaron wsdl.xml, pedido.xml y respuesta.xml en:
echo   %CD%
echo Compartir wsdl.xml y respuesta.xml (tachando datos de clientes).
echo.
pause
