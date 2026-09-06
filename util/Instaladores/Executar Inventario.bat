@echo off
setlocal
rem Instala/reconfigura o GLPI Agent e envia o 1o inventario.
rem Roda o deploy-glpi-agent.ps1 que esta NA MESMA PASTA deste .bat.

set "PS1=%~dp0deploy-glpi-agent.ps1"
set "SERVERURL=http://192.168.1.198/glpi2/"
set "LOG=%SystemRoot%\Temp\glpi-agent-deploy.log"

rem -- PowerShell 64-bit mesmo se o .bat abrir em contexto 32-bit
if exist "%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe" (
  set "PWSH=%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe"
) else (
  set "PWSH=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
)

rem -- exige admin; auto-eleva via UAC
net session >nul 2>&1
if %errorlevel% neq 0 (
  echo Elevando privilegios...
  "%PWSH%" -NoProfile -Command "Start-Process -Verb runas -FilePath '%~f0'"
  exit /b
)

echo Executando %PS1%
echo Log: %LOG%
"%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -ServerUrl "%SERVERURL%" -Version 1.15 -Freq daily -Verbose > "%LOG%" 2>&1
echo Saida do PowerShell: %errorlevel%
type "%LOG%"
pause
