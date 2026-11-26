<?php

/**
 * One short-lived IMAP connection for one account.
 */
class UnifiedInboxImapReader
{
    private $rc;
    private $account;

    public function __construct(rcmail $rc, array $account)
    {
        $this->rc = $rc;
        $this->account = $account;
    }

    public function latest_headers(int $limit): array
    {
        $password = $this->account['password'];
        if (!empty($this->account['encrypted'])) {
            $password = $this->rc->decrypt($password);
        }

        if (!is_string($password) || $password === '') {
            throw new RuntimeException('Could not decrypt the stored IMAP password.');
        }

        $imap = new rcube_imap_generic();
        $options = [
            'port'       => (int) $this->account['port'],
            'ssl_mode'   => $this->account['ssl'] ?: null,
            'timeout'    => 8,
        ];

        try {
            if (!$imap->connect(
                (string) $this->account['host'],
                (string) $this->account['username'],
                $password,
                $options
            )) {
                throw new RuntimeException('IMAP connection or authentication failed.');
            }

            $mailbox = 'INBOX';
            if (!$imap->select($mailbox)) {
                throw new RuntimeException('Could not select INBOX.');
            }

            // SEARCH returns UIDs, never message sequence numbers.
            $result = $imap->search($mailbox, 'ALL', true);
            if (!$result || $result->is_error() || $result->is_empty()) {
                return [];
            }

            $uids = $result->get();
            if (!is_array($uids) || !$uids) {
                return [];
            }

            rsort($uids, SORT_NUMERIC);
            $uids = array_slice($uids, 0, max(1, $limit));

            $headers = $imap->fetchHeaders($mailbox, $uids, true);
            if (!is_array($headers)) {
                throw new RuntimeException('Could not retrieve message headers.');
            }

            $messages = [];
            foreach ($headers as $header) {
                if (!is_object($header)) {
                    continue;
                }

                $uid = (int) ($header->uid ?? 0);
                if ($uid < 1) {
                    continue;
                }

                $timestamp = 0;
                if (!empty($header->internaldate)) {
                    $timestamp = is_numeric($header->internaldate)
                        ? (int) $header->internaldate
                        : (strtotime((string) $header->internaldate) ?: 0);
                }
                if (!$timestamp && !empty($header->date)) {
                    $timestamp = strtotime((string) $header->date) ?: 0;
                }

                $flags = [];
                if (!empty($header->flags) && is_array($header->flags)) {
                    $flags = array_values($header->flags);
                }

                $messages[] = [
                    'account'   => $this->account['label'],
                    'account_key' => $this->account['key'],
                    'folder'    => $mailbox,
                    'uid'       => $uid,
                    'timestamp' => $timestamp,
                    'from'      => $this->header_value($header, 'from'),
                    'subject'   => $this->header_value($header, 'subject'),
                    'flags'     => $flags,
                ];
            }

            return $messages;
        } finally {
            if ($imap->connected()) {
                $imap->closeConnection();
            }
            unset($password);
        }
    }

    private function header_value($header, string $name): string
    {
        $value = $header->$name ?? '';
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_object($item) && method_exists($item, 'mailto')) {
                    $parts[] = $item->mailto();
                } elseif (is_object($item) && isset($item->string)) {
                    $parts[] = $item->string;
                } elseif (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            }
            $value = implode(', ', $parts);
        } elseif (is_object($value) && method_exists($value, 'mailto')) {
            $value = $value->mailto();
        } elseif (is_object($value) && isset($value->string)) {
            $value = $value->string;
        } elseif (!is_scalar($value)) {
            $value = '';
        }

        return trim(strip_tags((string) $value));
    }
}
