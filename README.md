# Unified Inbox for Roundcube

Unified Inbox is an independent Roundcube plugin that combines messages from the user's primary mailbox and enabled Gecka `ident_switch` accounts into one chronological inbox. Each message remains on its original IMAP server; the plugin does not forward or copy mail into a collector mailbox.

The project is intended to provide a Thunderbird-style multi-account workflow inside Roundcube while leaving Roundcube core, existing mailbox configuration, and Gecka `ident_switch` files and tables unchanged.

## Current capabilities

- Discovers the primary Roundcube login mailbox and enabled root accounts configured in Gecka `ident_switch`.
- Lets each Roundcube user choose which accounts participate in Unified Inbox.
- Retrieves recent INBOX message headers from each selected account and merges them newest-first.
- Identifies each message by its originating account and account-local IMAP UID.
- Displays the independent message states **Unread**, **Read**, **Unread Flagged**, and **Read Flagged**.
- Opens a selected message from its originating account in a separate read-only message page.
- Isolates individual account connection failures so one unavailable account does not prevent other accounts from appearing.
- Uses existing Roundcube/Gecka credential material at connection time; it does not save mailbox passwords in Unified Inbox preferences or files.

## Current limitations

This is an early prototype. The message list and message reader are functional, but the reader is not yet integrated into Roundcube's split-pane mailbox layout. Message actions are not implemented yet: marking read/unread, deleting, moving messages, and replying are planned work. Attachments and a full Roundcube-equivalent MIME rendering experience are also not yet implemented.

## Requirements

- Roundcube 1.7.x (developed and checked against 1.7.7).
- PHP 8.4 (tested for syntax with PHP 8.4).
- Gecka `ident_switch` 5.0.x for discovery of additional accounts.
- IMAP access to each participating mailbox.

The primary account can be used without switching to another Gecka account first. When Gecka has switched to a secondary account, Unified Inbox uses Gecka's preserved primary-session credentials. When opened directly from the primary account, it uses Roundcube's authenticated-user password accessor.

## Installation

Install only on the isolated test server until all account and message operations have been verified.

1. Back up the current plugin directory:

   ```bash
   cd /var/www/html/roundcube/plugins
   sudo cp -a unified_inbox unified_inbox.backup-before-0.1.7
   ```

2. Upload `unified_inbox_0.1.7.zip` to the server and extract it into the plugins directory. The ZIP contains the `unified_inbox/` directory at its root:

   ```bash
   cd /var/www/html/roundcube/plugins
   sudo unzip -o /path/to/unified_inbox_0.1.7.zip
   sudo chown -R www-data:www-data unified_inbox
   ```

3. Check PHP syntax:

   ```bash
   sudo find unified_inbox -name '*.php' -print0 | xargs -0 -n1 php -l
   ```

4. Ensure `unified_inbox` is enabled in Roundcube's plugin list. If it is already enabled, no configuration change is required.
5. Reload Roundcube and open **Settings → Unified Inbox** to choose participating accounts.

## Test checklist

- Open Unified Inbox directly while logged into the primary account; the primary email address should appear in Settings and its messages should be included when selected.
- Switch to a secondary Gecka account and open Unified Inbox; the primary account should still appear with the same email address.
- Confirm the other enabled Gecka accounts remain visible and selectable.
- Confirm messages are merged newest-first and the four status labels remain correct.
- Open messages from at least two different accounts and confirm the correct account's message is displayed.
- Confirm an unavailable account does not prevent the other selected accounts from loading.

## Integration and security notes

Gecka `ident_switch` 5.0.4 does not expose a public account-provider API in the inspected source. The plugin therefore isolates its dependency on Gecka's enabled root account rows and preserved session conventions in `UnifiedInboxAccountProvider`. It excludes alias rows to avoid duplicate mailboxes and does not modify Gecka's database or files.

The primary mailbox's live password is obtained through Roundcube's `get_user_password()` accessor when no Gecka primary snapshot exists. That plaintext value is used only in memory for the short-lived IMAP connection; it is not written to disk or saved in preferences. Gecka-stored credentials are decrypted at connection time through Roundcube.

Message bodies are sanitized with Roundcube's HTML sanitizer and remote resources are blocked by default. As with any mail client, message content is untrusted input.

## Rollback

On the test server:

```bash
cd /var/www/html/roundcube/plugins
sudo rm -rf unified_inbox
sudo mv unified_inbox.backup-before-0.1.7 unified_inbox
```

Do not run the rollback if the backup directory does not exist. Do not deploy to production until the test checklist has passed.

## Project conventions

Release notes are maintained separately in [CHANGELOG.md](CHANGELOG.md), following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0/).
