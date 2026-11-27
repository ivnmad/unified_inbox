# Unified Inbox 0.1.5

Independent Roundcube plugin prototype for a read-only merged view of the primary mailbox and enabled Gecka `ident_switch` accounts.

## Changes in 0.1.5

- Restores the primary login mailbox in account preferences when Gecka has not yet created a saved `_iswitch` primary-session snapshot. The live Roundcube session is used only when its active username matches the login username.
- Keeps the existing saved-primary-session path when Gecka has switched to a secondary account.

## Changes in 0.1.4

- Makes each subject a link to a Unified Inbox message-reading page.
- Opens the message using its originating account key and account-local IMAP UID, always in that account's INBOX.
- Fetches headers and a text body through a separate short-lived IMAP connection; it does not switch Gecka's active account.
- Prefers HTML when available and sanitizes it with Roundcube's `rcube_washtml` while blocking remote resources; falls back to plain text.
- Keeps the four independent status labels unchanged.
- Does not yet implement mark read/unread, delete, move, attachments, or reply. Message opening is not intended to alter flags.

## Changes in 0.1.3

- Replaces raw numeric IMAP flag values with the four requested statuses: **Unread**, **Read**, **Unread Flagged**, and **Read Flagged**.
- Interprets `\Seen` and `\Flagged` independently, retaining the flag names rather than flattening the IMAP flag map into numeric values.
- This remains a read-only release: it does not change message flags or mailbox contents.

## Changes in 0.1.2

- Fixes the empty page in 0.1.1. The plugin now registers the `plugin.body` handler expected by Roundcube's built-in `plugin` skin template.
- Adds a native **Settings → Unified Inbox** entry through Roundcube's `settings_actions` hook.
- Moves account selection to that Settings page and saves it in the logged-in Roundcube user's preferences.
- Keeps the Gecka selector entry and preserves Gecka's original account-switch handler for real account choices.
- Account failures remain isolated; the page shows a generic account error and logs technical details server-side.

## Scope and limitations

This is still a read-only prototype. It lists recent INBOX headers only. It does not open messages, modify flags, move/delete messages, or send/reply. Messages remain on their original IMAP servers. No passwords are stored by this plugin; it decrypts existing Roundcube/Gecka credential material only when connecting.

The Gecka adapter reads the enabled root account rows from Gecka's `ident_switch` table and the associated Roundcube identities table. Gecka 5.0.4 does not expose a public account-provider API in the inspected source, so this is an isolated schema/session integration dependency. The plugin does not modify Gecka files or tables.

## Installation on the isolated test server

1. Back up the existing test plugin directory:

   ```bash
   cd /var/www/html/roundcube/plugins
   sudo cp -a unified_inbox unified_inbox.backup-before-0.1.5
   ```

2. Upload `unified_inbox_0.1.5.zip` to the test server, then extract it into the plugins directory. The ZIP contains the `unified_inbox/` directory at its root.

   ```bash
   cd /var/www/html/roundcube/plugins
   sudo unzip -o /path/to/unified_inbox_0.1.5.zip
   sudo chown -R www-data:www-data unified_inbox
   ```

3. Check PHP syntax:

   ```bash
   sudo find unified_inbox -name '*.php' -print0 | xargs -0 -n1 php -l
   ```

4. No change to `config.inc.php` is needed if `unified_inbox` is already enabled. Do not alter Gecka or Roundcube core files.

5. Sign out and back in (or reload Roundcube), then test:
   - Gecka selector → Unified Inbox should display the inbox page.
   - Click a message subject; it should open with sender, recipients, date, account and body.
   - Use Back to Unified Inbox to return to the merged list.
   - Verify the original Gecka account selection is unchanged.
   - Settings should show a **Unified Inbox** section.
   - Select/deselect accounts, save, reopen Settings, and verify the selection persists.
   - Open Unified Inbox and verify messages from the selected accounts are merged newest-first.

## Rollback

On the test server only:

```bash
cd /var/www/html/roundcube/plugins
sudo rm -rf unified_inbox
sudo mv unified_inbox.backup-before-0.1.5 unified_inbox
```

If you have not made the backup, do not run the rollback commands; restore your prior 0.1.2 ZIP instead.

## Compatibility

Designed against Roundcube 1.7.7 / PHP 8.4 and Gecka ident_switch 5.0.x, using the inspected account table/session conventions. Test on the isolated mirror first. Do not deploy to production until the page, preferences persistence, and per-account IMAP reads have been verified.
