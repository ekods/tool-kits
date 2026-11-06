<?php
// php scripts/object-cache-regression.php — the Redis object-cache drop-in matches core WP_Object_Cache semantics,
// isolates sites by key prefix, never lets a Redis failure escape, and the module never overwrites a foreign drop-in.
if (getenv('TK_OC_CHILD')) {
    // Child process: load the drop-in under one failure scenario and report whether it defined the cache API.
    define('ABSPATH', __DIR__ . '/');
    define('WP_CONTENT_DIR', getenv('TK_OC_CONTENT'));
    $scenario = getenv('TK_OC_CHILD');
    define('TK_REDIS_CLASS', $scenario === 'no-ext' ? 'TkMissingRedis' : 'TkChildRedis');
    if ($scenario !== 'no-ext') {
        class TkChildRedis {
            public function connect(...$args) { if (getenv('TK_OC_CHILD') === 'throw') { throw new RedisException('Connection refused'); } return getenv('TK_OC_CHILD') !== 'refused'; }
            public function auth($password) { return false; }
            public function select($db) { return true; }
            public function close() { return true; }
        }
        if (!class_exists('RedisException')) { class RedisException extends Exception {} }
    }
    ob_start();
    require dirname(__DIR__) . '/drop-ins/object-cache.php';
    $output = ob_get_clean();
    echo json_encode(array('defined' => function_exists('wp_cache_init') || class_exists('WP_Object_Cache', false), 'output' => $output));
    exit;
}

define('ABSPATH', __DIR__ . '/');
$content = sys_get_temp_dir() . '/tk-object-cache-' . bin2hex(random_bytes(4));
define('WP_CONTENT_DIR', $content);
mkdir($content . '/cache/tool-kits', 0777, true);

if (!class_exists('RedisException')) { class RedisException extends Exception {} }
/** In-memory stand-in for the phpredis methods the drop-in and module use. */
define('TK_REDIS_CLASS', 'TkFakeRedis');
class TkFakeRedis {
    public static $data = array();
    public static $expires = array();
    public static $now = 1000;
    public static $fail_connect = false;
    public static $throw = false;
    public static $password = '';
    public static $calls = array();
    private $db = 0;
    private function guard($name) {
        self::$calls[] = $name;
        if (self::$throw) { throw new RedisException('Connection lost'); }
    }
    private function &store() {
        if (!isset(self::$data[$this->db])) { self::$data[$this->db] = array(); }
        foreach (self::$expires[$this->db] ?? array() as $key => $at) {
            if ($at <= self::$now) { unset(self::$data[$this->db][$key], self::$expires[$this->db][$key]); }
        }
        return self::$data[$this->db];
    }
    public function connect($host, $port = 6379, $timeout = 0, $reserved = null, $retry = 0, $read_timeout = 0) {
        if (self::$fail_connect) { throw new RedisException('Connection refused'); }
        return true;
    }
    public function auth($password) { $this->guard('auth'); return self::$password === '' || $password === self::$password; }
    public function select($db) { $this->guard('select'); $this->db = (int) $db; return true; }
    public function get($key) { $this->guard('get'); $store = &$this->store(); return $store[$key] ?? false; }
    public function mget(array $keys) { $this->guard('mget'); $store = &$this->store(); return array_map(function ($key) use ($store) { return $store[$key] ?? false; }, $keys); }
    public function set($key, $value, $options = null) {
        $this->guard('set');
        $store = &$this->store();
        $options = is_array($options) ? $options : array();
        if (in_array('nx', $options, true) && array_key_exists($key, $store)) { return false; }
        $store[$key] = (string) $value;
        unset(self::$expires[$this->db][$key]);
        if (!empty($options['ex'])) { self::$expires[$this->db][$key] = self::$now + (int) $options['ex']; }
        return true;
    }
    public function del($keys) {
        $this->guard('del');
        $store = &$this->store();
        $count = 0;
        foreach ((array) $keys as $key) { if (array_key_exists($key, $store)) { unset($store[$key], self::$expires[$this->db][$key]); $count++; } }
        return $count;
    }
    public function ttl($key) { $this->guard('ttl'); $store = &$this->store(); if (!array_key_exists($key, $store)) { return -2; } return isset(self::$expires[$this->db][$key]) ? self::$expires[$this->db][$key] - self::$now : -1; }
    public function scan(&$iterator, $pattern = null, $count = 0) {
        $this->guard('scan');
        self::$calls[] = 'scan:' . $pattern;
        static $snapshot = array();
        if (empty($iterator)) { $snapshot = array_values(array_filter(array_keys($this->store()), function ($key) use ($pattern) { return fnmatch($pattern, $key); })); }
        $keys = $snapshot; // Like SCAN, deleting returned keys does not skip the remaining ones.
        $offset = (int) $iterator;
        $batch = array_slice($keys, $offset, 2); // Tiny batches exercise the cursor loop.
        $iterator = $offset + 2 < count($keys) ? $offset + 2 : 0;
        return $batch;
    }
    public function close() { return true; }
}

$GLOBALS['ms'] = false;
$GLOBALS['blog'] = 1;
$GLOBALS['suspend'] = false;
function is_multisite() { return $GLOBALS['ms']; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function wp_suspend_cache_addition() { return $GLOBALS['suspend']; }

$checks = 0;
function check_oc($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['checks']++; }
function oc_new(array $config = array()) { $redis = new TkFakeRedis(); $redis->select(2); return new WP_Object_Cache($redis, $config + array('prefix' => 'siteA:', 'host' => '127.0.0.1', 'port' => 6379)); }

try {
    // Boot fallbacks run in child processes: the drop-in must define nothing (WordPress then loads its own cache).
    $config = "<?php\nif (!defined('ABSPATH')) { return array(); }\nreturn array('enabled' => true, 'host' => '127.0.0.1', 'port' => 6379, 'password' => 'secret', 'database' => 0, 'prefix' => 'siteA:');\n";
    foreach (array('throw', 'refused', 'auth', 'no-ext', 'no-config') as $scenario) {
        $child_content = $content . '/child-' . $scenario;
        mkdir($child_content . '/cache/tool-kits', 0777, true);
        if ($scenario !== 'no-config') { file_put_contents($child_content . '/cache/tool-kits/object-cache-config.php', $config); }
        $command = 'TK_OC_CHILD=' . escapeshellarg($scenario) . ' TK_OC_CONTENT=' . escapeshellarg($child_content) . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg(__FILE__) . ' 2>&1';
        $result = json_decode((string) shell_exec($command), true);
        check_oc(is_array($result) && $result['defined'] === false && $result['output'] === '', 'Drop-in did not fall back silently when Redis ' . $scenario . ': ' . json_encode($result));
        if (in_array($scenario, array('throw', 'refused', 'auth'), true)) {
            check_oc(file_exists($child_content . '/cache/tool-kits/object-cache-down'), 'A failed ' . $scenario . ' connection did not start the 30 second pause');
        }
    }

    // After a failure the drop-in skips Redis for 30 seconds (an unreachable host would cost every request the connect timeout).
    $healthy = "<?php\nif (!defined('ABSPATH')) { return array(); }\nreturn array('enabled' => true, 'host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'database' => 0, 'prefix' => 'siteA:');\n";
    foreach (array('marker-none' => null, 'marker-fresh' => time(), 'marker-stale' => time() - 31) as $scenario => $marker_time) {
        $child_content = $content . '/child-' . $scenario;
        mkdir($child_content . '/cache/tool-kits', 0777, true);
        file_put_contents($child_content . '/cache/tool-kits/object-cache-config.php', $healthy);
        $marker = $child_content . '/cache/tool-kits/object-cache-down';
        if ($marker_time !== null) { touch($marker, $marker_time); }
        $command = 'TK_OC_CHILD=' . escapeshellarg($scenario) . ' TK_OC_CONTENT=' . escapeshellarg($child_content) . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg(__FILE__) . ' 2>&1';
        $result = json_decode((string) shell_exec($command), true);
        $expect_active = $scenario !== 'marker-fresh';
        check_oc(is_array($result) && $result['defined'] === $expect_active && $result['output'] === '', 'Pause handling wrong for ' . $scenario . ': ' . json_encode($result));
        check_oc(file_exists($marker) === ($scenario === 'marker-fresh'), 'Marker state wrong after ' . $scenario . ' (a successful connection must clear it, a fresh one must stay)');
    }

    // Successful boot defines the full API.
    file_put_contents($content . '/cache/tool-kits/object-cache-config.php', "<?php\nif (!defined('ABSPATH')) { return array(); }\nreturn array('enabled' => true, 'host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'database' => 2, 'prefix' => 'siteA:');\n");
    require dirname(__DIR__) . '/drop-ins/object-cache.php';
    foreach (array('wp_cache_init', 'wp_cache_add', 'wp_cache_add_multiple', 'wp_cache_replace', 'wp_cache_set', 'wp_cache_set_multiple', 'wp_cache_get', 'wp_cache_get_multiple', 'wp_cache_delete', 'wp_cache_delete_multiple', 'wp_cache_incr', 'wp_cache_decr', 'wp_cache_flush', 'wp_cache_flush_runtime', 'wp_cache_flush_group', 'wp_cache_supports', 'wp_cache_close', 'wp_cache_add_global_groups', 'wp_cache_add_non_persistent_groups', 'wp_cache_switch_to_blog', 'wp_cache_reset') as $function) {
        check_oc(function_exists($function), $function . ' is missing');
    }
    wp_cache_init();
    check_oc($wp_object_cache instanceof WP_Object_Cache && $wp_object_cache->tk_status()['connected'], 'wp_cache_init did not connect');
    check_oc(isset(TkFakeRedis::$data[2]) || wp_cache_set('db', 1) && isset(TkFakeRedis::$data[2]), 'Configured database was not selected');

    // After an outage, deletes never reached Redis: recovery clears this site's keys only, a normal boot clears nothing.
    $recovery = new TkFakeRedis(); $recovery->select(5);
    $recovery->set('siteA:g:stale', serialize('old')); $recovery->set('siteB:g:other-site', serialize('keep'));
    $recovery_config = array('prefix' => 'siteA:', 'host' => '127.0.0.1', 'port' => 6379);
    $GLOBALS['tk_object_cache_boot'] = array('redis' => $recovery, 'config' => $recovery_config, 'recovered' => false);
    wp_cache_init();
    check_oc(isset(TkFakeRedis::$data[5]['siteA:g:stale']), 'A normal boot cleared cached data');
    $GLOBALS['tk_object_cache_boot'] = array('redis' => $recovery, 'config' => $recovery_config, 'recovered' => true);
    wp_cache_init();
    check_oc(!isset(TkFakeRedis::$data[5]['siteA:g:stale']), 'Recovery after an outage left stale data behind');
    check_oc(isset(TkFakeRedis::$data[5]['siteB:g:other-site']), 'Recovery cleared another site\'s data');
    wp_cache_init();
    $GLOBALS['wp_object_cache'] = oc_new();

    // Stored falsy values are distinguishable from misses, across requests.
    foreach (array('false' => false, 'zero' => 0, 'empty' => '', 'null' => null, 'array' => array()) as $key => $value) {
        wp_cache_set($key, $value, 'g');
    }
    $GLOBALS['wp_object_cache'] = oc_new();
    foreach (array('false' => false, 'zero' => 0, 'empty' => '', 'null' => null, 'array' => array()) as $key => $value) {
        $found = null;
        check_oc(wp_cache_get($key, 'g', false, $found) === $value && $found === true, 'Stored ' . $key . ' did not round-trip as found');
    }
    $found = null;
    check_oc(wp_cache_get('missing', 'g', false, $found) === false && $found === false, 'A missing key was reported as found');
    check_oc($wp_object_cache->cache_hits === 5 && $wp_object_cache->cache_misses === 1, 'Hit/miss counters are wrong');
    check_oc(wp_cache_get('', 'g') === false && wp_cache_set('', 1) === false && wp_cache_set(' ', 1) === false, 'Empty keys were accepted');

    // Objects are cloned on set and get.
    $object = new stdClass();
    $object->v = 1;
    wp_cache_set('obj', $object);
    $object->v = 2;
    $copy = wp_cache_get('obj');
    check_oc($copy->v === 1, 'set() did not clone the object');
    $copy->v = 3;
    check_oc(wp_cache_get('obj')->v === 1, 'get() returned the cached object instead of a clone');
    $GLOBALS['wp_object_cache'] = oc_new();
    check_oc(wp_cache_get('obj') instanceof stdClass && wp_cache_get('obj')->v === 1, 'Object did not persist');

    // add / replace.
    check_oc(wp_cache_add('a', 'one') === true && wp_cache_add('a', 'two') === false && wp_cache_get('a') === 'one', 'add() overwrote an existing key');
    $GLOBALS['wp_object_cache'] = oc_new();
    check_oc(wp_cache_add('a', 'three') === false && wp_cache_get('a') === 'one', 'add() ignored a key only present in Redis (SET NX)');
    check_oc(wp_cache_replace('nope', 1) === false && wp_cache_get('nope') === false, 'replace() created a missing key');
    $GLOBALS['wp_object_cache'] = oc_new();
    check_oc(wp_cache_replace('a', 'four') === true && wp_cache_get('a') === 'four', 'replace() failed for a key present in Redis');
    $GLOBALS['suspend'] = true;
    check_oc(wp_cache_add('suspended', 1) === false, 'add() ignored wp_suspend_cache_addition()');
    $GLOBALS['suspend'] = false;
    check_oc(wp_cache_add_multiple(array('m1' => 1, 'a' => 2)) === array('m1' => true, 'a' => false), 'add_multiple results are wrong');

    // delete.
    check_oc(wp_cache_delete('a') === true && wp_cache_get('a') === false && wp_cache_delete('a') === false, 'delete() semantics are wrong');
    $GLOBALS['wp_object_cache'] = oc_new();
    check_oc(wp_cache_get('a') === false, 'Deleted key survived in Redis');
    wp_cache_set_multiple(array('d1' => 1, 'd2' => 2), 'multi');
    $GLOBALS['wp_object_cache'] = oc_new();
    check_oc(wp_cache_get_multiple(array('d1', 'd2', 'd3'), 'multi') === array('d1' => 1, 'd2' => 2, 'd3' => false), 'get_multiple results are wrong');
    check_oc(wp_cache_delete_multiple(array('d1', 'd3'), 'multi') === array('d1' => true, 'd3' => false), 'delete_multiple results are wrong');

    // incr / decr.
    wp_cache_set('n', 5);
    check_oc(wp_cache_incr('n') === 6 && wp_cache_incr('n', 4) === 10 && wp_cache_decr('n', 3) === 7, 'incr/decr arithmetic is wrong');
    check_oc(wp_cache_decr('n', 100) === 0, 'decr did not clamp at 0');
    wp_cache_set('s', 'abc');
    check_oc(wp_cache_incr('s', 2) === 2, 'A non-numeric value was not treated as 0');
    check_oc(wp_cache_incr('absent') === false && wp_cache_decr('absent') === false, 'incr on a missing key did not return false');
    $GLOBALS['wp_object_cache'] = oc_new();
    check_oc(wp_cache_get('n') === 0 && wp_cache_get('s') === 2, 'incr/decr results did not persist');

    // TTL: expire 0 = no TTL; positive expire set and preserved through incr.
    wp_cache_set('forever', 1, 'ttl', 0);
    wp_cache_set('short', 1, 'ttl', 60);
    check_oc(!isset(TkFakeRedis::$expires[2]['siteA:ttl:forever']) && TkFakeRedis::$expires[2]['siteA:ttl:short'] === TkFakeRedis::$now + 60, 'TTLs were not applied');
    wp_cache_add('short-add', 1, 'ttl', 30);
    wp_cache_incr('short', 1, 'ttl');
    check_oc(TkFakeRedis::$expires[2]['siteA:ttl:short-add'] === TkFakeRedis::$now + 30 && TkFakeRedis::$expires[2]['siteA:ttl:short'] === TkFakeRedis::$now + 60, 'add() TTL or incr TTL preservation failed');
    TkFakeRedis::$now += 61;
    $GLOBALS['wp_object_cache'] = oc_new();
    check_oc(wp_cache_get('short', 'ttl') === false && wp_cache_get('forever', 'ttl') === 1, 'Expiry did not behave');

    // Non-persistent groups stay in memory.
    wp_cache_add_non_persistent_groups(array('local'));
    wp_cache_set('x', 1, 'local');
    check_oc(wp_cache_get('x', 'local') === 1 && !preg_grep('/local/', array_keys(TkFakeRedis::$data[2])), 'Non-persistent group reached Redis');
    check_oc(wp_cache_incr('x', 1, 'local') === 2 && wp_cache_delete('x', 'local') === true, 'Non-persistent incr/delete failed');

    // flush_runtime keeps Redis; $force bypasses memory.
    wp_cache_set('rt', 'v');
    wp_cache_flush_runtime();
    check_oc(wp_cache_get('rt') === 'v', 'flush_runtime cleared Redis');
    TkFakeRedis::$data[2]['siteA:default:rt'] = serialize('changed');
    check_oc(wp_cache_get('rt') === 'v' && wp_cache_get('rt', '', true) === 'changed', '$force did not re-read Redis');

    // Multisite: blog-prefixed keys, global groups shared.
    $GLOBALS['ms'] = true;
    $GLOBALS['wp_object_cache'] = oc_new();
    wp_cache_add_global_groups(array('users'));
    wp_cache_set('k', 'blog1');
    wp_cache_set('u', 'shared', 'users');
    wp_cache_switch_to_blog(2);
    check_oc(wp_cache_get('k') === false && wp_cache_get('u', 'users') === 'shared', 'Blogs share non-global keys or do not share global ones');
    wp_cache_set('k', 'blog2');
    wp_cache_switch_to_blog(1);
    check_oc(wp_cache_get('k') === 'blog1', 'Switching back did not restore blog 1 keys');
    check_oc(isset(TkFakeRedis::$data[2]['siteA:default:1:k'], TkFakeRedis::$data[2]['siteA:default:2:k'], TkFakeRedis::$data[2]['siteA:users:u']), 'Unexpected Redis key layout: ' . implode(', ', array_keys(TkFakeRedis::$data[2])));
    $GLOBALS['ms'] = false;

    // flush and flush_group only touch this site's prefix (and that group).
    TkFakeRedis::$data[2]['siteB:default:k'] = serialize('other site');
    TkFakeRedis::$data[2]['siteA*:default:k'] = serialize('glob lookalike');
    $GLOBALS['wp_object_cache'] = oc_new();
    wp_cache_set('g1', 1, 'grp');
    wp_cache_set('g2', 2, 'grp');
    wp_cache_set('g3', 3, 'grp:sub');
    wp_cache_set('keep', 1, 'other');
    check_oc(wp_cache_flush_group('grp') === true && wp_cache_get('g1', 'grp') === false && wp_cache_get('g3', 'grp:sub') === 3 && wp_cache_get('keep', 'other') === 1, 'flush_group removed the wrong keys');
    TkFakeRedis::$calls = array();
    check_oc(wp_cache_flush() === true, 'flush() failed');
    $remaining = array_keys(TkFakeRedis::$data[2]);
    sort($remaining);
    check_oc($remaining === array('siteA*:default:k', 'siteB:default:k'), 'flush() removed other prefixes or kept its own: ' . implode(', ', $remaining));
    check_oc(in_array('scan:siteA:*', TkFakeRedis::$calls, true) && !preg_grep('/flush/i', TkFakeRedis::$calls), 'flush did not use a prefix SCAN');
    $GLOBALS['wp_object_cache'] = oc_new(array('prefix' => 'siteA*:'));
    wp_cache_flush();
    check_oc(array_keys(TkFakeRedis::$data[2]) === array('siteB:default:k'), 'Glob characters in the prefix were not escaped');

    // A Redis failure mid-request drops to in-memory without throwing.
    $GLOBALS['wp_object_cache'] = oc_new();
    wp_cache_set('before', 1);
    TkFakeRedis::$throw = true;
    check_oc(wp_cache_set('after', 2) === true && wp_cache_get('after') === 2 && wp_cache_get('before') === 1, 'Values were lost after a Redis failure');
    check_oc(wp_cache_get('never') === false && wp_cache_add('n2', 1) === true && wp_cache_incr('n2') === 2 && wp_cache_flush() === true, 'Fallback cache misbehaved');
    $status = $wp_object_cache->tk_status();
    check_oc($status['connected'] === false && $status['error'] === 'Connection lost', 'Fallback status not reported');
    TkFakeRedis::$throw = false;
    $calls = count(TkFakeRedis::$calls);
    wp_cache_get('x');
    check_oc(count(TkFakeRedis::$calls) === $calls, 'Cache retried Redis after it failed in this request');
    check_oc(wp_cache_supports('flush_group') && wp_cache_supports('get_multiple') && !wp_cache_supports('nope') && wp_cache_close() === true, 'wp_cache_supports/close wrong');
    ob_start();
    $wp_object_cache->stats();
    check_oc(strpos(ob_get_clean(), 'Cache Hits') !== false, 'stats() printed nothing');

    // Module: never overwrite a foreign drop-in; enable requires a passing test.
    $GLOBALS['tk'] = array();
    $GLOBALS['licensed'] = true;
    function tk_get_option($key, $default = null) { return $GLOBALS['tk'][$key] ?? $default; }
    function tk_update_option($key, $value) { $GLOBALS['tk'][$key] = $value; }
    function tk_update_options(array $values) { $GLOBALS['tk'] = $values + $GLOBALS['tk']; }
    function tk_license_features_enabled() { return $GLOBALS['licensed']; }
    function tk_cache_dir() { return WP_CONTENT_DIR . '/cache/tool-kits'; }
    function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
    function wp_generate_password($length = 12, $special = true, $extra = false) { return 'AbCdEfGhIj'; }
    require dirname(__DIR__) . '/includes/cache-early.php';
    require dirname(__DIR__) . '/includes/object-cache.php';
    $dropin = WP_CONTENT_DIR . '/object-cache.php';

    check_oc(tk_object_cache_dropin_state() === 'none', 'Drop-in state not none');
    $result = tk_object_cache_enable();
    check_oc(!$result['ok'] && !is_file($dropin) && strpos($result['message'], 'test') !== false, 'Enable worked without a connection test');
    $settings = tk_object_cache_settings();
    check_oc($settings === array('host' => '127.0.0.1', 'port' => 6379, 'password' => '', 'database' => 0), 'Default settings wrong');
    TkFakeRedis::$fail_connect = true;
    $test = tk_object_cache_test($settings);
    check_oc($test['ok'] === false, 'A failing connection passed the test');
    $GLOBALS['tk']['object_cache_last_test'] = array('fp' => tk_object_cache_fingerprint($settings), 'ok' => false);
    check_oc(!tk_object_cache_enable()['ok'] && !is_file($dropin), 'Enable worked after a failed test');
    $GLOBALS['tk']['object_cache_last_test'] = array('fp' => tk_object_cache_fingerprint($settings), 'ok' => true);
    $result = tk_object_cache_enable();
    check_oc(!$result['ok'] && !is_file($dropin) && $GLOBALS['tk']['object_cache_last_test']['ok'] === false, 'Enable did not re-test live before installing');
    TkFakeRedis::$fail_connect = false;
    check_oc(tk_object_cache_test($settings)['ok'], 'A working connection failed the test');
    $GLOBALS['tk']['object_cache_last_test'] = array('fp' => tk_object_cache_fingerprint($settings), 'ok' => true);
    $GLOBALS['tk']['object_cache_port'] = 6380;
    check_oc(!tk_object_cache_enable()['ok'] && !is_file($dropin), 'A test for different settings unlocked Enable');
    unset($GLOBALS['tk']['object_cache_port']);

    file_put_contents($dropin, "<?php\n// Some other object cache\n");
    $result = tk_object_cache_enable();
    check_oc(!$result['ok'] && file_get_contents($dropin) === "<?php\n// Some other object cache\n" && tk_object_cache_dropin_state() === 'foreign', 'A foreign drop-in was overwritten');
    tk_object_cache_remove();
    check_oc(file_get_contents($dropin) === "<?php\n// Some other object cache\n", 'remove() deleted a foreign drop-in');
    unlink($dropin);

    $GLOBALS['licensed'] = false;
    check_oc(!tk_object_cache_enable()['ok'] && !is_file($dropin), 'Enable worked without a license');
    $GLOBALS['licensed'] = true;

    TkFakeRedis::$data[0] = array('tk_abcdefghij:default:stale' => serialize('old'), 'siteB:default:k' => 'x');
    $result = tk_object_cache_enable();
    check_oc($result['ok'] && tk_object_cache_dropin_state() === 'toolkits' && file_get_contents($dropin) === file_get_contents(dirname(__DIR__) . '/drop-ins/object-cache.php'), 'Enable did not install the drop-in: ' . $result['message']);
    $written = include tk_object_cache_config_path();
    check_oc($written['enabled'] === true && $written['prefix'] === 'tk_abcdefghij:' && $written['host'] === '127.0.0.1', 'Config file is wrong: ' . json_encode($written));
    check_oc(array_keys(TkFakeRedis::$data[0]) === array('siteB:default:k'), 'Enable did not clear stale keys for this prefix only');
    check_oc(tk_object_cache_prefix() === 'tk_abcdefghij:', 'Prefix changed between calls');
    tk_object_cache_remove();
    check_oc(!is_file($dropin) && !is_file(tk_object_cache_config_path()), 'remove() kept our drop-in or config');

    // Uninstall removes our drop-in even without the delete-data opt-in.
    copy(dirname(__DIR__) . '/drop-ins/object-cache.php', $dropin);
    function get_option($name, $default = false) { return $default; }
    require dirname(__DIR__) . '/includes/uninstall.php';
    tk_uninstall_run();
    check_oc(!is_file($dropin), 'Uninstall kept the Tool Kits drop-in');

    // Wiring: module registered, removed on deactivation, rendered on the Cache page.
    $main = file_get_contents(dirname(__DIR__) . '/tool-kits.php');
    check_oc(strpos($main, "'object-cache.php'          => 'tk_object_cache_init'") !== false && preg_match('/function tk_deactivate\(\) \{[^}]*tk_object_cache_remove\(\);/s', $main), 'Module registration or deactivation cleanup missing');
    check_oc(strpos(file_get_contents(dirname(__DIR__) . '/includes/cache.php'), 'tk_object_cache_render_section();') !== false, 'Cache page does not render the Redis section');
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($content, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($content);
}

echo 'PASS: ' . $checks . " object-cache checks: core semantics (falsy values, clones, add/replace/incr/decr, TTL), groups, multisite, prefix-only flush, silent fallback, 30 second failure pause with flush on recovery, foreign drop-in protection, test-gated enable\n";
