@echo off
setlocal
rem ============================================================
rem  Instala o GLPI Agent do ZERO e envia o 1o inventario.
rem  Use so em PC que NAO tem o agente. Se ja tem, use "Repontar agente.bat".
rem  Rode como administrador (o .bat se auto-eleva).
rem ============================================================
set "PS1=%~dp0deploy-glpi-agent.ps1"
set "SERVERURL=https://192.168.1.198:7412/glpi2/"
set "LOG=%SystemRoot%\Temp\glpi-agent-deploy.log"

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
echo Instalando GLPI Agent -^> %SERVERURL%
echo Log: %LOG%
echo.
"%PWSH%" -NoProfile -ExecutionPolicy Bypass -File "%PS1%" -ServerUrl "%SERVERURL%" -NoSslCheck 1 -Version 1.15 -Freq daily -Verbose > "%LOG%" 2>&1
echo Saida do PowerShell: %errorlevel%
echo.
type "%LOG%"
echo.
echo ============================================================
echo  Confira: Programas e Recursos deve ter "GLPI Agent".
echo  E no portal: Inventario -^> PCs Retaguarda / PDVs (em ~minutos).
echo ============================================================
pause
