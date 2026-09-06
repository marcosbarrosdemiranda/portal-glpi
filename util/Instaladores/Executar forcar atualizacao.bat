@echo off
setlocal
rem Forca um envio de inventario AGORA, sem reinstalar o agente.
rem Roda o "Forcar atualizacao.ps1" que esta NA MESMA PASTA deste .bat.

set "PS1=%~dp0Forcar atualizacao.ps1"
set "SERVERURL=https://ti.grupogmais.com:7412/glpi2/"
set "LOG=%SystemRoot%\Temp\glpi-agent-forcar.log"

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

echo Executando %PS1%
echo Log: %LOG%
"%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -ServerUrl "%SERVERURL%" -Verbose > "%LOG%" 2>&1
echo Saida do PowerShell: %errorlevel%
type "%LOG%"
pause
