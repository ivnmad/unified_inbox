# Unified Inbox for Roundcube (read-only prototype)

Version: 0.1.0-prototype  
Target: Roundcube 1.7.7 / PHP 8.2+  
Status: test-server prototype; not production-ready.

Requirements: Gecka ident_switch - https://github.com/Gecka-Apps/roundcube-ident_switch

## What this prototype does

- Adds a separate Unified Inbox taskbar entry.
- Reads the primary account credentials saved by Gecka ident_switch and enabled Gecka separate-account records.
- Excludes Gecka alias records (`parent_id IS NULL`).
- Lets the user select accounts and saves that selection in the user's Roundcube preferences.
- Opens a separate short-lived IMAP connection for each selected account.
- Reads INBOX headers using UIDs and merges them newest-first.
- Reports an account's connection failure without stopping other accounts.

## Important limitations

- Read-only: no message opening, flag changes, deletion, moving, replying or sending.
- This first prototype reads the Gecka database table and session keys because the inspected Gecka code does not expose a documented public account-provider API. The adapter is isolated in `lib/UnifiedInboxAccountProvider.php`. This is an implementation dependency, not a claim of a supported Gecka API.
- It expects the primary-account baseline session keys created by Gecka (`password_iswitch`, `storage_host_iswitch`, etc.). If those are absent, the primary account will not appear.
- The prototype currently fetches a bounded recent UID window per account and then sorts those headers by date. It is not yet a complete historical mailbox index.
- Exact PHP object property formats for message headers must be verified against the installed Roundcube build during the first test.
- The plugin has not yet been installed or exercised against the user's live IMAP endpoints. Treat it as experimental and test only on the isolated test server.
- The plugin does not alter Roundcube core files, Gecka files, Gecka tables or IMAP contents.

## Install on the isolated test server

1. Back up the Roundcube directory and database.
2. Copy this directory to:
   ` /var/www/html/roundcube/plugins/unified_inbox `
3. Ensure the web-server user can read the files.
4. Add `unified_inbox` to the `$config['plugins']` array in Roundcube `config/config.inc.php`.
5. Clear Roundcube cache if needed and sign in again.
6. Open the new Unified Inbox taskbar entry.

Do not replace the existing `plugins` array. Add `unified_inbox` to it.

## Syntax check

From the Roundcube directory:

```sh
find plugins/unified_inbox -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Rollback

1. Remove `unified_inbox` from `$config['plugins']`.
2. Remove `plugins/unified_inbox/`.
3. Sign out and back in, or clear the Roundcube cache.

No database migration is required. Account selections are stored as a Roundcube user preference; removing the plugin leaves that unused preference harmlessly in place.

## Security notes

- Passwords are decrypted only for the duration of an IMAP connection and are not persisted by this plugin.
- The plugin uses the logged-in Roundcube user's identity and user ID for account lookup.
- Do not expose PHP errors or debug output to the public web.
- Do not deploy this prototype to production.
