@echo off
REM Spustit jako Administrator!
REM Registruje vsechny sync schedulery do Windows Task Scheduler (spusti pri startu systemu)
REM Parametry: --no-pause   preskoci pauzy (vhodne pro automatizovane spousteni)

set BASE=C:\laragon\www\pisek\v3.4\scripts
set "HAS_ERROR=0"
set "NO_PAUSE=0"
if "%~1"=="--no-pause" set "NO_PAUSE=1"

schtasks /create ^
  /tn "PisekDvdImportSync" ^
  /tr "powershell.exe -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File \"%BASE%\sync_dvd_import_scheduler.ps1\"" ^
  /sc ONSTART ^
  /ru "SYSTEM" ^
  /rl HIGHEST ^
  /f
if errorlevel 1 (
  echo [ERROR] Nepodarilo se registrovat task PisekDvdImportSync.
  set "HAS_ERROR=1"
)

schtasks /create ^
  /tn "PisekIkosWorkersSync" ^
  /tr "powershell.exe -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File \"%BASE%\sync_ikos_workers_scheduler.ps1\"" ^
  /sc ONSTART ^
  /ru "SYSTEM" ^
  /rl HIGHEST ^
  /f
if errorlevel 1 (
  echo [ERROR] Nepodarilo se registrovat task PisekIkosWorkersSync.
  set "HAS_ERROR=1"
)

echo.
if "%HAS_ERROR%"=="0" (
  echo Tasky zaregistrovany:
  echo - PisekDvdImportSync
  echo - PisekIkosWorkersSync
  echo Schedulery se automaticky spusti pri startu systemu ^(bez nutnosti prihlaseni uzivatele^).
  if "%NO_PAUSE%"=="0" pause
  exit /b 0
) else (
  echo Registrace tasku skoncila s chybou. Spustte tento skript jako Administrator.
  if "%NO_PAUSE%"=="0" pause
  exit /b 1
)
