<?php
/**
 * pfsense_lojas.php — Atalho para Central pfSense (listagem de lojas)
 *
 * Redireciona para pfsense_proxy.php que gerencia o proxy automático
 * para firewalls pfSense das lojas sem necessidade de digitar senha.
 *
 * @package PortalTI
 * @see pfsense_proxy.php
 */

require_once __DIR__ . '/pfsense_proxy.php';
