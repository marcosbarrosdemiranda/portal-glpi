# Projetos Compartilhados

Esta pasta é o **local de origem** dos projetos lidos pelo módulo
Projetos do Portal TI. Coloque aqui seus arquivos `.md` do Obsidian.

## Estrutura

Cada projeto deve ser uma subpasta com seu(s) arquivo(s) `.md` dentro:

```
projetos-compartilhados/
  ├── portal-glpi/
  │   └── portal-glpi-prd.md
  ├── migracao-servidores/
  │   └── migracao-servidores.md
  └── ... (cada técnico cria sua pasta)
```

## Como usar

1. Crie uma subpasta com o nome do seu projeto
2. Coloque os arquivos `.md` dentro dela
3. Execute o sync: `php sync_projetos.php`
4. Os projetos aparecem automaticamente no Portal

## Acesso pela rede

Se esta pasta estiver compartilhada na rede, configure em
`config_projetos.local.php`:

```php
define('ORIGEM_PROJETOS', '\\\\192.168.1.198\projetos-ti');
```

## Sync automático

No servidor do portal, agende no Task Scheduler:

```
Programa: C:\xampp\php\php.exe
Args: -f C:\xampp\htdocs\glpi2\portal-glpi\sync_projetos.php
Frequência: A cada 60 minutos
```
