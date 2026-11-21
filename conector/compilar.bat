@echo off
rem Compila RepaPOS-Conector.exe con el compilador de C# que ya trae Windows (no instala nada)
cd /d "%~dp0"
set ICONO=
if exist ..\..\RepaPOS-Escritorio\icono.ico set ICONO=/win32icon:..\..\RepaPOS-Escritorio\icono.ico
if exist icono.ico set ICONO=/win32icon:icono.ico
"%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe" /nologo /codepage:65001 /target:winexe /optimize+ %ICONO% ^
  /out:RepaPOS-Conector.exe /r:System.dll /r:System.Core.dll /r:System.Drawing.dll /r:System.Management.dll ^
  /r:System.Windows.Forms.dll /r:System.Xml.dll /r:System.Security.dll src\*.cs
if errorlevel 1 (
  echo.
  echo ERROR al compilar.
  pause
  exit /b 1
)
echo Listo: RepaPOS-Conector.exe
rem una copia para descargarlo desde RepaPOS (frontend/public/descargas → https://repapos.tech/descargas/RepaPOS-Conector.exe)
if exist ..\frontend\public (
  if not exist ..\frontend\public\descargas mkdir ..\frontend\public\descargas
  copy /y RepaPOS-Conector.exe ..\frontend\public\descargas\RepaPOS-Conector.exe >nul
  echo Copiado a frontend\public\descargas: se publica al compilar el frontend.
)
