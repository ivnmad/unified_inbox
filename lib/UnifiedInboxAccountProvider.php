<?php

/**
 * Reads the primary account session and enabled Gecka separate-account rows.
 *
 * Gecka does not expose a public account-provider API in the inspected source.
 * This adapter deliberately isolates the schema/session dependency.
 */
class UnifiedInboxAccountProvider
{
    private $rc;

    public function __construct(rcmail $rc)
    {
        $this->rc = $rc;
    }

    public function get_accounts(): array
    {
        $accounts = [];
        $primary = $this->get_primary_account();

        if ($primary) {
            $accounts[] = $primary;
        }

        if (!class_exists('ident_switch')) {
            return $accounts;
        }

        $sql = 'SELECT isw.id, isw.iid, isw.label, isw.username, isw.password, '
            . 'isw.imap_host, isw.imap_port, isw.flags, ii.email '
            . 'FROM ' . $this->rc->db->table_name('ident_switch') . ' isw '
            . 'INNER JOIN ' . $this->rc->db->table_name('identities') . ' ii ON isw.iid = ii.identity_id '
            . 'WHERE isw.user_id = ? AND (isw.flags & 1) > 0 AND isw.parent_id IS NULL '
            . 'ORDER BY isw.id';

        $query = $this->rc->db->query($sql, $this->rc->user->ID);

        while ($row = $this->rc->db->fetch_assoc($query)) {
            $host_info = $this->parse_host((string) ($row['imap_host'] ?: 'localhost'));
            $username = trim((string) ($row['username'] ?? ''));
            if ($username === '' && class_exists('ident_switch') && method_exists('ident_switch', 'resolve_username')) {
                $username = ident_switch::resolve_username((int) $row['iid'], $username);
            }
            if ($username === '') {
                $username = (string) $row['email'];
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                $label = $username ?: (string) $row['email'];
            }

            $accounts[] = [
                'key'       => 'gecka:' . (int) $row['id'],
                'label'     => $label,
                'host'      => $host_info['host'],
                'ssl'       => $host_info['ssl'] ?: (((int) $row['flags'] & 4) ? 'tls' : null),
                'port'      => (int) ($row['imap_port'] ?: (($host_info['ssl'] ?: (((int) $row['flags'] & 4) ? 'tls' : null)) === 'ssl' ? 993 : 143)),
                'username'  => $username,
                'password'  => (string) $row['password'],
                'encrypted' => true,
                'source'    => 'gecka',
                'iid'       => (int) $row['iid'],
            ];
        }

        return $accounts;
    }

    private function get_primary_account(): ?array
    {
        $postfix = '_iswitch';
        $username = (string) ($this->rc->user->data['username'] ?? '');
        if ($username === '') {
            return null;
        }

        // Gecka sets iid_iswitch to -1 when the user switches back to the
        // primary mailbox. On that path it restores the primary connection
        // details into the ordinary Roundcube session keys, while the
        // _iswitch snapshot remains present. Always prefer the active session
        // credentials when the primary mailbox is active; otherwise a stale
        // or out-of-sync snapshot can cause authentication to fail.
        $active_username = (string) ($_SESSION['username'] ?? '');
        $active_iid = $_SESSION['iid' . $postfix] ?? null;
        $primary_is_active = ($active_username !== '' && strcasecmp($active_username, $username) === 0)
            || (is_numeric($active_iid) && (int) $active_iid === -1);

        if ($primary_is_active) {
            $password = $_SESSION['password'] ?? null;
            $encrypted = true;

            // Some Roundcube authentication backends do not retain the
            // encrypted password in the session. In that case, use the
            // authenticated-user accessor, which returns plaintext.
            if (!is_string($password) || $password === '') {
                $password = method_exists($this->rc, 'get_user_password')
                    ? $this->rc->get_user_password()
                    : null;
                $encrypted = false;
            }

            $host = (string) ($_SESSION['storage_host'] ?? 'localhost');
            $ssl = $_SESSION['storage_ssl'] ?? null;
            $port = (int) ($_SESSION['storage_port'] ?? 143);
        } else {
            // While Gecka is impersonating a secondary account, it preserves
            // the original primary account's encrypted credentials and
            // storage settings under the _iswitch suffix.
            $password = $_SESSION['password' . $postfix] ?? null;
            if (!is_string($password) || $password === '') {
                return null;
            }

            $encrypted = true;
            $host = (string) ($_SESSION['storage_host' . $postfix] ?? 'localhost');
            $ssl = $_SESSION['storage_ssl' . $postfix] ?? null;
            $port = (int) ($_SESSION['storage_port' . $postfix] ?? 143);
        }

        if (!is_string($password) || $password === '') {
            return null;
        }

        $parsed = $this->parse_host($host);
        if ($parsed['ssl']) {
            $ssl = $parsed['ssl'];
        }

        $identity = $this->rc->user->get_identity();
        $label = trim((string) ($identity['email'] ?? ''));
        if ($label === '') {
            $label = $username;
        }

        return [
            'key'       => 'primary:0',
            'label'     => $label,
            'host'      => $parsed['host'],
            'ssl'       => $ssl,
            'port'      => $port ?: ($ssl === 'ssl' ? 993 : 143),
            'username'  => $username,
            'password'  => $password,
            'encrypted' => $encrypted,
            'source'    => 'primary',
            'iid'       => 0,
        ];
    }

    private function parse_host(string $host): array
    {
        $ssl = null;
        if (stripos($host, 'ssl://') === 0) {
            $ssl = 'ssl';
            $host = substr($host, 6);
        } elseif (stripos($host, 'tls://') === 0) {
            $ssl = 'tls';
            $host = substr($host, 6);
        }

        return ['host' => $host, 'ssl' => $ssl];
    }
}
