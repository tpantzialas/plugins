<?php
/* Copy to config.php OUTSIDE the public document root and replace every value. */
return array(
	'app_origin' => 'https://danae.11ads.gr',
	/* Web Push contact shown to notification services; use a monitored address. */
	'vapid_subject' => 'mailto:info@11ads.gr',
	'database' => array(
		'host' => 'localhost',
		'name' => 'CHANGE_ME',
		'user' => 'CHANGE_ME',
		'password' => 'CHANGE_ME',
		'charset' => 'utf8mb4',
	),
	/* Generate with: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' */
	'encryption_key' => 'REPLACE_WITH_BASE64_32_BYTE_KEY',
	/* Long random setup secret. Remove it from config after the first account is created. */
	'setup_key' => 'REPLACE_WITH_A_LONG_RANDOM_SETUP_SECRET',
	'session_name' => 'danae_mobile',
);
