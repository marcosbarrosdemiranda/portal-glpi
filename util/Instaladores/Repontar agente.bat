@echo off
setlocal
rem ============================================================
rem  REPONTAR o GLPI Agent ja instalado para o endereco novo.
rem  Nao reinstala. Rode como Administrador em cada PC.
rem  (o .bat se auto-eleva via UAC)
rem ============================================================
set "PS1=%~dp0Repontar agente.ps1"
set "SERVERURL=https://192.168.1.198:7412/glpi2/"
set "LOG=%SystemRoot%\Temp\glpi-agent-repontar.log"

if exist "%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe" (
  set "PWSH=%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe"
) else (
  set "PWSH=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
)

net session >nul 2>&1
if %errorlevel% neq 0 (
  echo Elevando privilegios...
  "%PWSH%" -NoProfile -Command "Start-Process -Verb runas -FilePath '%~f0'"
  exit /b
)

echo.
echo Repontando o GLPI Agent para %SERVERURL%
echo Log: %LOG%
echo.
"%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -ServerUrl "%SERVERURL%" -NoSslCheck 1 > "%LOG%" 2>&1
echo Saida: %errorlevel%
echo.
type "%LOG%"
echo.
echo ============================================================
echo  Pronto. Confira em alguns minutos no portal:
echo  Inventario -^> PCs Retaguarda / PDVs  (o aviso "sem inventario" some)
echo ============================================================
pause
