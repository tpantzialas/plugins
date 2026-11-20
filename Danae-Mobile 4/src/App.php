<?php
declare(strict_types=1);

namespace DanaeMobile;

final class App {
	private static array $config;
	private static string $root;
	private static ?\PDO $db = null;

	public static function run(array $config, string $root): void {
		self::$config = $config;
		self::$root = $root;
		self::securityHeaders();
		self::session();
		try {
			self::db();
			self::ensurePushSchema();
			$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
			$path = '/' . trim(rawurldecode($path), '/');
			if ($path === '//') { $path = '/'; }
			if (str_starts_with($path, '/api/')) { self::api($path); return; }
			self::page();
		} catch (\Throwable $error) {
			error_log('Danae Mobile request failed: ' . $error->getMessage());
			if (isset($path) && str_starts_with($path, '/api/')) { self::json(['error' => 'Προσωρινό σφάλμα υπηρεσίας. Έλεγξε τη ρύθμιση και ξαναδοκίμασε.'], 500); return; }
			http_response_code(503); header('Content-Type: text/html; charset=utf-8');
			echo '<!doctype html><html lang="el"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ρύθμιση Danae Mobile</title><body style="font:16px system-ui;background:#061020;color:#f4f8fb;padding:10vh 8vw"><h1>Η αρχική ρύθμιση δεν ολοκληρώθηκε</h1><p>Έλεγξε τη βάση MySQL, το αρχείο config.php και τις οδηγίες εγκατάστασης του Danae Mobile.</p></body></html>';
		}
	}

	private static function securityHeaders(): void {
		header('X-Content-Type-Options: nosniff');
		header('X-Frame-Options: DENY');
		header('Referrer-Policy: strict-origin-when-cross-origin');
		header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
		header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
	}

	private static function session(): void {
		$secure = str_starts_with((string) (self::$config['app_origin'] ?? ''), 'https://');
		ini_set('session.use_strict_mode', '1');
		ini_set('session.use_only_cookies', '1');
		ini_set('session.cookie_httponly', '1');
		ini_set('session.cookie_secure', $secure ? '1' : '0');
		ini_set('session.cookie_samesite', 'Strict');
		session_name((string) (self::$config['session_name'] ?? 'danae_mobile'));
		session_start();
		if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(24)); }
	}

	private static function db(): \PDO {
		if (self::$db instanceof \PDO) { return self::$db; }
		$d = self::$config['database'] ?? [];
		foreach (['host', 'name', 'user', 'password'] as $required) {
			if (!isset($d[$required]) || $d[$required] === '' || $d[$required] === 'CHANGE_ME') { throw new \RuntimeException('Database configuration is incomplete'); }
		}
		$dsn = 'mysql:host=' . $d['host'] . ';dbname=' . $d['name'] . ';charset=' . ($d['charset'] ?? 'utf8mb4');
		self::$db = new \PDO($dsn, $d['user'], $d['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]);
		return self::$db;
	}

	private static function api(string $path): void {
		$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
		if ($path === '/api/setup' && $method === 'POST') { self::setup(); return; }
		if ($path === '/api/pair/complete' && $method === 'POST') { self::pairComplete(); return; }
		if ($path === '/api/orders/event' && $method === 'POST') { self::orderEvent(); return; }
		if ($path === '/api/login' && $method === 'POST') { self::login(); return; }
		if ($path === '/api/logout' && $method === 'POST') { self::requireAuth(); self::csrf(); $_SESSION = []; session_regenerate_id(true); self::json(['ok' => true]); return; }
		self::requireAuth();
		if ($method !== 'GET') { self::csrf(); }
		if ($path === '/api/me' && $method === 'GET') { self::me(); return; }
		if ($path === '/api/push/key' && $method === 'GET') { self::pushKey(); return; }
		if ($path === '/api/push/subscribe' && $method === 'POST') { self::pushSubscribe(); return; }
		if ($path === '/api/push/unsubscribe' && $method === 'POST') { self::pushUnsubscribe(); return; }
		if ($path === '/api/pair/start' && $method === 'POST') { self::pairStart(); return; }
		if ($path === '/api/sites' && $method === 'GET') { self::sites(); return; }
		if (preg_match('#^/api/orders/(\d+)(?:/(\d+))?$#', $path, $m)) { self::ordersProxy((int) $m[1], isset($m[2]) ? (int) $m[2] : 0, $method); return; }
		if (preg_match('#^/api/sales/(\d+)$#', $path, $m)) { self::salesProxy((int) $m[1], $method); return; }
		if (preg_match('#^/api/social/(\d+)/(site|places|categories|items|links|analytics)$#', $path, $m)) { self::socialProxy((int) $m[1], $m[2], $method); return; }
		self::json(['error' => 'Η διαδρομή δεν βρέθηκε.'], 404);
	}

	private static function setup(): void {
		self::csrf();
		if (!self::rateLimit('setup:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 3600)) { self::json(['error' => 'Πάρα πολλές προσπάθειες. Δοκίμασε αργότερα.'], 429); }
		$count = (int) self::db()->query('SELECT COUNT(*) FROM dm_users')->fetchColumn();
		$key = (string) (self::$config['setup_key'] ?? '');
		$body = self::body();
		if ($count > 0 || $key === '' || !hash_equals($key, (string) ($body['setup_key'] ?? ''))) { self::json(['error' => 'Η αρχική ρύθμιση δεν είναι διαθέσιμη ή ο κωδικός δεν είναι σωστός.'], 403); }
		$user = self::validUsername($body['username'] ?? ''); $password = (string) ($body['password'] ?? '');
		if (!$user || strlen($password) < 12) { self::json(['error' => 'Βάλε email και κωδικό τουλάχιστον 12 χαρακτήρων.'], 422); }
		$q = self::db()->prepare('INSERT INTO dm_users (username,password_hash,created_at) VALUES (?,?,UTC_TIMESTAMP())');
		$q->execute([$user, password_hash($password, PASSWORD_DEFAULT)]);
		self::authenticate((int) self::db()->lastInsertId(), $user);
		self::json(['ok' => true]);
	}

	private static function login(): void {
		self::csrf(); $body = self::body(); $user = self::validUsername($body['username'] ?? ''); $password = (string) ($body['password'] ?? '');
		if (!self::rateLimit('login:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 25, 3600)) { self::json(['error' => 'Έγιναν πολλές προσπάθειες σύνδεσης. Δοκίμασε ξανά σε λίγο.'], 429); }
		$q = self::db()->prepare('SELECT id,username,password_hash FROM dm_users WHERE username=? LIMIT 1'); $q->execute([$user ?? '']); $row = $q->fetch();
		if (!$row || !password_verify($password, $row['password_hash'])) { self::json(['error' => 'Το email ή ο κωδικός δεν είναι σωστά.'], 401); }
		if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) { $up = self::db()->prepare('UPDATE dm_users SET password_hash=? WHERE id=?'); $up->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]); }
		self::authenticate((int) $row['id'], $row['username']); self::json(['ok' => true]);
	}

	private static function authenticate(int $id, string $username): void { session_regenerate_id(true); $_SESSION['user_id'] = $id; $_SESSION['username'] = $username; $_SESSION['csrf'] = bin2hex(random_bytes(24)); }
	private static function requireAuth(): void { if (empty($_SESSION['user_id'])) { self::json(['error' => 'Συνδέσου ξανά για να συνεχίσεις.'], 401); } }
	private static function csrf(): void {
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { return; }
		$received = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
		if (!isset($_SESSION['csrf']) || $received === '' || !hash_equals((string) $_SESSION['csrf'], $received)) { self::json(['error' => 'Η ασφαλής συνεδρία έληξε. Φόρτωσε ξανά τη σελίδα.'], 419); }
	}

	private static function me(): void { self::json(['user' => ['username' => $_SESSION['username'] ?? ''], 'csrf' => $_SESSION['csrf'] ?? '', 'sites' => self::siteRows()]); }
	private static function sites(): void { self::json(['sites' => self::siteRows()]); }
	private static function siteRows(): array {
		$q = self::db()->prepare("SELECT id,site_name,site_url,plugin_version,last_seen_at FROM dm_sites WHERE user_id=? AND status='active' ORDER BY site_name"); $q->execute([(int) $_SESSION['user_id']]); return $q->fetchAll();
	}

	private static function pairStart(): void {
		self::requireAuth(); self::csrf();
		self::db()->exec('DELETE FROM dm_pairings WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)');
		$code = strtoupper(bin2hex(random_bytes(6)));
		$hash = hash('sha256', $code);
		$q = self::db()->prepare('INSERT INTO dm_pairings (code_hash,user_id,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))'); $q->execute([$hash, (int) $_SESSION['user_id']]);
		self::json(['code' => substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4), 'expires_in' => 600]);
	}

	private static function pairComplete(): void {
		$body = self::body();
		$code = strtoupper(preg_replace('/[^A-F0-9]/', '', (string) ($body['code'] ?? '')));
		if (strlen($code) !== 12) { self::json(['error' => 'Ο κωδικός σύνδεσης δεν είναι έγκυρος.'], 400); }
		$q = self::db()->prepare('SELECT user_id FROM dm_pairings WHERE code_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1'); $q->execute([hash('sha256', $code)]); $pair = $q->fetch();
		if (!$pair) { self::json(['error' => 'Ο κωδικός έληξε ή χρησιμοποιήθηκε. Δημιούργησε νέο κωδικό.'], 410); }
		$siteUrl = self::validatedSiteUrl((string) ($body['site_url'] ?? ''));
		$apiUrl = self::validatedApiUrl((string) ($body['api_url'] ?? ''));
		$token = (string) ($body['token'] ?? '');
		if (!$siteUrl || !$apiUrl || !preg_match('/^danae_mobile_[a-f0-9]{64}$/', $token)) { self::json(['error' => 'Τα στοιχεία σύνδεσης του WordPress δεν είναι έγκυρα.'], 422); }
		$info = self::wpRequest($apiUrl . '/social/site', 'GET', $token);
		if (!is_array($info) || empty($info['name']) || empty($info['url'])) { self::json(['error' => 'Δεν επιβεβαιώθηκε η γέφυρα WordPress. Έλεγξε HTTPS, plugin και κλειδί.'], 422); }
		$canonical = self::validatedSiteUrl((string) $info['url']);
		if (!$canonical || strtolower(rtrim($canonical, '/')) !== strtolower(rtrim($siteUrl, '/'))) { self::json(['error' => 'Η διεύθυνση site που επέστρεψε το WordPress δεν ταιριάζει.'], 422); }
		$fp = hash('sha256', strtolower(rtrim($siteUrl, '/')));
		$callbackSecret = bin2hex(random_bytes(32));
		try {
			self::db()->beginTransaction();
			$consume = self::db()->prepare('UPDATE dm_pairings SET used_at=UTC_TIMESTAMP() WHERE code_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP()'); $consume->execute([hash('sha256', $code)]);
			if ($consume->rowCount() !== 1) { self::db()->rollBack(); self::json(['error' => 'Ο κωδικός χρησιμοποιήθηκε ήδη.'], 409); }
			$encrypted = self::encrypt($token);
			$callbackCiphertext = self::encrypt($callbackSecret);
			$q = self::db()->prepare("INSERT INTO dm_sites (user_id,site_fingerprint,site_name,site_url,api_url,token_ciphertext,callback_secret_ciphertext,plugin_version,status,created_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?,'active',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE site_name=VALUES(site_name),site_url=VALUES(site_url),api_url=VALUES(api_url),token_ciphertext=VALUES(token_ciphertext),callback_secret_ciphertext=VALUES(callback_secret_ciphertext),plugin_version=VALUES(plugin_version),status='active',last_seen_at=UTC_TIMESTAMP()");
			$q->execute([(int) $pair['user_id'], $fp, mb_substr((string) $info['name'], 0, 190), $siteUrl, $apiUrl, $encrypted, $callbackCiphertext, mb_substr((string) ($body['plugin_version'] ?? $info['plugin_version'] ?? ''), 0, 32)]);
			$siteId = (int) self::db()->lastInsertId();
			if ($siteId === 0) { $find = self::db()->prepare('SELECT id FROM dm_sites WHERE user_id=? AND site_fingerprint=?'); $find->execute([(int) $pair['user_id'], $fp]); $siteId = (int) $find->fetchColumn(); }
			self::audit((int) $pair['user_id'], $siteId, 'site_paired'); self::db()->commit();
		} catch (\Throwable $error) { if (self::db()->inTransaction()) { self::db()->rollBack(); } throw $error; }
		self::json(['connected' => true, 'order_event_secret' => $callbackSecret]);
	}

	/** Create additive tables/columns so existing installations upgrade without re-importing schema.sql. */
	private static function ensurePushSchema(): void {
		$db = self::db();
		$columns = $db->query("SHOW COLUMNS FROM dm_sites LIKE 'callback_secret_ciphertext'")->fetchAll();
		if (!$columns) { $db->exec('ALTER TABLE dm_sites ADD COLUMN callback_secret_ciphertext MEDIUMTEXT NULL AFTER token_ciphertext'); }
		$db->exec("CREATE TABLE IF NOT EXISTS dm_push_subscriptions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,endpoint_hash CHAR(64) NOT NULL,endpoint_ciphertext MEDIUMTEXT NOT NULL,p256dh VARCHAR(100) NOT NULL,auth_ciphertext MEDIUMTEXT NOT NULL,created_at DATETIME NOT NULL,last_seen_at DATETIME NOT NULL,PRIMARY KEY(id),UNIQUE KEY endpoint_unique(endpoint_hash),KEY user_idx(user_id),CONSTRAINT dm_push_user_fk FOREIGN KEY(user_id) REFERENCES dm_users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		$db->exec("CREATE TABLE IF NOT EXISTS dm_order_events (site_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(site_id,order_id),KEY created_idx(created_at),CONSTRAINT dm_order_site_fk FOREIGN KEY(site_id) REFERENCES dm_sites(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		$db->exec("CREATE TABLE IF NOT EXISTS dm_push_keys (id TINYINT UNSIGNED NOT NULL,public_key VARCHAR(100) NOT NULL,private_key_ciphertext MEDIUMTEXT NOT NULL,created_at DATETIME NOT NULL,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}

	private static function orderEvent(): void {
		$raw = file_get_contents('php://input', false, null, 0, 32769) ?: '';
		if (strlen($raw) > 32768) { self::json(['error' => 'Το αίτημα είναι μεγαλύτερο από το επιτρεπόμενο όριο.'], 413); }
		$timestamp = (string) ($_SERVER['HTTP_X_DANAE_TIMESTAMP'] ?? '');
		$signature = (string) ($_SERVER['HTTP_X_DANAE_SIGNATURE'] ?? '');
		if (!preg_match('/^\d{10}$/', $timestamp) || abs(time() - (int) $timestamp) > 300 || !preg_match('/^[a-f0-9]{64}$/', $signature)) { self::json(['error' => 'Μη έγκυρη υπογραφή ειδοποίησης.'], 401); }
		$body = json_decode($raw, true);
		if (!is_array($body) || empty($body['site_url']) || empty($body['order_id']) || empty($body['order_number'])) { self::json(['error' => 'Λείπουν στοιχεία παραγγελίας.'], 422); }
		$siteUrl = self::validatedSiteUrl((string) $body['site_url']);
		if (!$siteUrl) { self::json(['error' => 'Μη έγκυρο e-shop.'], 422); }
		$fp = hash('sha256', strtolower(rtrim($siteUrl, '/')));
		$q = self::db()->prepare("SELECT s.*,u.id AS owner_id FROM dm_sites s JOIN dm_users u ON u.id=s.user_id WHERE s.site_fingerprint=? AND s.status='active' LIMIT 1"); $q->execute([$fp]); $site = $q->fetch();
		if (!$site || empty($site['callback_secret_ciphertext'])) { self::json(['error' => 'Το e-shop δεν είναι συνδεδεμένο για ειδοποιήσεις.'], 404); }
		$secret = self::decrypt((string) $site['callback_secret_ciphertext']);
		$expected = hash_hmac('sha256', $timestamp . "\n" . $raw, $secret);
		if (!hash_equals($expected, $signature)) { self::json(['error' => 'Η υπογραφή της ειδοποίησης δεν είναι έγκυρη.'], 401); }
		$orderId = (int) $body['order_id'];
		if ($orderId < 1) { self::json(['error' => 'Μη έγκυρος αριθμός παραγγελίας.'], 422); }
		try {
			$ins = self::db()->prepare('INSERT INTO dm_order_events (site_id,order_id,created_at) VALUES (?,?,UTC_TIMESTAMP())');
			$ins->execute([(int) $site['id'], $orderId]);
		} catch (\PDOException $error) {
			if ((string) $error->getCode() === '23000') { self::json(['ok' => true, 'duplicate' => true]); }
			throw $error;
		}
		self::db()->exec('DELETE FROM dm_order_events WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)');
		$amount = mb_substr(trim((string) ($body['total'] ?? '')), 0, 40);
		$number = mb_substr(trim((string) $body['order_number']), 0, 80);
		$orderUrl = rtrim((string) (self::$config['app_origin'] ?? ''), '/') . '/?view=orders&site_id=' . (int) $site['id'] . '&order_id=' . $orderId;
		$payload = ['title' => 'Νέα παραγγελία · ' . mb_substr((string) $site['site_name'], 0, 80), 'body' => 'Παραγγελία #' . $number . ($amount !== '' ? ' · ' . $amount : ''), 'url' => $orderUrl, 'tag' => 'danae-order-' . $site['id'] . '-' . $orderId];
		$subs = self::db()->prepare('SELECT * FROM dm_push_subscriptions WHERE user_id=?'); $subs->execute([(int) $site['owner_id']]);
		$sent = 0;
		foreach ($subs->fetchAll() as $sub) { if (self::sendPush($sub, $payload)) { $sent++; } }
		self::audit((int) $site['owner_id'], (int) $site['id'], 'order_notification');
		self::json(['ok' => true, 'notifications' => $sent]);
	}

	private static function pushKey(): void { $keys = self::vapidKeys(); self::json(['public_key' => $keys['public']]); }
	private static function pushSubscribe(): void {
		$body = self::body(); $sub = $body['subscription'] ?? null;
		if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) { self::json(['error' => 'Η συσκευή δεν επέστρεψε έγκυρη συνδρομή ειδοποιήσεων.'], 422); }
		$endpoint = (string) $sub['endpoint']; $p = parse_url($endpoint);
		if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || strlen($endpoint) > 2048 || !self::publicHost((string) $p['host'])) { self::json(['error' => 'Μη έγκυρος πάροχος ειδοποιήσεων.'], 422); }
		$p256dh = (string) $sub['keys']['p256dh']; $auth = (string) $sub['keys']['auth'];
		if (strlen($p256dh) > 100 || strlen($auth) > 100 || self::b64urlDecode($p256dh) === '' || self::b64urlDecode($auth) === '') { self::json(['error' => 'Τα κλειδιά ειδοποιήσεων δεν είναι έγκυρα.'], 422); }
		$q = self::db()->prepare('INSERT INTO dm_push_subscriptions (user_id,endpoint_hash,endpoint_ciphertext,p256dh,auth_ciphertext,created_at,last_seen_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint_ciphertext=VALUES(endpoint_ciphertext),p256dh=VALUES(p256dh),auth_ciphertext=VALUES(auth_ciphertext),last_seen_at=UTC_TIMESTAMP()');
		$q->execute([(int) $_SESSION['user_id'], hash('sha256', $endpoint), self::encrypt($endpoint), $p256dh, self::encrypt($auth)]); self::json(['ok' => true]);
	}
	private static function pushUnsubscribe(): void {
		$body = self::body(); $endpoint = (string) ($body['endpoint'] ?? '');
		if ($endpoint !== '') { $q = self::db()->prepare('DELETE FROM dm_push_subscriptions WHERE user_id=? AND endpoint_hash=?'); $q->execute([(int) $_SESSION['user_id'], hash('sha256', $endpoint)]); }
		self::json(['ok' => true]);
	}

	private static function vapidKeys(): array {
		$q = self::db()->query('SELECT public_key,private_key_ciphertext FROM dm_push_keys WHERE id=1'); $row = $q->fetch();
		if ($row) { return ['public' => $row['public_key'], 'private' => self::decrypt($row['private_key_ciphertext'])]; }
		$pair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		if (!$pair || !openssl_pkey_export($pair, $privatePem)) { throw new \RuntimeException('VAPID key generation failed'); }
		$detail = openssl_pkey_get_details($pair); $publicRaw = "\x04" . $detail['ec']['x'] . $detail['ec']['y']; $public = self::b64url($publicRaw);
		$ins = self::db()->prepare('INSERT IGNORE INTO dm_push_keys (id,public_key,private_key_ciphertext,created_at) VALUES (1,?,?,UTC_TIMESTAMP())'); $ins->execute([$public, self::encrypt($privatePem)]);
		$q = self::db()->query('SELECT public_key,private_key_ciphertext FROM dm_push_keys WHERE id=1'); $saved = $q->fetch();
		return ['public' => $saved['public_key'], 'private' => self::decrypt($saved['private_key_ciphertext'])];
	}

	private static function sendPush(array $sub, array $payload): bool {
		$endpoint = self::decrypt((string) $sub['endpoint_ciphertext']); $p = parse_url($endpoint); if (!$p || empty($p['host']) || !self::publicHost((string) $p['host'])) { return false; }
		$uaPublic = self::b64urlDecode((string) $sub['p256dh']); $auth = self::b64urlDecode(self::decrypt((string) $sub['auth_ciphertext']));
		if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) < 16) { return false; }
		$spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $uaPublic;
		$pubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
		$uaKey = openssl_pkey_get_public($pubPem); $asKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		if (!$uaKey || !$asKey) { return false; }
		$shared = openssl_pkey_derive($uaKey, $asKey, 32); $asDetails = openssl_pkey_get_details($asKey); $asPublic = "\x04" . $asDetails['ec']['x'] . $asDetails['ec']['y'];
		if (!is_string($shared)) { return false; }
		$prkKey = hash_hmac('sha256', $shared, $auth, true); $ikm = self::hkdfExpand($prkKey, "WebPush: info\0" . $uaPublic . $asPublic, 32);
		$salt = random_bytes(16); $prk = hash_hmac('sha256', $ikm, $salt, true); $cek = self::hkdfExpand($prk, "Content-Encoding: aes128gcm\0", 16); $nonce = self::hkdfExpand($prk, "Content-Encoding: nonce\0", 12);
		$plain = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\x02"; $tag = '';
		$cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
		if (!is_string($cipher)) { return false; }
		$body = $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
		$keys = self::vapidKeys(); $aud = strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
		$appHost = (string) (parse_url((string) (self::$config['app_origin'] ?? ''), PHP_URL_HOST) ?: 'localhost');
		$claims = ['aud' => $aud, 'exp' => time() + 3600, 'sub' => (string) (self::$config['vapid_subject'] ?? 'mailto:admin@' . $appHost)];
		$jwt = self::vapidJwt($keys['private'], $claims); $headers = ['TTL: 86400', 'Urgency: normal', 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'Authorization: vapid t=' . $jwt . ', k=' . $keys['public']];
		$ch = curl_init($endpoint); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
		$response = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
		if ($status === 404 || $status === 410) { $del = self::db()->prepare('DELETE FROM dm_push_subscriptions WHERE id=?'); $del->execute([(int) $sub['id']]); return false; }
		return is_string($response) && $status >= 200 && $status < 300;
	}
	private static function vapidJwt(string $privatePem, array $claims): string {
		$header = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])); $body = self::b64url(json_encode($claims, JSON_UNESCAPED_SLASHES)); $input = $header . '.' . $body;
		$key = openssl_pkey_get_private($privatePem); if (!$key || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) { throw new \RuntimeException('VAPID signing failed'); }
		$offset = 2; if ((ord($der[1]) & 0x80) !== 0) { $offset += ord($der[1]) & 0x7f; }
		if (ord($der[$offset]) !== 2) { throw new \RuntimeException('Invalid VAPID signature'); } $len = ord($der[$offset + 1]); $r = substr($der, $offset + 2, $len); $offset += 2 + $len;
		if (ord($der[$offset]) !== 2) { throw new \RuntimeException('Invalid VAPID signature'); } $len = ord($der[$offset + 1]); $s = substr($der, $offset + 2, $len);
		$r = str_pad(substr($r, -32), 32, "\0", STR_PAD_LEFT); $s = str_pad(substr($s, -32), 32, "\0", STR_PAD_LEFT);
		return $input . '.' . self::b64url($r . $s);
	}
	private static function hkdfExpand(string $prk, string $info, int $length): string { $out = ''; $last = ''; for ($i = 1; strlen($out) < $length; $i++) { $last = hash_hmac('sha256', $last . $info . chr($i), $prk, true); $out .= $last; } return substr($out, 0, $length); }
	private static function b64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
	private static function b64urlDecode(string $data): string { $raw = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true); return is_string($raw) ? $raw : ''; }

	private static function socialProxy(int $siteId, string $endpoint, string $method): void {
		$q = self::db()->prepare("SELECT * FROM dm_sites WHERE id=? AND user_id=? AND status='active' LIMIT 1"); $q->execute([$siteId, (int) $_SESSION['user_id']]); $site = $q->fetch();
		if (!$site) { self::json(['error' => 'Το συγκεκριμένο e-shop δεν είναι συνδεδεμένο στον λογαριασμό σου.'], 404); }
		$allowed = ['site' => 'GET', 'places' => 'GET', 'categories' => 'GET', 'items' => 'GET', 'analytics' => 'GET', 'links' => 'POST'];
		if (!isset($allowed[$endpoint]) || $allowed[$endpoint] !== $method) { self::json(['error' => 'Η ενέργεια δεν επιτρέπεται.'], 405); }
		$url = $site['api_url'] . '/social/' . $endpoint;
		if ($method === 'GET') {
			$params = [];
			foreach (['type', 'search', 'category_id', 'page', 'per_page'] as $key) { if (isset($_GET[$key]) && is_scalar($_GET[$key])) { $params[$key] = substr(trim((string) $_GET[$key]), 0, 120); } }
			if ('analytics' === $endpoint && isset($_GET['days']) && is_scalar($_GET['days'])) { $params['days'] = (string) min(90, max(7, abs((int) $_GET['days']))); }
			if ($params) { $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986); }
		}
		$token = self::decrypt((string) $site['token_ciphertext']);
		$data = self::wpRequest($url, $method, $token, $method === 'POST' ? self::body() : null);
		if (is_array($data) && (int) ($data['__error_status'] ?? 0) === 401) {
			$revoked = self::db()->prepare("UPDATE dm_sites SET status='revoked' WHERE id=? AND user_id=?"); $revoked->execute([$siteId, (int) $_SESSION['user_id']]);
			self::json(['error' => 'Η πρόσβαση ανακλήθηκε από το WordPress. Σύνδεσε ξανά το e-shop από τις ρυθμίσεις του plugin.'], 401);
		}
		if (!is_array($data) || isset($data['__error_status'])) { self::json(['error' => self::remoteErrorText($data, 'Δεν ήταν δυνατή η επικοινωνία με το WordPress. Έλεγξε τη σύνδεση ή την κατάσταση του site.')], 502); }
		$update = self::db()->prepare('UPDATE dm_sites SET last_seen_at=UTC_TIMESTAMP() WHERE id=?'); $update->execute([$siteId]);
		self::json($data);
	}

	private static function salesProxy(int $siteId, string $method): void {
		$q = self::db()->prepare("SELECT * FROM dm_sites WHERE id=? AND user_id=? AND status='active' LIMIT 1"); $q->execute([$siteId, (int) $_SESSION['user_id']]); $site = $q->fetch();
		if (!$site) { self::json(['error' => 'Το συγκεκριμένο e-shop δεν είναι συνδεδεμένο στον λογαριασμό σου.'], 404); }
		if ($method !== 'GET') { self::json(['error' => 'Η ενέργεια δεν επιτρέπεται.'], 405); }
		$url = rtrim((string) $site['api_url'], '/') . '/mobile/sales';
		if (isset($_GET['year']) && is_scalar($_GET['year'])) { $url .= '?' . http_build_query(['year' => substr(trim((string) $_GET['year']), 0, 4)], '', '&', PHP_QUERY_RFC3986); }
		$data = self::wpRequest($url, 'GET', self::decrypt((string) $site['token_ciphertext']));
		if (is_array($data) && (int) ($data['__error_status'] ?? 0) === 401) { self::json(['error' => 'Η πρόσβαση ανακλήθηκε από το WordPress. Σύνδεσε ξανά το e-shop.'], 401); }
		if (!is_array($data) || isset($data['__error_status'])) { self::json(['error' => self::remoteErrorText($data, 'Δεν ήταν δυνατή η ανάγνωση του ιστορικού πωλήσεων. Έλεγξε τη σύνδεση και την έκδοση του plugin.', true)], 502); }
		self::json($data);
	}

	private static function ordersProxy(int $siteId, int $orderId, string $method): void {
		$q = self::db()->prepare("SELECT * FROM dm_sites WHERE id=? AND user_id=? AND status='active' LIMIT 1"); $q->execute([$siteId, (int) $_SESSION['user_id']]); $site = $q->fetch();
		if (!$site) { self::json(['error' => 'Το συγκεκριμένο e-shop δεν είναι συνδεδεμένο στον λογαριασμό σου.'], 404); }
		if ($orderId === 0 && $method !== 'GET') { self::json(['error' => 'Η ενέργεια δεν επιτρέπεται.'], 405); }
		if ($orderId > 0 && !in_array($method, ['GET', 'POST'], true)) { self::json(['error' => 'Η ενέργεια δεν επιτρέπεται.'], 405); }
		$endpoint = rtrim((string) $site['api_url'], '/') . '/mobile/orders' . ($orderId ? '/' . $orderId : '');
		if (!$orderId) {
			$params = [];
			foreach (['page', 'per_page', 'status', 'search'] as $key) { if (isset($_GET[$key]) && is_scalar($_GET[$key])) { $params[$key] = mb_substr(trim((string) $_GET[$key]), 0, 120); } }
			if ($params) { $endpoint .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986); }
		}
		$token = self::decrypt((string) $site['token_ciphertext']);
		$data = self::wpRequest($endpoint, $method, $token, $method === 'POST' ? self::body() : null);
		if (is_array($data) && (int) ($data['__error_status'] ?? 0) === 401) {
			$revoked = self::db()->prepare("UPDATE dm_sites SET status='revoked' WHERE id=? AND user_id=?"); $revoked->execute([$siteId, (int) $_SESSION['user_id']]);
			self::json(['error' => 'Η πρόσβαση ανακλήθηκε από το WordPress. Σύνδεσε ξανά το e-shop.'], 401);
		}
		if (!is_array($data) || isset($data['__error_status'])) { self::json(['error' => 'Δεν ήταν δυνατή η επικοινωνία με το WordPress. Έλεγξε τη σύνδεση ή αν έχει εγκατασταθεί η νέα έκδοση του plugin.'], 502); }
		$update = self::db()->prepare('UPDATE dm_sites SET last_seen_at=UTC_TIMESTAMP() WHERE id=?'); $update->execute([$siteId]);
		self::json($data);
	}

	private static function wpRequest(string $url, string $method, string $token, ?array $payload = null): ?array {
		if (!self::validatedApiTarget($url) || !function_exists('curl_init')) { return null; }
		$headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Cache-Control: no-cache, no-store, max-age=0', 'Pragma: no-cache'];
		$ch = curl_init($url);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
		if ($payload !== null) { $headers[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
		$raw = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
		if (!is_string($raw)) { return null; }
		$out = json_decode($raw, true);
		if ($status < 200 || $status >= 300) { return ['__error_status' => $status, '__error_message' => is_array($out) ? (string) ($out['message'] ?? '') : '']; }
		return is_array($out) ? $out : null;
	}

	private static function remoteErrorText(?array $data, string $fallback, bool $newEndpoint = false): string {
		$status = (int) ($data['__error_status'] ?? 0);
		if ($newEndpoint && 404 === $status) { return 'Το endpoint δεν υπάρχει στο WordPress. Έλεγξε ότι έχει εγκατασταθεί το Smart SEO D.A.N.A.E. 6.2.9.'; }
		$message = trim(strip_tags((string) ($data['__error_message'] ?? '')));
		$message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message);
		$message = trim(preg_replace('/\s+/u', ' ', (string) $message));
		return '' !== $message ? mb_substr($message, 0, 320) : $fallback;
	}

	private static function validatedApiTarget(string $url): bool {
		$p = parse_url($url);
		if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) { return false; }
		$base = rtrim((string) ($p['path'] ?? ''), '/');
		if (!preg_match('#/wp-json/ssa-danae/v1/(?:social/(?:site|places|categories|items|links|analytics)|mobile/(?:orders(?:/\d+)?|sales))$#', $base)) { return false; }
		return self::publicHost((string) $p['host']);
	}
	private static function validatedApiUrl(string $url): ?string {
		$p = parse_url($url);
		if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) { return null; }
		$path = rtrim((string) ($p['path'] ?? ''), '/');
		if (!preg_match('#/wp-json/ssa-danae/v1$#', $path) || !self::publicHost((string) $p['host'])) { return null; }
		$port = isset($p['port']) ? ':' . (int) $p['port'] : '';
		return 'https://' . strtolower((string) $p['host']) . $port . $path;
	}
	private static function validatedSiteUrl(string $url): ?string {
		$p = parse_url($url);
		if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) { return null; }
		if (!self::publicHost((string) $p['host'])) { return null; }
		return 'https://' . strtolower((string) $p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '') . rtrim((string) ($p['path'] ?? ''), '/') . '/';
	}
	private static function publicHost(string $host): bool {
		$host = trim(strtolower($host), '[]');
		if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) { return false; }
		$ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
		if (!$ips) {
			foreach ((@dns_get_record($host, DNS_A | DNS_AAAA) ?: []) as $record) { if (!empty($record['ip'])) { $ips[] = $record['ip']; } if (!empty($record['ipv6'])) { $ips[] = $record['ipv6']; } }
			if (!$ips) { $v4 = @gethostbynamel($host) ?: []; $ips = array_merge($ips, $v4); }
		}
		if (!$ips) { return false; }
		foreach ($ips as $ip) { if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; } }
		return true;
	}

	private static function encrypt(string $plain): string {
		$key = self::encryptionKey(); $nonce = random_bytes(12); $tag = '';
		$cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'DanaeMobile:v1');
		if ($cipher === false) { throw new \RuntimeException('Token encryption failed'); }
		return base64_encode($nonce . $tag . $cipher);
	}
	private static function decrypt(string $packed): string {
		$raw = base64_decode($packed, true); if ($raw === false || strlen($raw) < 29) { throw new \RuntimeException('Stored site credential is invalid'); }
		$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'DanaeMobile:v1');
		if ($plain === false) { throw new \RuntimeException('Stored site credential cannot be decrypted'); } return $plain;
	}
	private static function encryptionKey(): string {
		$key = base64_decode((string) (self::$config['encryption_key'] ?? ''), true);
		if ($key === false || strlen($key) !== 32) { throw new \RuntimeException('Encryption key must be 32 random bytes in base64'); }
		return $key;
	}
	private static function audit(int $userId, int $siteId, string $action): void { $q = self::db()->prepare('INSERT INTO dm_audit_log (user_id,site_id,action,created_at) VALUES (?,?,?,UTC_TIMESTAMP())'); $q->execute([$userId, $siteId, $action]); }
	private static function rateLimit(string $subject, int $limit, int $windowSeconds): bool {
		$seconds = max(60, min(86400, $windowSeconds)); $key = hash('sha256', $subject);
		$sql = 'INSERT INTO dm_rate_limits (rate_key,hits,window_started_at) VALUES (?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE hits=IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $seconds . ' SECOND),1,hits+1),window_started_at=IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $seconds . ' SECOND),UTC_TIMESTAMP(),window_started_at)';
		$q = self::db()->prepare($sql); $q->execute([$key]); $read = self::db()->prepare('SELECT hits FROM dm_rate_limits WHERE rate_key=?'); $read->execute([$key]); return (int) $read->fetchColumn() <= $limit;
	}
	private static function validUsername(mixed $value): ?string { $value = strtolower(trim((string) $value)); return filter_var($value, FILTER_VALIDATE_EMAIL) ? substr($value, 0, 190) : null; }
	private static function body(): array {
		$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
		if ($length > 131072) { self::json(['error' => 'Το αίτημα είναι μεγαλύτερο από το επιτρεπόμενο όριο.'], 413); }
		$raw = file_get_contents('php://input', false, null, 0, 131073) ?: '';
		if (strlen($raw) > 131072) { self::json(['error' => 'Το αίτημα είναι μεγαλύτερο από το επιτρεπόμενο όριο.'], 413); }
		$data = json_decode($raw, true); return is_array($data) ? $data : [];
	}
	private static function json(array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

	private static function page(): void {
		$csrf = htmlspecialchars((string) ($_SESSION['csrf'] ?? ''), ENT_QUOTES, 'UTF-8');
		$username = htmlspecialchars((string) ($_SESSION['username'] ?? ''), ENT_QUOTES, 'UTF-8');
		$loggedIn = !empty($_SESSION['user_id']);
		$setupAvailable = (int) self::db()->query('SELECT COUNT(*) FROM dm_users')->fetchColumn() === 0;
		$logo = '/assets/danae-emblem-white.webp';
		$hero = '/assets/danae-hero-emblem.webp';
		header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, private');
?>
<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#061020"><meta name="csrf-token" content="<?=$csrf?>"><title>Danae Mobile</title><link rel="manifest" href="/manifest.webmanifest?v=1.2.10"><link rel="stylesheet" href="/assets/app.css?v=1.2.10"><link rel="apple-touch-icon" href="/assets/danae-icon.png?v=1.2.10"></head>
<body data-setup-available="<?=$setupAvailable ? '1' : '0'?>"><div class="stars" aria-hidden="true"></div><main class="shell">
<header class="topbar"><div class="brand-mini"><img src="<?=$logo?>" alt="D.A.N.A.E."><span>Danae <small>Mobile</small></span></div><div class="top-actions"><span id="connection-state" class="state-dot">Τοπικά ασφαλές</span><button id="logout" class="quiet hidden" type="button">Έξοδος</button></div></header>
<section id="welcome" class="hero"><div class="hero-copy"><h1>SMART SEO</h1><p class="hero-brand-accent">D.A.N.A.E.</p><p class="hero-tagline">Digital Assistant<br>Navigation Analytics &amp; E-Commerce</p></div><div class="hero-art"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><img src="<?=$hero?>" alt="Danae — Digital Assistant Navigation Analytics & E-Commerce"></div></section>
<section id="auth" class="panel auth-panel"><div class="panel-heading"><span class="kicker">ΚΑΛΩΣ ΗΡΘΕΣ</span><h2 id="auth-title">Σύνδεση στη Danae</h2><p id="auth-help">Χρησιμοποίησε τον λογαριασμό Danae Mobile.</p></div><form id="login-form" class="form-grid"><label>Email<input name="username" type="email" autocomplete="username" required></label><label>Κωδικός<input name="password" type="password" autocomplete="current-password" required minlength="12"></label><button class="primary" type="submit">Σύνδεση</button></form><form id="setup-form" class="form-grid hidden"><label>Κωδικός αρχικής ρύθμισης<input name="setup_key" type="password" autocomplete="off" required></label><label>Email διαχειριστή<input name="username" type="email" autocomplete="username" required></label><label>Νέος κωδικός (τουλάχιστον 12 χαρακτήρες)<input name="password" type="password" autocomplete="new-password" minlength="12" required></label><button class="primary" type="submit">Δημιουργία λογαριασμού</button></form><button id="show-setup" class="link-button hidden" type="button">Αρχική ρύθμιση</button><p id="auth-error" class="error" role="alert"></p></section>
<section id="workspace" class="workspace hidden">
<nav class="mobile-tabs" id="workspace-tabs" aria-label="Ενότητες Danae Mobile">
<button id="tab-social" class="active" type="button">Social Links</button>
<button id="tab-stats" type="button">Social Stats</button>
<button id="tab-orders" type="button">Παραγγελίες</button>
<button id="tab-sales" type="button">Πωλήσεις</button>
</nav>
<div id="social-panel"><div class="section-title"><div><span class="kicker">Ο ΔΙΚΟΣ ΣΟΥ ΧΩΡΟΣ</span><h2>Social Media Links</h2><p>Σύνδεσε μόνο e-shop με ενεργό Smart SEO D.A.N.A.E.</p></div><button id="pair-site" class="secondary" type="button">＋ Σύνδεση e-shop</button></div><div id="pair-card" class="pair-card hidden"><div><span class="kicker">ΚΩΔΙΚΟΣ ΜΙΑΣ ΧΡΗΣΗΣ</span><strong id="pair-code">--------</strong><p>Άνοιξε στο WordPress: <b>Smart SEO D.A.N.A.E. → Σύνδεση Danae</b> και βάλε αυτόν τον κωδικό. Ισχύει για 10 λεπτά.</p></div><button id="pair-refresh" class="quiet" type="button">Νέος κωδικός</button></div><div id="shop-empty" class="empty-card hidden"><span class="empty-icon">✦</span><h3>Σύνδεσε το πρώτο σου e-shop</h3><p>Θα εμφανίζονται μόνο τα sites που έχουν συνδεθεί με το Danae Mobile.</p><button class="primary" type="button" id="pair-empty">Σύνδεση e-shop</button></div><div id="shop-content" class="hidden"><div class="shop-bar"><div><span class="kicker">ΕΝΕΡΓΟ E-SHOP</span><h3 id="shop-name"></h3><a id="shop-domain" target="_blank" rel="noopener"></a></div><select id="shop-select" class="hidden" aria-label="Επιλογή e-shop"></select></div><div class="tool-card"><div class="tool-header"><div><span class="step">01</span><h3>Τι θέλεις να μοιραστείς;</h3></div><div id="selected-count" class="count">0 επιλεγμένα</div></div><div class="filters"><div class="segmented" id="type-tabs"><button type="button" data-type="product" class="active">Προϊόντα</button><button type="button" data-type="page">Σελίδες</button><button type="button" data-type="post">Άρθρα</button></div><label class="search-wrap"><span>⌕</span><input id="item-search" type="search" placeholder="Αναζήτηση ονόματος ή SKU"></label><select id="category-filter" class="hidden" aria-label="Φίλτρο κατηγορίας"><option value="0">Όλες οι κατηγορίες</option></select></div><div id="items" class="items"><div class="loading">Φόρτωση περιεχομένου…</div></div><button id="load-more" class="load-more hidden" type="button">Φόρτωσε περισσότερα</button></div><div class="tool-card campaign-card"><div class="tool-header"><div><span class="step">02</span><h3>Πού θα δημοσιευτεί;</h3></div></div><div id="places" class="places"><div class="loading">Φόρτωση καναλιών…</div></div><div class="campaign-fields"><label>Όνομα καμπάνιας (προαιρετικό)<input id="campaign-suffix" maxlength="50" placeholder="π.χ. winter-sale"></label><label>Ετικέτα περιεχομένου (προαιρετική)<input id="content-label" maxlength="100" placeholder="π.χ. story Νοεμβρίου"></label></div><div class="generate-row"><p>Θα δημιουργηθούν UTM σύνδεσμοι σύμφωνα με τη σήμανση του plugin.</p><button id="generate" class="primary" type="button" disabled>Δημιουργία συνδέσμων <span>→</span></button></div><div id="results" class="results hidden"></div></div></div></div>
<div id="orders-panel" class="hidden">
<div class="section-title"><div><span class="kicker">ΔΙΑΧΕΙΡΙΣΗ E-SHOP</span><h2>Παραγγελίες</h2><p>Δες και ενημέρωσε τις παραγγελίες των συνδεδεμένων e-shop.</p></div><button id="orders-refresh" class="secondary" type="button">Ανανέωση</button></div>
<div class="tool-card push-card"><div><span class="kicker">ΕΙΔΟΠΟΙΗΣΕΙΣ</span><h3>Πωλήσεις στο κινητό</h3><p id="push-status">Ενεργοποίησε ειδοποιήσεις για νέες παραγγελίες από τα συνδεδεμένα e-shop.</p></div><button id="push-toggle" class="secondary" type="button">Ενεργοποίηση ειδοποιήσεων</button></div>
<div class="shop-bar"><div><span class="kicker">ΕΝΕΡΓΟ E-SHOP</span><h3 id="orders-shop-name"></h3><a id="orders-shop-domain" target="_blank" rel="noopener"></a></div><select id="orders-shop-select" class="hidden" aria-label="Επιλογή e-shop για παραγγελίες"></select></div>
<div id="orders-browser" class="tool-card"><div class="filters order-filters"><label class="search-wrap"><span>⌕</span><input id="order-search" type="search" placeholder="Αναζήτηση αριθμού ή πελάτη"></label><select id="order-status-filter" aria-label="Φίλτρο κατάστασης"><option value="all">Όλες οι καταστάσεις</option></select></div><div id="orders-list" class="order-list"><div class="loading">Φόρτωση παραγγελιών…</div></div><button id="orders-more" class="load-more hidden" type="button">Περισσότερες παραγγελίες</button></div>
<div id="order-detail" class="tool-card hidden"><div class="order-detail-heading"><button id="order-back" class="secondary" type="button">← Πίσω στις παραγγελίες</button><h3 id="order-detail-title">Παραγγελία</h3></div><div id="order-detail-content"><div class="loading">Φόρτωση παραγγελίας…</div></div></div>
</div>
<div id="sales-panel" class="hidden">
<div class="section-title"><div><span class="kicker">ΙΣΤΟΡΙΚΟ E-SHOP</span><h2>Πωλήσεις</h2><p>Τζίρος ανά μήνα με επιλογή προηγούμενων ετών.</p></div><button id="sales-refresh" class="secondary" type="button">Ανανέωση</button></div>
<div class="shop-bar"><div><span class="kicker">ΕΝΕΡΓΟ E-SHOP</span><h3 id="sales-shop-name"></h3><a id="sales-shop-domain" target="_blank" rel="noopener"></a></div><select id="sales-shop-select" class="hidden" aria-label="Επιλογή e-shop για πωλήσεις"></select></div>
<div class="tool-card"><div class="filters"><label>Έτος<select id="sales-year"></select></label></div><div id="sales-current" class="sales-current"></div><div id="sales-months" class="sales-month-list"><div class="loading">Φόρτωση πωλήσεων…</div></div><p class="analytics-note">Ο επιβεβαιωμένος τζίρος περιλαμβάνει παραγγελίες σε επεξεργασία και ολοκληρωμένες. Η συνολική αξία παραγγελιών εξαιρεί ακυρωμένες, αποτυχημένες, επιστραφείσες και πρόχειρες παραγγελίες.</p></div>
</div>
<div id="stats-panel" class="hidden">
<div class="section-title"><div><span class="kicker">ΑΠΟΔΟΣΗ SOCIAL LINKS</span><h2>Social Stats</h2><p>Δες ποιες καμπάνιες και ποιοι σύνδεσμοι έφεραν επισκέψεις και αγορές.</p></div><button id="stats-refresh" class="secondary" type="button">Ανανέωση</button></div>
<div class="shop-bar"><div><span class="kicker">ΕΝΕΡΓΟ E-SHOP</span><h3 id="stats-shop-name"></h3><a id="stats-shop-domain" target="_blank" rel="noopener"></a></div><select id="stats-shop-select" class="hidden" aria-label="Επιλογή e-shop για Social Stats"></select></div>
<div class="tool-card"><div class="filters"><label>Περίοδος<select id="stats-days"><option value="28" selected>28 ημέρες</option></select></label></div><div id="stats-summary" class="sales-current"></div><h3 class="stats-subtitle">Απόδοση ανά σύνδεσμο / καμπάνια</h3><div id="stats-campaigns" class="sales-month-list"><div class="loading">Φόρτωση Social Stats…</div></div><h3 class="stats-subtitle">Σε ποιες σελίδες έφτασαν</h3><div id="stats-landings" class="sales-month-list"></div><p class="analytics-note">Τα στοιχεία προέρχονται από GA4 μέσω Site Kit και δείχνουν επισκέψεις μετά το πάτημα του UTM συνδέσμου. Οι επισκέπτες στη σύνοψη είναι άθροισμα ανά καμπάνια και δεν αντιστοιχούν απαραίτητα σε διαφορετικά άτομα. Δεν είναι μετρήσεις προβολών ή likes από Instagram, Facebook ή άλλες πλατφόρμες. Για αξιόπιστη διάκριση κάθε ανάρτησης, χρησιμοποίησε διαφορετική ετικέτα περιεχομένου κατά τη δημιουργία του συνδέσμου.</p></div>
</div>
</section>
</main><div id="toast" class="toast" role="status"></div><script src="/assets/app.js?v=1.2.10" defer></script></body></html>
<?php
	}
}
