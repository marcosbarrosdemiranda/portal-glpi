<?php
require_once 'agenda/db.php';
\ = \->query("SELECT ip, loja FROM portal_pfsense_lojas WHERE ativo = 1");
print_r(\->fetchAll(PDO::FETCH_ASSOC));
