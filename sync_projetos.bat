@echo off
REM sync_projetos.bat — Task Scheduler: sincroniza projetos da rede para o portal
REM
REM Instalação no Task Scheduler do servidor (192.168.1.198):
REM   1. Abrir taskschd.msc
REM   2. Criar tarefa básica: "Sync Projetos TI"
REM   3. Trigger: Diário, repetir a cada 60 minutos
REM   4. Ação:
REM        Programa:  C:\xampp\php\php.exe
REM        Argumentos: -f C:\xampp\htdocs\glpi2\portal-glpi\sync_projetos.php
REM        Iniciar em: C:\xampp\htdocs\glpi2\portal-glpi\
REM
REM   Testar: clicar em "Executar" na tarefa
REM
REM Se o PHP não estiver em C:\xampp\php\, ajuste o caminho acima.

echo [%date% %time%] Iniciando sync de projetos...
C:\xampp\php\php.exe -f C:\xampp\htdocs\glpi2\portal-glpi\sync_projetos.php
echo [%date% %time%] Sync concluído.
