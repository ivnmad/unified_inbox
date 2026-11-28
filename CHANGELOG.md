# Changelog

## [0.1.8] - 2026-11-28

### Fixed
- Select the primary mailbox's active Roundcube session credentials when Gecka has switched back to the primary account (`iid_iswitch = -1` or the active session username matches the login username).
- Use the preserved `_iswitch` primary credential snapshot only while a secondary Gecka account is active, avoiding stale-snapshot authentication failures after switching back.

All notable changes to Unified Inbox are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.7] - 2026-11-28

### Fixed
- Discover the primary mailbox when Unified Inbox is opened directly from the primary account by using Roundcube's `get_user_password()` accessor when no Gecka-preserved primary snapshot exists.
- Avoid decrypting the primary password twice when Roundcube has already returned it in plaintext.
- Retain the Gecka `_iswitch` encrypted primary-session path when the user is currently switched to a secondary account.

### Documentation
- Separate project overview and usage information in `README.md` from version-by-version release notes in `CHANGELOG.md`.

## [0.1.6] - 2026-11-27

### Fixed
- Display the primary account's email address as its account label rather than the user's display name.
- Removed a fragile session-username comparison from primary-account discovery.

## [0.1.5] - 2026-11-27

### Fixed
- Added primary login mailbox discovery when Gecka has not yet created a saved `_iswitch` primary-session snapshot.
- Preserved the saved-primary-session path when Gecka has switched to a secondary account.

## [0.1.4] - 2026-11-27

### Added
- Added a message-reading page opened from the subject link in Unified Inbox.
- Opened messages using the originating account key and account-local IMAP UID in the account's INBOX.
- Added sender, recipients, date, account label, and a sanitized message body to the read-only message view.

### Security
- Sanitized HTML message content with Roundcube's HTML sanitizer and blocked remote resources by default.

## [0.1.3] - 2026-11-27

### Changed
- Replaced raw numeric IMAP flag output with the four independent statuses: **Unread**, **Read**, **Unread Flagged**, and **Read Flagged**.
- Interpreted `\Seen` and `\Flagged` independently.

## [0.1.2] - 2026-11-27

### Fixed
- Registered the `plugin.body` handler expected by Roundcube's built-in plugin template, fixing the blank page.

### Added
- Added a native **Settings → Unified Inbox** entry.
- Added per-user account selection preferences.
- Preserved Gecka's original account-switch handler when a real account is selected in its selector.
- Isolated per-account failures so an unavailable account does not prevent the remaining accounts from loading.
