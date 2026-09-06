@echo off
setlocal
rem So troca a URL do GLPI Agent ja instalado e forca um envio (nao reinstala).
set "PS1=%~dp0Repontar agente.ps1"
set "SERVERURL=http://192.168.1.198/glpi2/"
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

echo Executando %PS1%
"%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -ServerUrl "%SERVERURL%" > "%LOG%" 2>&1
echo Saida: %errorlevel%   Log: %LOG%
type "%LOG%"
pause
