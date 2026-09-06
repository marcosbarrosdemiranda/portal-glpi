@echo off
setlocal
rem Startup script da GPO - chama o deploy-glpi-agent.ps1 desta mesma pasta.
set "BATLOG=%SystemRoot%\Temp\glpi-gpo-bat.log"
echo [%date% %time%] (startup) iniciando... >> "%BATLOG%"

if exist "%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe" (
  set "PWSH=%SystemRoot%\Sysnative\WindowsPowerShell\v1.0\powershell.exe"
) else (
  set "PWSH=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
)

set "PS1=%~dp0deploy-glpi-agent.ps1"
"%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -ServerUrl "https://ti.grupogmais.com:7412/glpi2/" -Version 1.15 -Freq daily

echo [%date% %time%] (startup) fim. erro=%errorlevel% >> "%BATLOG%"
endlocal
exit /b 0
