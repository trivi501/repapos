@echo off
rem Compila RepaPOS-Conector.exe con el compilador de C# que ya trae Windows (no instala nada)
cd /d "%~dp0"
set ICONO=
if exist ..\..\RepaPOS-Escritorio\icono.ico set ICONO=/win32icon:..\..\RepaPOS-Escritorio\icono.ico
if exist icono.ico set ICONO=/win32icon:icono.ico
"%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\csc.exe" /nologo /codepage:65001 /target:winexe /optimize+ %ICONO% ^
  /out:RepaPOS-Conector.exe /r:System.dll /r:System.Core.dll /r:System.Drawing.dll /r:System.Management.dll ^
  /r:System.Windows.Forms.dll /r:System.Xml.dll src\*.cs
if errorlevel 1 (
  echo.
  echo ERROR al compilar.
  pause
  exit /b 1
)
echo Listo: RepaPOS-Conector.exe
