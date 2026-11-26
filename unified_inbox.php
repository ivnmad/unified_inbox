<?php

/**
 * Unified Inbox - read-only multi-account inbox prototype.
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
    public $task = 'mail';

    public function init()
    {
        $this->add_texts('localization/', true);
        $this->include_stylesheet('skins/elastic/unified_inbox.css');

        $this->register_action('plugin.unified_inbox', [$this, 'action']);
        $this->register_action('plugin.unified_inbox_save', [$this, 'save_preferences']);
        $this->register_handler('plugin.unified_inbox_body', [$this, 'ui']);

        $this->add_hook('render_page', [$this, 'add_taskbar_button']);
    }

    public function add_taskbar_button($args)
    {
        if (($args['template'] ?? '') === 'mail') {
            $this->add_button([
                'command' => 'plugin.unified_inbox',
                'class'   => 'button-unified-inbox',
                'label'   => 'Unified Inbox',
                'title'   => 'Unified Inbox',
            ], 'taskbar');
        }

        return $args;
    }

    public function action()
    {
        $rc = rcmail::get_instance();

        $this->include_script('skins/elastic/unified_inbox.js');
        $rc->output->set_pagetitle('Unified Inbox');
        $rc->output->send('plugin');
    }

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

        $rc->output->redirect(['_task' => 'mail', '_action' => 'plugin.unified_inbox']);
    }

    public function ui($attrib)
    {
        $rc = rcmail::get_instance();
        $provider = new UnifiedInboxAccountProvider($rc);
        $accounts = $provider->get_accounts();
        $prefs = $rc->user->get_prefs();
        $saved = $prefs['unified_inbox_accounts'] ?? null;

        if (!is_array($saved)) {
            $saved = array_column($accounts, 'key');
        }

        $selected = array_values(array_intersect($saved, array_column($accounts, 'key')));
        $page = max(1, (int) rcube_utils::get_input_value('_page', rcube_utils::INPUT_GET));
        $per_page = 50;

        $messages = [];
        $errors = [];

        foreach ($accounts as $account) {
            if (!in_array($account['key'], $selected, true)) {
                continue;
            }

            try {
                $reader = new UnifiedInboxImapReader($rc, $account);
                $messages = array_merge($messages, $reader->latest_headers(($page * $per_page) + $per_page));
            } catch (Throwable $e) {
                $errors[] = $account['label'] . ': ' . $e->getMessage();
            }
        }

        usort($messages, static function ($a, $b) {
            return ($b['timestamp'] <=> $a['timestamp']);
        });

        $total_loaded = count($messages);
        $messages = array_slice($messages, ($page - 1) * $per_page, $per_page);

        $html = '<div id="unified-inbox">';
        $html .= '<h1>Unified Inbox</h1>';
        $html .= '<p class="hint">Read-only preview. Messages remain on their original mail servers.</p>';

        if ($errors) {
            $html .= '<div class="ui-alert">' . rcube::Q(implode(' | ', $errors)) . '</div>';
        }

        $html .= '<form method="post" action="./?_task=mail&amp;_action=plugin.unified_inbox_save">';
        $html .= '<input type="hidden" name="_token" value="' . rcube::Q($rc->get_request_token()) . '">';
        $html .= '<fieldset><legend>Accounts included</legend><div class="unified-account-list">';

        foreach ($accounts as $account) {
            $checked = in_array($account['key'], $selected, true) ? ' checked="checked"' : '';
            $html .= '<label><input type="checkbox" name="_accounts[]" value="' . rcube::Q($account['key']) . '"' . $checked . '> '
                . rcube::Q($account['label']) . '</label>';
        }

        $html .= '</div><p><button type="button" id="unified-select-all">Select all</button> ';
        $html .= '<button type="button" id="unified-select-none">Deselect all</button> ';
        $html .= '<button type="submit">Save account selection</button> ';
        $html .= '<a href="./?_task=mail&amp;_action=plugin.unified_inbox">Refresh</a></p></fieldset></form>';

        $html .= '<table class="records-table unified-messages"><thead><tr>';
        $html .= '<th>Date</th><th>From</th><th>Subject</th><th>Account</th><th>Status</th>';
        $html .= '</tr></thead><tbody>';

        if (!$messages) {
            $html .= '<tr><td colspan="5">No messages found in the selected accounts, or no accounts are selected.</td></tr>';
        } else {
            foreach ($messages as $message) {
                $date = $message['timestamp'] ? date('Y-m-d H:i', $message['timestamp']) : '';
                $html .= '<tr>';
                $html .= '<td>' . rcube::Q($date) . '</td>';
                $html .= '<td>' . rcube::Q($message['from']) . '</td>';
                $html .= '<td>' . rcube::Q($message['subject']) . '</td>';
                $html .= '<td>' . rcube::Q($message['account']) . '</td>';
                $html .= '<td>' . rcube::Q(implode(', ', $message['flags'])) . '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';
        $html .= '<p class="hint">Showing up to ' . rcube::Q((string) $per_page) . ' messages per page from a bounded recent window per account. '
            . 'This prototype does not open, modify, move, delete or send messages.</p>';

        if ($page > 1) {
            $html .= '<a href="./?_task=mail&amp;_action=plugin.unified_inbox&amp;_page=' . ($page - 1) . '">Previous page</a> ';
        }
        if ($total_loaded > $page * $per_page) {
            $html .= '<a href="./?_task=mail&amp;_action=plugin.unified_inbox&amp;_page=' . ($page + 1) . '">Next page</a>';
        }

        $html .= '</div>';

        return $html;
    }
}
