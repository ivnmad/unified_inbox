<?php

/**
 * Unified Inbox - multi-account inbox prototype with read-only message viewing.
 *
 * Independent Roundcube plugin. Reads Gecka ident_switch account records
 * without changing Gecka's tables or Roundcube core.
 *
 * @license GPL-3.0-or-later
 */
require_once __DIR__ . '/lib/UnifiedInboxAccountProvider.php';
require_once __DIR__ . '/lib/UnifiedInboxImapReader.php';

class unified_inbox extends rcube_plugin
{
    public $task = 'mail|settings';

    public function init()
    {
        $this->add_texts('localization/', true);
        $this->include_stylesheet('skins/elastic/unified_inbox.css');

        $this->register_action('plugin.unified_inbox', [$this, 'action']);
        $this->register_action('plugin.unified_inbox_message', [$this, 'message_action']);
        $this->register_action('plugin.unified_inbox_prefs', [$this, 'preferences_action']);
        $this->register_action('plugin.unified_inbox_save', [$this, 'save_preferences']);

        $this->add_hook('render_page', [$this, 'integrate_gecka_switcher']);

        if (rcmail::get_instance()->task === 'settings') {
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
        }
    }

    /** Add Unified Inbox to Gecka ident_switch 5.x selector on mail pages. */
    public function integrate_gecka_switcher($args)
    {
        if (($args['template'] ?? '') === 'mail') {
            $this->include_script('skins/elastic/unified_inbox.js');
        }

        return $args;
    }

    /** Add a native Roundcube Settings navigation entry. */
    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.unified_inbox_prefs',
            'class'  => 'unified-inbox',
            'label'  => 'settings',
            'title'  => 'settings',
            'domain' => 'unified_inbox',
        ];

        return $args;
    }

    /** Display the merged inbox using Roundcube's built-in plugin template. */
    public function action()
    {
        $this->send_plugin_page('Unified Inbox');
    }

    /** Display account-selection settings using the same built-in template. */
    public function preferences_action()
    {
        $this->send_plugin_page($this->gettext('settings'));
    }

    private function send_plugin_page(string $title): void
    {
        $rc = rcmail::get_instance();
        // Roundcube's built-in skins/*/templates/plugin.html expects plugin.body.
        $this->register_handler('plugin.body', [$this, 'page_body']);
        $this->include_script('skins/elastic/unified_inbox.js');
        $rc->output->set_pagetitle($title);
        $rc->output->send('plugin');
    }

    /** Open a message from its originating account, folder and UID. */
    public function message_action()
    {
        $this->send_plugin_page('Unified Inbox - Message');
    }

    /** Save selected account keys to this Roundcube user's preferences. */
    public function save_preferences()
    {
        $rc = rcmail::get_instance();
        $accounts = (new UnifiedInboxAccountProvider($rc))->get_accounts();
        $valid = [];

        foreach ($accounts as $account) {
            $valid[$account['key']] = true;
        }

        $posted = rcube_utils::get_input_value('_accounts', rcube_utils::INPUT_POST);
        $selected = [];
        if (is_array($posted)) {
            foreach ($posted as $key) {
                $key = (string) $key;
                if (isset($valid[$key])) {
                    $selected[] = $key;
                }
            }
        }

        $prefs = $rc->user->get_prefs();
        $prefs['unified_inbox_accounts'] = array_values(array_unique($selected));
        $rc->user->save_prefs($prefs);

        $return_to = rcube_utils::get_input_value('_return', rcube_utils::INPUT_POST);
        if ($return_to === 'settings') {
            $rc->output->redirect(['_task' => 'settings', '_action' => 'plugin.unified_inbox_prefs']);
        }

        $rc->output->redirect(['_task' => 'mail', '_action' => 'plugin.unified_inbox']);
    }

    /** Roundcube built-in plugin template content handler. */
    public function page_body($attrib = [])
    {
        $rc = rcmail::get_instance();
        if ($rc->action === 'plugin.unified_inbox_message') {
            return $this->render_message();
        }
        if ($rc->task === 'settings' || $rc->action === 'plugin.unified_inbox_prefs') {
            return $this->render_preferences();
        }

        return $this->render_inbox();
    }

    private function selected_accounts(array $accounts): array
    {
        $prefs = rcmail::get_instance()->user->get_prefs();
        $saved = $prefs['unified_inbox_accounts'] ?? null;
        if (!is_array($saved)) {
            $saved = array_column($accounts, 'key');
        }

        return array_values(array_intersect($saved, array_column($accounts, 'key')));
    }

    private function render_preferences(): string
    {
        $rc = rcmail::get_instance();
        $accounts = (new UnifiedInboxAccountProvider($rc))->get_accounts();
        $selected = $this->selected_accounts($accounts);

        $html = '<div id="unified-inbox" class="boxcontent">';
        $html .= '<h2>' . rcube::Q($this->gettext('settings')) . '</h2>';
        $html .= '<p class="hint">' . rcube::Q($this->gettext('settings_intro')) . '</p>';

        if (!$accounts) {
            $html .= '<p class="ui-alert">' . rcube::Q($this->gettext('no_accounts')) . '</p>';
        } else {
            $html .= '<form method="post" action="./?_task=settings&amp;_action=plugin.unified_inbox_save">';
            $html .= '<input type="hidden" name="_token" value="' . rcube::Q($rc->get_request_token()) . '">';
            $html .= '<input type="hidden" name="_return" value="settings">';
            $html .= '<fieldset><legend>' . rcube::Q($this->gettext('accounts_included')) . '</legend>';
            $html .= '<div class="unified-account-list">';
            foreach ($accounts as $account) {
                $checked = in_array($account['key'], $selected, true) ? ' checked="checked"' : '';
                $html .= '<label><input type="checkbox" name="_accounts[]" value="' . rcube::Q($account['key']) . '"' . $checked . '> '
                    . rcube::Q($account['label']) . '</label>';
            }
            $html .= '</div><p><button type="button" id="unified-select-all">' . rcube::Q($this->gettext('select_all')) . '</button> ';
            $html .= '<button type="button" id="unified-select-none">' . rcube::Q($this->gettext('deselect_all')) . '</button> ';
            $html .= '<button type="submit">' . rcube::Q($this->gettext('save_selection')) . '</button></p></fieldset></form>';
        }

        $html .= '<p><a href="./?_task=mail&amp;_action=plugin.unified_inbox">' . rcube::Q($this->gettext('open_unified_inbox')) . '</a></p>';
        $html .= '</div>';
        return $html;
    }

    private function render_message(): string
    {
        $rc = rcmail::get_instance();
        $account_key = (string) rcube_utils::get_input_value('_account', rcube_utils::INPUT_GET);
        $uid = (int) rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GET);
        $accounts = (new UnifiedInboxAccountProvider($rc))->get_accounts();
        $selected = $this->selected_accounts($accounts);
        $account = null;
        foreach ($accounts as $candidate) {
            if ($candidate['key'] === $account_key && in_array($candidate['key'], $selected, true)) {
                $account = $candidate;
                break;
            }
        }

        $html = '<div id="unified-inbox" class="boxcontent unified-message-view">';
        $html .= '<p><a href="./?_task=mail&amp;_action=plugin.unified_inbox">&larr; ' . rcube::Q($this->gettext('back_to_unified')) . '</a></p>';
        if (!$account || $uid < 1) {
            $html .= '<div class="ui-alert">' . rcube::Q($this->gettext('message_unavailable')) . '</div></div>';
            return $html;
        }

        try {
            $reader = new UnifiedInboxImapReader($rc, $account);
            $message = $reader->fetch_message($uid, 'INBOX');
            $html .= '<h2>' . rcube::Q($message['subject'] ?: '(no subject)') . '</h2>';
            $html .= '<dl class="unified-message-meta">';
            $html .= '<dt>' . rcube::Q($this->gettext('from')) . '</dt><dd>' . rcube::Q($message['from']) . '</dd>';
            if ($message['to'] !== '') $html .= '<dt>To</dt><dd>' . rcube::Q($message['to']) . '</dd>';
            if ($message['cc'] !== '') $html .= '<dt>Cc</dt><dd>' . rcube::Q($message['cc']) . '</dd>';
            $html .= '<dt>' . rcube::Q($this->gettext('date')) . '</dt><dd>' . rcube::Q($message['date']) . '</dd>';
            $html .= '<dt>' . rcube::Q($this->gettext('account')) . '</dt><dd>' . rcube::Q($account['label']) . '</dd>';
            $html .= '</dl><hr><div class="unified-message-body">' . $message['body_html'] . '</div>';
        } catch (Throwable $e) {
            rcube::raise_error(['code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__,
                'message' => 'Unified Inbox message open failed: ' . $e->getMessage()], true, false);
            $html .= '<div class="ui-alert">' . rcube::Q($this->gettext('message_unavailable')) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function render_inbox(): string
    {
        $rc = rcmail::get_instance();
        $accounts = (new UnifiedInboxAccountProvider($rc))->get_accounts();
        $selected = $this->selected_accounts($accounts);
        $page = max(1, (int) rcube_utils::get_input_value('_page', rcube_utils::INPUT_GET));
        $selected_account_key = (string) rcube_utils::get_input_value('_account', rcube_utils::INPUT_GET);
        $selected_uid = (int) rcube_utils::get_input_value('_uid', rcube_utils::INPUT_GET);
        $per_page = 50;
        $messages = [];
        $errors = [];
        $account_by_key = [];

        foreach ($accounts as $account) {
            $account_by_key[$account['key']] = $account;
            if (!in_array($account['key'], $selected, true)) {
                continue;
            }
            try {
                $reader = new UnifiedInboxImapReader($rc, $account);
                $messages = array_merge($messages, $reader->latest_headers(($page * $per_page) + $per_page));
            } catch (Throwable $e) {
                $errors[] = $account['label'] . ': ' . $this->gettext('account_unavailable');
                rcube::raise_error(['code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__,
                    'message' => 'Unified Inbox account read failed: ' . $e->getMessage()], true, false);
            }
        }

        usort($messages, static function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });
        $total_loaded = count($messages);
        $messages = array_slice($messages, ($page - 1) * $per_page, $per_page);

        // Only allow a selected message that belongs to a currently included account.
        $selected_message = null;
        if ($selected_uid > 0 && isset($account_by_key[$selected_account_key])
            && in_array($selected_account_key, $selected, true)) {
            foreach ($messages as $candidate) {
                if ($candidate['account_key'] === $selected_account_key && (int) $candidate['uid'] === $selected_uid) {
                    $selected_message = $candidate;
                    break;
                }
            }
        }

        $html = '<div id="unified-inbox" class="boxcontent unified-inbox-app">';
        $html .= '<div class="unified-inbox-heading"><h2>Unified Inbox</h2>';
        $html .= '<p class="hint">' . rcube::Q($this->gettext('readonly_intro')) . ' <a href="./?_task=settings&amp;_action=plugin.unified_inbox_prefs">'
            . rcube::Q($this->gettext('choose_accounts')) . '</a></p></div>';

        if ($errors) {
            $html .= '<div class="ui-alert">' . rcube::Q(implode(' | ', $errors)) . '</div>';
        }

        $html .= '<div class="unified-inbox-layout">';
        $html .= '<section class="unified-inbox-list-pane" aria-label="Unified message list">';
        $html .= '<table class="records-table unified-messages"><thead><tr>';
        $html .= '<th>' . rcube::Q($this->gettext('date')) . '</th><th>' . rcube::Q($this->gettext('from')) . '</th><th>'
            . rcube::Q($this->gettext('subject')) . '</th><th>' . rcube::Q($this->gettext('account')) . '</th><th>'
            . rcube::Q($this->gettext('status')) . '</th></tr></thead><tbody>';

        if (!$messages) {
            $html .= '<tr><td colspan="5">' . rcube::Q($this->gettext('no_messages')) . '</td></tr>';
        } else {
            foreach ($messages as $message) {
                $date = $message['timestamp'] ? date('Y-m-d H:i', $message['timestamp']) : '';
                $is_selected = $selected_message
                    && $selected_message['account_key'] === $message['account_key']
                    && (int) $selected_message['uid'] === (int) $message['uid'];
                $open_url = './?_task=mail&amp;_action=plugin.unified_inbox&amp;_page=' . $page
                    . '&amp;_account=' . rawurlencode($message['account_key']) . '&amp;_uid=' . (int) $message['uid'];
                $html .= '<tr' . ($is_selected ? ' class="selected"' : '') . '><td class="unified-col-date"><span>' . rcube::Q($date) . '</span></td><td class="unified-col-from"><span>' . rcube::Q($message['from']) . '</span></td><td class="unified-col-subject">'
                    . '<a href="' . $open_url . '"' . ($is_selected ? ' aria-current="true"' : '') . '>' . rcube::Q($message['subject'] ?: '(no subject)') . '</a></td><td class="unified-col-account"><span>' . rcube::Q($message['account']) . '</span></td><td class="unified-col-status">'
                    . '<span class="unified-status' . (!empty($message['seen']) ? ' is-read' : ' is-unread') . (!empty($message['flagged']) ? ' is-flagged' : '') . '">'
                    . rcube::Q(!empty($message['seen']) ? (!empty($message['flagged']) ? 'Read Flagged' : 'Read') : (!empty($message['flagged']) ? 'Unread Flagged' : 'Unread'))
                    . '</span></td></tr>';
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div class="unified-inbox-pagination">';
        if ($page > 1) {
            $html .= '<a href="./?_task=mail&amp;_action=plugin.unified_inbox&amp;_page=' . ($page - 1) . '">' . rcube::Q($this->gettext('previous')) . '</a> ';
        }
        if ($total_loaded > $page * $per_page) {
            $html .= '<a href="./?_task=mail&amp;_action=plugin.unified_inbox&amp;_page=' . ($page + 1) . '">' . rcube::Q($this->gettext('next')) . '</a>';
        }
        $html .= '</div></section>';

        $html .= '<section class="unified-inbox-reader-pane" aria-label="Selected message">';
        if (!$selected_message) {
            $html .= '<div class="unified-reader-empty"><span class="hint">Select a message to read it here.</span></div>';
        } else {
            try {
                $account = $account_by_key[$selected_account_key];
                $reader = new UnifiedInboxImapReader($rc, $account);
                $message = $reader->fetch_message($selected_uid, 'INBOX');
                $html .= '<div class="unified-reader-toolbar"><a href="./?_task=mail&amp;_action=plugin.unified_inbox&amp;_page=' . $page . '">Clear selection</a></div>';
                $html .= '<article class="unified-reader-message">';
                $html .= '<h2>' . rcube::Q($message['subject'] ?: '(no subject)') . '</h2>';
                $html .= '<dl class="unified-message-meta">';
                $html .= '<dt>' . rcube::Q($this->gettext('from')) . '</dt><dd>' . rcube::Q($message['from']) . '</dd>';
                if ($message['to'] !== '') $html .= '<dt>To</dt><dd>' . rcube::Q($message['to']) . '</dd>';
                if ($message['cc'] !== '') $html .= '<dt>Cc</dt><dd>' . rcube::Q($message['cc']) . '</dd>';
                $html .= '<dt>' . rcube::Q($this->gettext('date')) . '</dt><dd>' . rcube::Q($message['date']) . '</dd>';
                $html .= '<dt>' . rcube::Q($this->gettext('account')) . '</dt><dd>' . rcube::Q($account['label']) . '</dd>';
                $html .= '</dl><hr><div class="unified-message-body">' . $message['body_html'] . '</div></article>';
            } catch (Throwable $e) {
                rcube::raise_error(['code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__,
                    'message' => 'Unified Inbox message open failed: ' . $e->getMessage()], true, false);
                $html .= '<div class="ui-alert">' . rcube::Q($this->gettext('message_unavailable')) . '</div>';
            }
        }
        $html .= '</section></div>';
        $html .= '<p class="hint">' . rcube::Q($this->gettext('bounded_window')) . '</p>';
        $html .= '</div>';

        return $html;
    }

}
