<?php

/**
 * Retorna só o primeiro nome do atendente para exibição.
 * GLPI armazena "realname firstname" — pega a última palavra (firstname).
 * Ex: "Barros de Miranda Marcos" → "Marcos"
 *     "Lima Cavalheiro Celso"    → "Celso"
 */
function primeiro_nome(string $nome): string {
    $partes = explode(' ', trim($nome));
    $ultimo = end($partes);
    return $ultimo ?: $nome;
}

/**
 * Retorna o nome do requerente como "Felix Agnelo" em vez de "Agnelo Felix".
 * GLPI armazena "realname firstname" — move a última palavra (firstname) para a frente.
 * Ex: "Agnelo Felix" → "Felix Agnelo"
 *     "Silva João"   → "João Silva"
 */
function nome_requerente(string $nome): string {
    $partes = explode(' ', trim($nome));
    if (count($partes) <= 1) return $nome;
    $ultimo = array_pop($partes);
    return $ultimo . ' ' . implode(' ', $partes);
}

/**
 * Retorna "Marcos B." para "Barros de Miranda Marcos"
 */
function apelido_atendente(string $nome): string {
    $partes = explode(' ', trim($nome));
    $ultimo = end($partes);
    if (count($partes) <= 1) return $ultimo ?: $nome;
    $primeira_letra = mb_substr(reset($partes), 0, 1) . '.';
    return $ultimo . ' ' . $primeira_letra;
}

/**
 * Função compartilhada de alias de entidades.
 * Converte o nome completo retornado pelo GLPI no apelido visual (Lj 001, Lj 003…).
 * Decodifica automaticamente entidades HTML (&#62; → >) que o GLPI retorna.
 */
function apelido_entidade($nome): string {
    if (is_array($nome)) $nome = $nome['name'] ?? $nome['completename'] ?? '';
    $nome = html_entity_decode($nome, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    static $mapa = [
        'Entidade raiz > Grupo Gmais'                                   => 'Grupo Gmais',
        'Entidade raiz > Grupo Gmais > Gmais ADM'                       => 'Lj 101',
        'Entidade raiz > Grupo Gmais > Rincão Atacadista - BTO'         => 'Lj 030',
        'Entidade raiz > Grupo Gmais > Supermercado Express - BTO'      => 'Lj 010',
        'Entidade raiz > Grupo Gmais > Supermercado Santos - BTO'       => 'Lj 001',
        'Entidade raiz > Grupo Gmais > Supermercado Santos - JDM'       => 'Lj 003',
    ];
    return $mapa[$nome] ?? $nome;
}
