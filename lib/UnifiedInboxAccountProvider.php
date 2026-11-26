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
        $encrypted = $_SESSION['password' . $postfix] ?? null;

        if (!$encrypted) {
            return null;
        }

        $host = (string) ($_SESSION['storage_host' . $postfix] ?? 'localhost');
        $ssl = $_SESSION['storage_ssl' . $postfix] ?? null;
        $port = (int) ($_SESSION['storage_port' . $postfix] ?? 143);

        $parsed = $this->parse_host($host);
        if ($parsed['ssl']) {
            $ssl = $parsed['ssl'];
        }

        $username = (string) ($this->rc->user->data['username'] ?? '');
        if ($username === '') {
            return null;
        }

        return [
            'key'       => 'primary:0',
            'label'     => (string) ($_SESSION['global_alias'] ?? $username),
            'host'      => $parsed['host'],
            'ssl'       => $ssl,
            'port'      => $port ?: ($ssl === 'ssl' ? 993 : 143),
            'username'  => $username,
            'password'  => (string) $encrypted,
            'encrypted' => true,
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
