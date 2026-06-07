# CONTRIBUTING.md — AI & Human Engineering Contract

> Este documento define o contrato estrito de engenharia, padroes arquiteturais e seguranca para contribuicoes humanas e de agentes de IA.

## 1. Diretrizes de Seguranca (Security by Design - CRITICO)
- **Zero Secrets:** Nunca versione chaves de API ou tokens. Utilize sempre process.env. Arquivo .env.example e obrigatorio.
- **Validacao Estrita de Entrada:** Todo dado externo e malicioso. Use Zod na camada periferica (Controllers/API Routes) antes de qualquer mutacao de estado ou banco de dados.
- **Prevencao Destrutiva:** Agentes de IA estao terminantemente proibidos de executar delecoes de arquivos, refatoracoes globais destrutivas ou alteracao/drop de tabelas sem autorizacao explicita do desenvolvedor lider.

## 2. Arquitetura em Camadas e Isolacao (SoC)
- **Separacao Rigorosa:** UI/Controllers e Infraestrutura devem ser isolados do Core Domain (Regras de Negocio).
- **Independencia de Frameworks:** O dominio de negocio nao deve vazar para componentes do framework externo.
- **Modularidade:** Estruture por dominios/features, nao por diretorios massivos de tipos (ex: /controllers global).

## 3. Padrao de Entrega de Codigo (Para Agentes de IA)
- **Completude Obrigatoria:** Sempre retorne o codigo completo. E estritamente proibido retornar snippets fragmentados ou usar marcacoes preguiçosas para ocultar codigo.
- **Zero Lixo Tecnico:** Remova codigos mortos e console.log antes de entregar.
- **Clean Code e SOLID:** Single Responsibility, nomes descritivos e baixo acoplamento sempre.

## 4. Padroes de Linguagem (React + TypeScript)
- **TypeScript Estrito:** Uso de any ou as any e proibido. Utilize inferencia inteligente e Generics.
- **Componentizacao:** Extraia logicas complexas para Hooks customizados; mantenha os componentes visuais puros.
- **Tratamento de Erros:** Proibido silenciar erros. Mapeie erros de dominio de forma limpa.
