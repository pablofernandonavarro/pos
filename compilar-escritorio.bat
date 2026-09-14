@echo off
REM Compila la app de escritorio (NativePHP). Ver compilar-escritorio.ps1.
REM Ejemplo: compilar-escritorio.bat -Version 1.3.0
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0compilar-escritorio.ps1" %*
if errorlevel 1 pause
