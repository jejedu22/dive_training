<?php
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Clés JWT de test générées à la volée (passphrase "test", cf. phpunit.xml.dist).
$dir = dirname(__DIR__).'/config/jwt';
if (!is_file("$dir/private.pem")) {
    @mkdir($dir, 0775, true);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private, 'test');
    file_put_contents("$dir/private.pem", $private);
    file_put_contents("$dir/public.pem", openssl_pkey_get_details($key)['key']);
}
