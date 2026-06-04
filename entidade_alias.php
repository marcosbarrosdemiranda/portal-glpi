<?php
/**
 * Função compartilhada de alias de entidades.
 * Converte o nome completo retornado pelo GLPI no apelido visual (Lj 001, Lj 003…).
 * Decodifica automaticamente entidades HTML (&#62; → >) que o GLPI retorna.
 */
function apelido_entidade(string $nome): string {
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
