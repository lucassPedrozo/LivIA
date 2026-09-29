<?php
/**
 * Fica aqui só porque Livia_Registro::instalar() faz
 * `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`, como manda o
 * WordPress. Nos testes ABSPATH aponta para tests/, e sem este arquivo a
 * chamada daria fatal antes de chegar ao que interessa. O dbDelta de mentira
 * mora em stubs-wp.php.
 */
