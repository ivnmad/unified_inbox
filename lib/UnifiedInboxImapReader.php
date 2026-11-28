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

                // rcube_imap_generic may expose flags as an associative
                // name => enabled map. Keep names: discarding keys turns
                // values such as 1, 1 into meaningless numeric statuses.
                $flags = is_array($header->flags ?? null) ? $header->flags : [];
                $seen = $this->has_flag($flags, 'seen');
                $flagged = $this->has_flag($flags, 'flagged');

                $messages[] = [
                    'account'   => $this->account['label'],
                    'account_key' => $this->account['key'],
                    'folder'    => $mailbox,
                    'uid'       => $uid,
                    'timestamp' => $timestamp,
                    'from'      => $this->header_value($header, 'from'),
                    'subject'   => $this->header_value($header, 'subject'),
                    'flags'     => $flags,
                    'seen'      => $seen,
                    'flagged'   => $flagged,
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


    /** Detect a system flag in either name=>boolean or indexed-name form. */
    private function has_flag(array $flags, string $wanted): bool
    {
        foreach ($flags as $key => $value) {
            $key_name = strtolower(ltrim((string) $key, "\\"));
            $value_name = is_scalar($value) ? strtolower(ltrim((string) $value, "\\")) : '';

            if ($key_name === $wanted && $this->flag_enabled($value)) {
                return true;
            }
            if ($value_name === $wanted || $value_name === 'system_' . $wanted) {
                return true;
            }
        }

        return false;
    }

    /** Interpret common IMAP boolean representations without treating 0 as set. */
    private function flag_enabled($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        return in_array(strtolower(trim((string) $value)), ['true', 'yes', 'on'], true);
    }

    private function header_value($header, string $name): string
    {
        $value = $header->$name ?? '';

        // Roundcube's IMAP parser can expose address headers as arrays of
        // rcube_address objects. Preserve both the display name and address;
        // mailto() alone contains only the address and loses encoded names.
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $part = $this->normalize_header_component($item);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            $value = implode(', ', $parts);
        } else {
            $value = $this->normalize_header_component($value);
        }

        return trim($value);
    }

    /** Convert a scalar or Roundcube address object to decoded header text. */
    private function normalize_header_component($value): string
    {
        if (is_object($value)) {
            $address = method_exists($value, 'mailto') ? (string) $value->mailto() : '';
            $name = isset($value->name) && is_scalar($value->name)
                ? (string) $value->name
                : '';

            // Some Roundcube versions expose the display phrase as `string`.
            if ($name === '' && isset($value->string) && is_scalar($value->string)) {
                $candidate = (string) $value->string;
                if ($candidate !== $address) {
                    $name = $candidate;
                }
            }

            if ($address !== '') {
                $name = $this->decode_header_text($name);
                return $name !== '' ? $name . ' <' . $address . '>' : $address;
            }

            if (isset($value->string) && is_scalar($value->string)) {
                $value = (string) $value->string;
            } else {
                return '';
            }
        }

        if (!is_scalar($value)) {
            return '';
        }

        return $this->decode_header_text((string) $value);
    }

    /** Decode RFC 2047 encoded words using Roundcube's MIME decoder. */
    private function decode_header_text(string $value): string
    {
        if (class_exists('rcube_mime') && $value !== '') {
            $value = rcube_mime::decode_mime_string($value);
        }

        return trim(strip_tags($value));
    }

    /** Fetch one message by account-local UID without changing its flags. */
    public function fetch_message(int $uid, string $mailbox = 'INBOX'): array
    {
        if ($uid < 1 || $mailbox !== 'INBOX') {
            throw new InvalidArgumentException('Invalid message reference.');
        }

        $password = $this->account['password'];
        if (!empty($this->account['encrypted'])) {
            $password = $this->rc->decrypt($password);
        }
        if (!is_string($password) || $password === '') {
            throw new RuntimeException('Could not decrypt the stored IMAP password.');
        }

        $imap = new rcube_imap_generic();
        $options = [
            'port' => (int) $this->account['port'],
            'ssl_mode' => $this->account['ssl'] ?: null,
            'timeout' => 12,
        ];

        try {
            if (!$imap->connect((string) $this->account['host'], (string) $this->account['username'], $password, $options)) {
                throw new RuntimeException('IMAP connection or authentication failed.');
            }
            if (!$imap->select($mailbox)) {
                throw new RuntimeException('Could not select the requested mailbox.');
            }

            $header = $imap->fetchHeader($mailbox, $uid, true, true);
            if (!$header || !is_object($header)) {
                throw new RuntimeException('The message no longer exists in this mailbox.');
            }

            $structure = $header->bodystructure ?? $imap->getStructure($mailbox, $uid, true);
            if (!is_array($structure) || !$structure) {
                throw new RuntimeException('The message body structure could not be read.');
            }

            $parts = [];
            $this->collect_text_parts($structure, '', $parts);
            // Prefer HTML, but fall back to plain text. Only the first suitable
            // part of each type is used, avoiding attachment content in the view.
            $chosen = null;
            foreach (['text/html', 'text/plain'] as $wanted) {
                foreach ($parts as $part) {
                    if ($part['type'] === $wanted) {
                        $chosen = $part;
                        break 2;
                    }
                }
            }

            $body_html = '<p class="hint">This message has no displayable text body.</p>';
            if ($chosen) {
                $part_id = $chosen['part'] !== '' ? $chosen['part'] : 'TEXT';
                $body = $imap->handlePartBody(
                    $mailbox,
                    $uid,
                    true,
                    $part_id,
                    $chosen['encoding'],
                    null,
                    null,
                    false,
                    2097152
                );
                if (is_string($body)) {
                    $charset = $chosen['charset'] ?: 'UTF-8';
                    if (class_exists('rcube_charset')) {
                        $body = rcube_charset::convert($body, $charset, RCUBE_CHARSET);
                    }
                    if ($chosen['type'] === 'text/html') {
                        $washer = new rcube_washtml(['allow_remote' => false, 'charset' => RCUBE_CHARSET]);
                        $body_html = '<div class="unified-message-html">' . $washer->wash($body) . '</div>';
                    } else {
                        $body_html = '<div class="unified-message-plain">' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, RCUBE_CHARSET)) . '</div>';
                    }
                }
            }

            return [
                'uid' => $uid,
                'from' => $this->decode_header_value($header->from ?? ''),
                'to' => $this->decode_header_value($header->to ?? ''),
                'cc' => $this->decode_header_value($header->cc ?? ''),
                'subject' => $this->decode_header_value($header->subject ?? ''),
                'date' => (string) ($header->date ?? $header->internaldate ?? ''),
                'body_html' => $body_html,
            ];
        } finally {
            if ($imap->connected()) {
                $imap->closeConnection();
            }
            unset($password);
        }
    }

    /** Find leaf text parts by IMAP BODYSTRUCTURE part identifier. */
    private function collect_text_parts(array $node, string $path, array &$parts): void
    {
        // Multipart BODYSTRUCTURE begins with one or more child arrays.
        // Other leaf extensions (e.g. disposition/language) can also be arrays,
        // so do not mistake those for body parts.
        if (isset($node[0]) && is_array($node[0])) {
            $index = 0;
            while (isset($node[$index]) && is_array($node[$index])) {
                $part_id = $path === '' ? (string) ($index + 1) : $path . '.' . ($index + 1);
                $this->collect_text_parts($node[$index], $part_id, $parts);
                $index++;
            }
            return;
        }

        // Leaf BODYSTRUCTURE fields follow the IMAP BODYSTRUCTURE layout.
        // Skip explicit attachments so their text is not mistaken for the body.
        foreach ([8, 9] as $disposition_index) {
            $disposition = $node[$disposition_index] ?? null;
            if (is_array($disposition) && strtolower((string) ($disposition[0] ?? '')) === 'attachment') {
                return;
            }
        }
        $type = strtolower((string) ($node[0] ?? ''));
        $subtype = strtolower((string) ($node[1] ?? ''));
        if ($type === 'text' && in_array($subtype, ['plain', 'html'], true)) {
            $params = is_array($node[2] ?? null) ? $node[2] : [];
            $charset = '';
            foreach ($params as $k => $v) {
                if (strtolower((string) $k) === 'charset') {
                    $charset = (string) $v;
                }
            }
            $parts[] = [
                'part' => $path,
                'type' => 'text/' . $subtype,
                'encoding' => $node[5] ?? null,
                'charset' => $charset,
            ];
        }
    }

    private function decode_header_value($value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map(static function ($item) {
                if (is_object($item) && isset($item->string)) return $item->string;
                return is_scalar($item) ? (string) $item : '';
            }, $value));
        } elseif (is_object($value) && isset($value->string)) {
            $value = $value->string;
        }
        $value = is_scalar($value) ? (string) $value : '';
        if (class_exists('rcube_mime')) {
            $value = rcube_mime::decode_mime_string($value);
        }
        return trim(strip_tags($value));
    }

}
