<?php

// El archivo distribuido completa opciones UI; no contiene credenciales.
require __DIR__.'/config.inc.php-dist';
$conf['servers'] = [[
    'desc' => 'SysForest · PostgreSQL',
    'host' => 'postgres', 'port' => 5432, 'sslmode' => 'disable',
    'defaultdb' => 'sysforest',
    'pg_dump_path' => '',
    'pg_dumpall_path' => '',
]];
$conf['default_lang'] = 'spanish';
$conf['extra_login_security'] = true;
$conf['extra_session_security'] = true;
$conf['owned_only'] = false;
$conf['show_system'] = false;
$conf['show_advanced'] = false;
$conf['show_oids'] = false;
$conf['plugins'] = [];
$conf['help_base'] = 'https://www.postgresql.org/docs/17/';
