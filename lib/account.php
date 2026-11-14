<?php
include_once "{$_SERVER['DOCUMENT_ROOT']}/lib/config.php";

const ACCOUNT_DATA_MAX_BLOB_SIZE = 2 * 1024 * 1024;

function is_encrypted_blob(mixed $blob, int $max_size = ACCOUNT_DATA_MAX_BLOB_SIZE): bool
{
    if (!is_array($blob) || !is_string($blob['iv'] ?? null) || !is_string($blob['data'] ?? null)) {
        return false;
    }

    $iv = base64_decode($blob['iv'], true);
    $data = base64_decode($blob['data'], true);

    return $iv !== false && strlen($iv) === 12
        && $data !== false && $data !== '' && strlen($blob['data']) <= $max_size;
}

class Account
{
    public function __construct(
        public readonly string $id,
        public readonly string $username,
        public readonly string $name,
        public readonly string|null $email = null,
        public readonly array $attributes = [],
        public readonly array $groups = [],
        public readonly int $logged_in_at = 0
    ) {
    }

    public function is_in_group(string $group): bool
    {
        foreach ($this->groups as $g) {
            if (strcasecmp($g, $group) === 0) {
                return true;
            }
        }

        return false;
    }

    public function is_moderator(): bool
    {
        return $this->is_in_group(CONFIG['account']['moderator_group']);
    }

    public function is_admin(): bool
    {
        return $this->is_in_group(CONFIG['account']['admin_group']);
    }

    public function to_array(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'attributes' => $this->attributes,
            'groups' => $this->groups,
            'logged_in_at' => $this->logged_in_at
        ];
    }

    public static function from_array(array $data): Account|null
    {
        if (!is_string($data['id'] ?? null) || !is_string($data['username'] ?? null)) {
            return null;
        }

        return new Account(
            $data['id'],
            $data['username'],
            is_string($data['name'] ?? null) ? $data['name'] : $data['username'],
            is_string($data['email'] ?? null) ? $data['email'] : null,
            is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
            is_array($data['groups'] ?? null) ? array_values(array_filter($data['groups'], 'is_string')) : [],
            (int) ($data['logged_in_at'] ?? 0)
        );
    }
}

interface AccountService
{
    public function get_user(): Account|null;

    public function login(array $credentials): Account|null;

    public function logout(): void;

    public function is_storage_available(): bool;

    public function get_storage_data(Account $account): array|null;

    public function put_storage_data(Account $account, array $data): bool;
}

class LDAPAccountService implements AccountService
{
    private PDO|null $db = null;

    public function __construct(private array $cfg, private array|null $storage_cfg = null)
    {
    }

    public function is_storage_available(): bool
    {
        return $this->db() !== null;
    }

    public function get_storage_data(Account $account): array|null
    {
        $db = $this->db();
        if ($db === null) {
            return null;
        }

        $stmt = $db->prepare('SELECT data FROM storages WHERE id = ?');
        $stmt->execute([$this->storage_id($account)]);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $data = json_decode($row['data'], true);
            return is_array($data) ? $data : null;
        }

        return null;
    }

    public function put_storage_data(Account $account, array $data): bool
    {
        $db = $this->db();
        if ($db === null) {
            return false;
        }

        $stmt = $db->prepare('INSERT INTO storages(id, data)
        VALUES (:id, :data)
        ON DUPLICATE KEY UPDATE
            data = :data');

        return $stmt->execute([
            ':id' => $this->storage_id($account),
            ':data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
    }

    private function storage_id(Account $account): string
    {
        return hash('sha256', "account:{$account->id}");
    }

    private function db(): PDO|null
    {
        if ($this->db === null && $this->storage_cfg !== null) {
            try {
                $this->db = new PDO($this->storage_cfg['url'], $this->storage_cfg['user'], $this->storage_cfg['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            } catch (PDOException $e) {
                error_log("Account storage: {$e->getMessage()}");
                $this->storage_cfg = null;
            }
        }

        return $this->db;
    }

    public function get_user(): Account|null
    {
        $this->session_start();

        if (is_array($_SESSION['user'] ?? null)) {
            $account = Account::from_array($_SESSION['user']);
            if ($account !== null) {
                return $account;
            }
            unset($_SESSION['user']);
        }

        $token = $_COOKIE[$this->cfg['cookie_name']] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        $credentials = $this->decode_token($token);
        $profile = null;

        if ($credentials !== null) {
            try {
                $profile = $this->authenticate($credentials[0], $credentials[1]);
            } catch (RuntimeException $e) {
                error_log("LDAP: {$e->getMessage()}");
                return null;
            }
        }

        if ($profile === null) {
            $this->set_token_cookie(null);
            return null;
        }

        $this->save_session($profile);
        return $profile;
    }

    public function login(array $credentials): Account|null
    {
        $username = is_string($credentials['username'] ?? null) ? trim($credentials['username']) : '';
        $password = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';

        if ($error = $this->validate_credentials($username, $password)) {
            throw new InvalidArgumentException($error);
        }

        $profile = $this->authenticate($username, $password);
        if ($profile === null) {
            return null;
        }

        $this->session_start();
        $this->save_session($profile);
        $this->set_token_cookie(!empty($credentials['remember']) ? $this->encode_token($username, $password) : null);

        return $profile;
    }

    public function logout(): void
    {
        $this->session_start();
        unset($_SESSION['user']);
        session_regenerate_id(true);
        $this->set_token_cookie(null);
    }

    private function validate_credentials(string $username, string $password): string|null
    {
        $min = (int) $this->cfg['username_min_length'];
        $max = (int) $this->cfg['username_max_length'];
        $ulen = strlen($username);

        if ($ulen < $min || $ulen > $max) {
            return "Username must be between $min and $max characters long";
        }

        $pmin = (int) $this->cfg['password_min_length'];
        if (strlen($password) < $pmin) {
            return "Password must be at least $pmin characters long";
        }

        return null;
    }

    private function authenticate(string $username, string $password): Account|null
    {
        if (!extension_loaded('ldap')) {
            throw new RuntimeException('LDAP extension is not installed');
        }

        if ($this->validate_credentials($username, $password) !== null) {
            return null;
        }

        $cfg = $this->cfg;

        $conn = ldap_connect($cfg['uri']);
        if (!$conn) {
            throw new RuntimeException('Failed to connect to LDAP server');
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, (int) $cfg['timeout']);

        try {
            if ($cfg['starttls'] && !@ldap_start_tls($conn)) {
                throw new RuntimeException('Failed to start TLS with LDAP server');
            }

            $attributes = $cfg['attributes'];
            $query_attributes = empty($cfg['group_base_dn'])
                ? array_values(array_unique([...$attributes, 'memberOf']))
                : $attributes;
            $entry = null;

            if (!empty($cfg['bind_dn'])) {
                if (!@ldap_bind($conn, $cfg['bind_dn'], $cfg['bind_password'] ?? '')) {
                    throw new RuntimeException('Failed to bind LDAP service account');
                }

                $filter = sprintf($cfg['user_filter'], ldap_escape($username, '', LDAP_ESCAPE_FILTER));
                $search = @ldap_search($conn, $cfg['base_dn'], $filter, $query_attributes, 0, 2);
                if (!$search) {
                    throw new RuntimeException('LDAP search failed');
                }

                $entries = ldap_get_entries($conn, $search);
                if ($entries['count'] !== 1) {
                    return null;
                }

                $entry = $entries[0];
                $user_dn = $entry['dn'];
            } else {
                $user_dn = sprintf($cfg['user_dn'], ldap_escape($username, '', LDAP_ESCAPE_DN));
            }

            if (!@ldap_bind($conn, $user_dn, $password)) {
                $errno = ldap_errno($conn);
                if ($errno === 0x31 || $errno === 0x20) {
                    return null;
                }
                throw new RuntimeException('LDAP bind failed: ' . ldap_err2str($errno));
            }

            if ($entry === null) {
                $read = @ldap_read($conn, $user_dn, '(objectClass=*)', $query_attributes);
                if ($read) {
                    $entries = ldap_get_entries($conn, $read);
                    $entry = $entries['count'] > 0 ? $entries[0] : null;
                }
            }

            $attrs = $entry !== null ? $this->extract_attributes($entry, $attributes) : [];

            return new Account(
                id: $user_dn,
                username: $username,
                name: $this->first_value($attrs['displayName'] ?? $attrs['cn'] ?? null) ?? $username,
                email: $this->first_value($attrs['mail'] ?? null),
                attributes: $attrs,
                groups: $this->load_groups($conn, $user_dn, $username, $entry),
                logged_in_at: time()
            );
        } finally {
            ldap_unbind($conn);
        }
    }

    private function load_groups($conn, string $user_dn, string $username, array|null $entry): array
    {
        $cfg = $this->cfg;

        if (empty($cfg['group_base_dn'])) {
            $groups = [];
            $member_of = $entry['memberof'] ?? [];
            unset($member_of['count']);

            foreach ($member_of as $group_dn) {
                $rdn = ldap_explode_dn($group_dn, 1);
                if ($rdn !== false && isset($rdn[0])) {
                    $groups[] = $rdn[0];
                }
            }

            return array_values(array_unique($groups));
        }

        if (!empty($cfg['bind_dn']) && !@ldap_bind($conn, $cfg['bind_dn'], $cfg['bind_password'] ?? '')) {
            error_log('LDAP: Failed to bind service account for group search');
            return [];
        }

        $filter = strtr($cfg['group_filter'], [
            '{dn}' => ldap_escape($user_dn, '', LDAP_ESCAPE_FILTER),
            '{username}' => ldap_escape($username, '', LDAP_ESCAPE_FILTER)
        ]);
        $name_attribute = $cfg['group_name_attribute'];

        $search = @ldap_search($conn, $cfg['group_base_dn'], $filter, [$name_attribute]);
        if (!$search) {
            error_log('LDAP: Group search failed: ' . ldap_error($conn));
            return [];
        }

        $groups = [];
        $entries = ldap_get_entries($conn, $search);

        for ($i = 0; $i < $entries['count']; $i++) {
            $values = $entries[$i][strtolower($name_attribute)] ?? [];
            unset($values['count']);
            foreach ($values as $value) {
                $groups[] = $value;
            }
        }

        return array_values(array_unique($groups));
    }

    private function extract_attributes(array $entry, array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attr) {
            $key = strtolower($attr);
            if (!isset($entry[$key])) {
                continue;
            }

            $values = $entry[$key];
            unset($values['count']);
            $result[$attr] = count($values) === 1 ? $values[0] : array_values($values);
        }

        return $result;
    }

    private function first_value(mixed $value): string|null
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function session_start(): void
    {
        if (session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    private function save_session(Account $account): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = $account->to_array();
    }

    private function encode_token(string $username, string $password): string
    {
        return base64_encode("$username\0$password");
    }

    private function decode_token(string $token): array|null
    {
        $data = base64_decode($token, true);
        if ($data === false) {
            return null;
        }

        $parts = explode("\0", $data, 2);
        if (count($parts) !== 2) {
            return null;
        }

        return $parts;
    }

    private function set_token_cookie(string|null $token): void
    {
        setcookie($this->cfg['cookie_name'], $token ?? '', [
            'expires' => $token === null ? time() - 3600 : time() + (int) $this->cfg['cookie_time'],
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

define('ACCOUNT_SERVICE', CONFIG['account']['enabled'] ? match (CONFIG['account']['type']) {
    'ldap' => new LDAPAccountService(
        CONFIG['account'],
        CONFIG['metadata']['type'] === 'sql' ? CONFIG['metadata'] : null
    ),
    default => null
} : null);


function is_moderator(): bool
{
    return ACCOUNT_SERVICE?->get_user()?->is_moderator() ?? false;
}
