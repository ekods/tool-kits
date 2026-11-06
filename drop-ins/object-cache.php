<?php
// Tool Kits object-cache v1. Managed by Tool Kits; removed when Tool Kits is deactivated.
// Persistent WordPress object cache backed by Redis (phpredis). When Redis is unavailable at load time this file
// defines nothing, so WordPress loads its own non-persistent cache. A Redis error later in the request switches
// this cache to in-memory only for the rest of the request. No exception or warning ever leaves this file.
// TK_REDIS_CLASS exists for the regression tests (phpredis is often compiled in, so a fake cannot be named Redis).
if (!defined('WP_CONTENT_DIR') || !class_exists(defined('TK_REDIS_CLASS') ? (string) TK_REDIS_CLASS : 'Redis')
    || function_exists('wp_cache_init') || class_exists('WP_Object_Cache', false)) { return; }

$GLOBALS['tk_object_cache_boot'] = (static function () {
    $state = array('redis' => null, 'config' => array(), 'error' => '');
    // After a failed connection, skip Redis for 30 seconds: an unreachable host would otherwise cost every request
    // the full connect timeout.
    $down = WP_CONTENT_DIR . '/cache/tool-kits/object-cache-down';
    try {
        $file = WP_CONTENT_DIR . '/cache/tool-kits/object-cache-config.php';
        $config = is_file($file) ? @include $file : null;
        if (!is_array($config) || empty($config['enabled'])) { $state['error'] = 'Config missing'; return $state; }
        foreach (array('host' => 'TK_REDIS_HOST', 'port' => 'TK_REDIS_PORT', 'password' => 'TK_REDIS_PASSWORD', 'database' => 'TK_REDIS_DATABASE') as $field => $constant) {
            if (defined($constant)) { $config[$field] = constant($constant); }
        }
        if (is_file($down) && time() - (int) @filemtime($down) < 30) { $state['error'] = 'Redis recently unreachable'; return $state; }
        $prefix = isset($config['prefix']) ? (string) $config['prefix'] : '';
        if ($prefix === '' && defined('WP_CACHE_KEY_SALT') && (string) WP_CACHE_KEY_SALT !== '') { $prefix = (string) WP_CACHE_KEY_SALT; }
        if ($prefix === '') { $prefix = 'tk_' . substr(md5(WP_CONTENT_DIR), 0, 10); }
        $prefix = preg_replace('/[\s\x00-\x1f]+/', '', $prefix);
        $config['prefix'] = substr($prefix, -1) === ':' ? $prefix : $prefix . ':';
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $timeout = max(0.1, min(5.0, (float) ($config['timeout'] ?? 1.0)));
        $class = defined('TK_REDIS_CLASS') ? (string) TK_REDIS_CLASS : 'Redis';
        $redis = new $class();
        $socket = $host !== '' && ($host[0] === '/' || strpos($host, 'unix://') === 0);
        $ok = $socket ? @$redis->connect($host, 0, $timeout, null, 0, $timeout)
            : @$redis->connect($host === '' ? '127.0.0.1' : $host, (int) ($config['port'] ?? 6379), $timeout, null, 0, $timeout);
        if (!$ok) { @touch($down); $state['error'] = 'Connection failed'; return $state; }
        if ((string) ($config['password'] ?? '') !== '' && !@$redis->auth((string) $config['password'])) { @touch($down); $state['error'] = 'Authentication failed'; return $state; }
        if ((int) ($config['database'] ?? 0) !== 0 && !@$redis->select((int) $config['database'])) { @touch($down); $state['error'] = 'Database select failed'; return $state; }
        // Redis is back after a pause: deletes made while it was unreachable never arrived, so this request clears
        // this site's keys before use (only the request that removes the marker does it).
        $state['recovered'] = is_file($down) && @unlink($down);
        $state['redis'] = $redis;
        $state['config'] = $config;
    } catch (Throwable $error) {
        @touch($down);
        $state['redis'] = null;
        $state['error'] = $error->getMessage();
    }
    return $state;
})();

if (!$GLOBALS['tk_object_cache_boot']['redis']) { return; }

if (!class_exists('WP_Object_Cache', false)) {

class WP_Object_Cache {
    public $cache_hits = 0;
    public $cache_misses = 0;
    public $redis_calls = 0;
    public $global_groups = array();
    public $non_persistent_groups = array();
    public $blog_prefix = '';
    public $multisite = false;
    /** @var array<string,array> */
    private $cache = array();
    /** @var Redis|null */
    private $redis;
    private $prefix = 'tk:';
    private $error = '';
    private $server = '';

    public function __construct($redis = null, array $config = array()) {
        $this->redis = $redis;
        $this->prefix = isset($config['prefix']) && $config['prefix'] !== '' ? (string) $config['prefix'] : 'tk:';
        $host = (string) ($config['host'] ?? '');
        $this->server = $host !== '' && $host[0] !== '/' && strpos($host, 'unix://') !== 0 ? $host . ':' . (int) ($config['port'] ?? 6379) : $host;
        $this->multisite = function_exists('is_multisite') && is_multisite();
        $this->blog_prefix = $this->multisite && function_exists('get_current_blog_id') ? get_current_blog_id() . ':' : '';
    }

    /** Runs a Redis call; on any failure the cache drops to in-memory only for the rest of the request. */
    private function call(callable $callback, $default = false) {
        if ($this->redis === null) { return $default; }
        try {
            $this->redis_calls++;
            return $callback($this->redis);
        } catch (Throwable $error) {
            $this->error = $error->getMessage();
            // Same 30-second pause as a failed connection: a half-dead Redis would otherwise stall every request.
            @touch(WP_CONTENT_DIR . '/cache/tool-kits/object-cache-down');
            $redis = $this->redis;
            $this->redis = null;
            try { @$redis->close(); } catch (Throwable $ignored) {}
            return $default;
        }
    }

    private function valid_key($key): bool {
        return is_int($key) || (is_string($key) && trim($key) !== '');
    }

    private function group($group): string {
        return ($group === '' || $group === null) ? 'default' : (string) $group;
    }

    private function key($key, string $group): string {
        return $this->multisite && !isset($this->global_groups[$group]) ? $this->blog_prefix . $key : (string) $key;
    }

    private function persistent(string $group): bool {
        return $this->redis !== null && !isset($this->non_persistent_groups[$group]);
    }

    private function redis_key(string $key, string $group): string {
        return $this->prefix . str_replace(array('%', ':'), array('%25', '%3A'), $group) . ':' . $key;
    }

    private static function glob_escape(string $text): string {
        return preg_replace('/([*?\[\]\\\\^])/', '\\\\$1', $text);
    }

    private static function copy($value) {
        return is_object($value) ? clone $value : $value;
    }

    /** @return array{0:bool,1:mixed} */
    private static function decode($raw): array {
        if (!is_string($raw)) { return array(false, false); }
        if ($raw === 'b:0;') { return array(true, false); }
        $value = @unserialize($raw);
        return $value === false ? array(false, false) : array(true, $value);
    }

    /** Looks a derived key up in memory, then Redis. @return array{0:bool,1:mixed} */
    private function fetch(string $key, string $group, bool $force = false): array {
        if (!$force && isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group])) {
            return array(true, $this->cache[$group][$key]);
        }
        if (!$this->persistent($group)) {
            return isset($this->cache[$group]) && array_key_exists($key, $this->cache[$group]) ? array(true, $this->cache[$group][$key]) : array(false, false);
        }
        $redis_key = $this->redis_key($key, $group);
        $result = self::decode($this->call(static function ($redis) use ($redis_key) { return $redis->get($redis_key); }));
        if ($result[0]) { $this->cache[$group][$key] = $result[1]; }
        return $result;
    }

    private function persist(string $key, string $group, $value, int $expire, bool $only_new = false): bool {
        if (!$this->persistent($group)) { return true; }
        $redis_key = $this->redis_key($key, $group);
        $payload = serialize($value);
        $options = array();
        if ($only_new) { $options[] = 'nx'; }
        if ($expire > 0) { $options['ex'] = $expire; }
        // A Redis failure keeps the value in memory, as core would: report the write as done unless SET NX lost.
        $result = $this->call(static function ($redis) use ($redis_key, $payload, $options) {
            return $options ? $redis->set($redis_key, $payload, $options) : $redis->set($redis_key, $payload);
        }, null);
        return $result === null || $result !== false || !$only_new;
    }

    public function add($key, $data, $group = 'default', $expire = 0) {
        if (function_exists('wp_suspend_cache_addition') && wp_suspend_cache_addition()) { return false; }
        if (!$this->valid_key($key)) { return false; }
        $group = $this->group($group);
        $id = $this->key($key, $group);
        if (isset($this->cache[$group]) && array_key_exists($id, $this->cache[$group])) { return false; }
        $data = self::copy($data);
        if ($this->persistent($group)) {
            if (!$this->persist($id, $group, $data, (int) $expire, true)) { return false; }
        }
        $this->cache[$group][$id] = $data;
        return true;
    }

    public function add_multiple(array $data, $group = '', $expire = 0) {
        $values = array();
        foreach ($data as $key => $value) { $values[$key] = $this->add($key, $value, $group, $expire); }
        return $values;
    }

    public function replace($key, $data, $group = 'default', $expire = 0) {
        if (!$this->valid_key($key)) { return false; }
        $group = $this->group($group);
        list($exists) = $this->fetch($this->key($key, $group), $group);
        return $exists ? $this->set($key, $data, $group, $expire) : false;
    }

    public function set($key, $data, $group = 'default', $expire = 0) {
        if (!$this->valid_key($key)) { return false; }
        $group = $this->group($group);
        $id = $this->key($key, $group);
        $data = self::copy($data);
        $this->cache[$group][$id] = $data;
        $this->persist($id, $group, $data, (int) $expire);
        return true;
    }

    public function set_multiple(array $data, $group = '', $expire = 0) {
        $values = array();
        foreach ($data as $key => $value) { $values[$key] = $this->set($key, $value, $group, $expire); }
        return $values;
    }

    public function get($key, $group = 'default', $force = false, &$found = null) {
        if (!$this->valid_key($key)) { $found = false; return false; }
        $group = $this->group($group);
        list($found, $value) = $this->fetch($this->key($key, $group), $group, (bool) $force);
        if ($found) { $this->cache_hits++; return self::copy($value); }
        $this->cache_misses++;
        return false;
    }

    public function get_multiple($keys, $group = 'default', $force = false) {
        $group = $this->group($group);
        $values = array();
        $missing = array();
        foreach ((array) $keys as $key) {
            if (!$this->valid_key($key)) { $values[$key] = false; continue; }
            $id = $this->key($key, $group);
            if (!$force && isset($this->cache[$group]) && array_key_exists($id, $this->cache[$group])) {
                $this->cache_hits++;
                $values[$key] = self::copy($this->cache[$group][$id]);
            } else {
                $values[$key] = false;
                $missing[$key] = $id;
            }
        }
        if (!$missing) { return $values; }
        $raw = array();
        if ($this->persistent($group)) {
            $redis_keys = array();
            foreach ($missing as $id) { $redis_keys[] = $this->redis_key($id, $group); }
            $raw = $this->call(static function ($redis) use ($redis_keys) { return $redis->mget($redis_keys); }, array());
            $raw = is_array($raw) ? array_values($raw) : array();
        }
        $i = 0;
        foreach ($missing as $key => $id) {
            list($found, $value) = self::decode($raw[$i++] ?? false);
            if ($found) {
                $this->cache[$group][$id] = $value;
                $this->cache_hits++;
                $values[$key] = self::copy($value);
            } else {
                $this->cache_misses++;
            }
        }
        return $values;
    }

    public function delete($key, $group = 'default', $deprecated = false) {
        if (!$this->valid_key($key)) { return false; }
        $group = $this->group($group);
        $id = $this->key($key, $group);
        $existed = isset($this->cache[$group]) && array_key_exists($id, $this->cache[$group]);
        unset($this->cache[$group][$id]);
        if (!$this->persistent($group)) { return $existed; }
        $redis_key = $this->redis_key($id, $group);
        $deleted = $this->call(static function ($redis) use ($redis_key) { return $redis->del($redis_key); }, 0);
        return $existed || (int) $deleted > 0;
    }

    public function delete_multiple(array $keys, $group = '') {
        $values = array();
        foreach ($keys as $key) { $values[$key] = $this->delete($key, $group); }
        return $values;
    }

    private function offset($key, $offset, $group, int $sign) {
        if (!$this->valid_key($key)) { return false; }
        $group = $this->group($group);
        $id = $this->key($key, $group);
        list($exists, $value) = $this->fetch($id, $group);
        if (!$exists) { return false; }
        if (!is_numeric($value)) { $value = 0; }
        $value += $sign * (int) $offset;
        if ($value < 0) { $value = 0; }
        $this->cache[$group][$id] = $value;
        if ($this->persistent($group)) {
            $redis_key = $this->redis_key($id, $group);
            $ttl = (int) $this->call(static function ($redis) use ($redis_key) { return $redis->ttl($redis_key); }, -1);
            $this->persist($id, $group, $value, $ttl > 0 ? $ttl : 0);
        }
        return $value;
    }

    public function incr($key, $offset = 1, $group = 'default') {
        return $this->offset($key, $offset, $group, 1);
    }

    public function decr($key, $offset = 1, $group = 'default') {
        return $this->offset($key, $offset, $group, -1);
    }

    /** Deletes Redis keys matching a pattern under this site's prefix, in SCAN batches. Never FLUSHDB. */
    private function delete_matching(string $pattern): bool {
        if ($this->redis === null) { return true; }
        return (bool) $this->call(static function ($redis) use ($pattern) {
            $iterator = null;
            do {
                $keys = $redis->scan($iterator, $pattern, 500);
                if (is_array($keys) && $keys) { $redis->del($keys); }
            } while (!empty($iterator));
            return true;
        }, false);
    }

    public function flush() {
        $this->cache = array();
        return $this->delete_matching(self::glob_escape($this->prefix) . '*');
    }

    public function flush_runtime() {
        $this->cache = array();
        return true;
    }

    public function flush_group($group) {
        $group = $this->group($group);
        unset($this->cache[$group]);
        if (isset($this->non_persistent_groups[$group])) { return true; }
        return $this->delete_matching(self::glob_escape($this->redis_key('', $group)) . '*');
    }

    public function add_global_groups($groups) {
        foreach ((array) $groups as $group) { $this->global_groups[(string) $group] = true; }
    }

    public function add_non_persistent_groups($groups) {
        foreach ((array) $groups as $group) { $this->non_persistent_groups[(string) $group] = true; }
    }

    public function switch_to_blog($blog_id) {
        $this->blog_prefix = $this->multisite ? (int) $blog_id . ':' : '';
    }

    /** Deprecated in core: clears this blog's in-memory values. */
    public function reset() {
        foreach (array_keys($this->cache) as $group) {
            if (!isset($this->global_groups[$group])) { unset($this->cache[$group]); }
        }
    }

    public function close() {
        $this->call(static function ($redis) { return $redis->close(); });
        $this->redis = null;
        return true;
    }

    public function stats() {
        echo '<p>';
        echo '<strong>Cache Hits:</strong> ' . (int) $this->cache_hits . '<br />';
        echo '<strong>Cache Misses:</strong> ' . (int) $this->cache_misses . '<br />';
        echo '<strong>Redis:</strong> ' . ($this->redis !== null ? 'connected' : 'not connected') . '<br />';
        echo '</p><ul>';
        foreach ($this->cache as $group => $cache) {
            echo '<li><strong>Group:</strong> ' . htmlspecialchars((string) $group, ENT_QUOTES) . ' - ( ' . number_format(strlen(serialize($cache)) / 1024, 2) . 'k )</li>';
        }
        echo '</ul>';
    }

    /** Status for the Tool Kits admin screen. */
    public function tk_status(): array {
        $total = $this->cache_hits + $this->cache_misses;
        return array(
            'connected' => $this->redis !== null, 'error' => $this->error, 'server' => $this->server, 'prefix' => $this->prefix,
            'hits' => $this->cache_hits, 'misses' => $this->cache_misses, 'ratio' => $total > 0 ? round($this->cache_hits / $total * 100, 1) : 0.0,
        );
    }
}

function wp_cache_init() {
    $boot = $GLOBALS['tk_object_cache_boot'] ?? array();
    $GLOBALS['wp_object_cache'] = new WP_Object_Cache($boot['redis'] ?? null, $boot['config'] ?? array());
    if (!empty($boot['recovered'])) { $GLOBALS['wp_object_cache']->flush(); }
    unset($GLOBALS['tk_object_cache_boot']);
}

function wp_cache_add($key, $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add($key, $data, $group, (int) $expire);
}

function wp_cache_add_multiple(array $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->add_multiple($data, $group, (int) $expire);
}

function wp_cache_replace($key, $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->replace($key, $data, $group, (int) $expire);
}

function wp_cache_set($key, $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set($key, $data, $group, (int) $expire);
}

function wp_cache_set_multiple(array $data, $group = '', $expire = 0) {
    global $wp_object_cache;
    return $wp_object_cache->set_multiple($data, $group, (int) $expire);
}

function wp_cache_get($key, $group = '', $force = false, &$found = null) {
    global $wp_object_cache;
    return $wp_object_cache->get($key, $group, $force, $found);
}

function wp_cache_get_multiple($keys, $group = '', $force = false) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple($keys, $group, $force);
}

function wp_cache_delete($key, $group = '') {
    global $wp_object_cache;
    return $wp_object_cache->delete($key, $group);
}

function wp_cache_delete_multiple(array $keys, $group = '') {
    global $wp_object_cache;
    return $wp_object_cache->delete_multiple($keys, $group);
}

function wp_cache_incr($key, $offset = 1, $group = '') {
    global $wp_object_cache;
    return $wp_object_cache->incr($key, $offset, $group);
}

function wp_cache_decr($key, $offset = 1, $group = '') {
    global $wp_object_cache;
    return $wp_object_cache->decr($key, $offset, $group);
}

function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group($group) {
    global $wp_object_cache;
    return $wp_object_cache->flush_group($group);
}

function wp_cache_supports($feature) {
    return in_array($feature, array('add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group'), true);
}

function wp_cache_close() {
    return true;
}

function wp_cache_add_global_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups($groups) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups($groups);
}

function wp_cache_switch_to_blog($blog_id) {
    global $wp_object_cache;
    $wp_object_cache->switch_to_blog($blog_id);
}

function wp_cache_reset() {
    global $wp_object_cache;
    $wp_object_cache->reset();
}

}
