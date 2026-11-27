<?php
/**
 * Copie para config.php e ajuste. Se config.php não existir, usam-se estes valores.
 */
return [
    // Pasta onde ficam os ficheiros das empresas (.ideia). Deve estar fora de pastas sincronizadas (Drive, Dropbox).
    'data_dir' => __DIR__ . '/data',

    // Pasta das cópias de segurança automáticas.
    'backup_dir' => __DIR__ . '/data/backups',

    // Por segurança a API só responde a pedidos do próprio computador (127.0.0.1 / ::1).
    // Mude para true apenas numa rede local de confiança.
    'allow_remote' => false,

    // Moeda e país por omissão.
    'currency' => 'CVE',
    'country'  => 'CV',
];
