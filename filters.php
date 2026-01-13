<?php

/**
 * Filters
 *
 * Plugin that adds a new tab to the settings section to create client-side e-mail filtering.
 *
 * @version 2.2.0
 * @author Roberto Zarrelli <zarrelli@unimol.it>
 * @developer Artur Petrov <artur@phpchain.ru>
 */

class filters extends rcube_plugin
{
    public $task = 'settings|login'; // Sadece settings ve login
    
    private $autoAddSpamFilterRule;
    private $spam_subject;
    private $spam_headers = array();
    private $caseInsensitiveSearch;
    private $decodeBase64Msg;
    private $rc;


    function init()
    {
        /* Filters parameters initialization. See readme.txt */
        $this->load_config();
        /* ***************************************************** */

        $rcmail = rcmail::get_instance();
        $this->rc = &$rcmail;

        $this->autoAddSpamFilterRule = $this->rc->config->get('autoAddSpamFilterRule', true);
        $this->spam_subject = $this->rc->config->get('spam_subject', '[SPAM]');
        $this->caseInsensitiveSearch = $this->rc->config->get('caseInsensitiveSearch', true);
        $this->decodeBase64Msg = $this->rc->config->get('decodeBase64Msg', false);
        $this->spam_headers = (array)$this->rc->config->get('spam_headers', array('X-Spam-Flag'));

        // SADECE settings task'ýnda çalýþ
        if ($this->rc->task == 'settings') {
            $this->add_hook('settings_actions', array($this, 'settings_actions'));
            $this->register_action('plugin.filters', array($this, 'filters_init'));
            $this->register_action('plugin.filters-save', array($this, 'filters_save'));
            $this->register_action('plugin.filters-delete', array($this, 'filters_delete'));
            $this->add_texts('localization/', array('filters', 'nosearchstring'));
            $this->rc->output->add_label('filters');
            $this->include_script('filters.js');
        } 
        // Login task'ýnda sadece spam kuralý ekleme
        else if ($this->rc->task == 'login' && $this->autoAddSpamFilterRule) {
            $this->add_hook('login_after', array($this, 'filters_addMoveSpamRule'));
        }
        
        // Mail task'ýnda ÇALIÞMA - problem buradan geliyor
    }

    function settings_actions($args)
    {
        $args['actions'][] = array(
            'action' => 'plugin.filters',
            'class' => 'filters',
            'label' => 'filters',
            'domain' => 'filters',
        );

        return $args;
    }

    function filters_init()
    {
        $this->add_texts('localization/');
        $this->register_handler('plugin.body', array($this, 'filters_form'));
        $this->rc->output->set_pagetitle($this->gettext('filters'));
        $this->rc->output->send('plugin');
    }

    function filters_save()
    {
        $user = $this->rc->user;

        $this->add_texts('localization/');
        $this->register_handler('plugin.body', array($this, 'filters_form'));
        $this->rc->output->set_pagetitle($this->gettext('filters'));

        $searchstring = trim(rcube_utils::get_input_value('_searchstring', rcube_utils::INPUT_POST, true));
        $srcfolder = trim(rcube_utils::get_input_value('_srcfolders', rcube_utils::INPUT_POST, true));
        $destfolder = trim(rcube_utils::get_input_value('_folders', rcube_utils::INPUT_POST, true));
        $whatfilter = trim(rcube_utils::get_input_value('_whatfilter', rcube_utils::INPUT_POST, true));
        $messages = trim(rcube_utils::get_input_value('_messages', rcube_utils::INPUT_POST, true));
        $filterpriority = trim(rcube_utils::get_input_value('_checkbox', rcube_utils::INPUT_POST, true));
        $markread = trim(rcube_utils::get_input_value('_markread', rcube_utils::INPUT_POST, true));

        if ($searchstring == "") {
            $this->rc->output->command('display_message', $this->gettext('nosearchstring'), 'error');
        } else {
            $new_arr['whatfilter'] = $whatfilter;
            $new_arr['searchstring'] = htmlspecialchars($searchstring, ENT_QUOTES, 'UTF-8');
            $new_arr['srcfolder'] = $srcfolder;
            $new_arr['destfolder'] = $destfolder;
            $new_arr['messages'] = $messages;
            $new_arr['filterpriority'] = $filterpriority ? '1' : '0';
            $new_arr['markread'] = $markread;
            
            $arr_prefs = $user->get_prefs();
            if (!isset($arr_prefs['filters']) || !is_array($arr_prefs['filters'])) {
                $arr_prefs['filters'] = array();
            }
            
            $arr_prefs['filters'][] = $new_arr;
            
            if ($user->save_prefs($arr_prefs)) {
                $this->rc->output->command('display_message', $this->gettext('successfullysaved'), 'confirmation');
                // Kaydedilen filtreyi hemen göster
                $_SESSION['filters_refresh'] = true;
            } else {
                $this->rc->output->command('display_message', $this->gettext('unsuccessfullysaved'), 'error');
            }
        }
        $this->rc->overwrite_action('plugin.filters');
        $this->rc->output->send('plugin');
    }

    function filters_delete()
    {
        $user = $this->rc->user;

        $this->add_texts('localization/');
        $this->register_handler('plugin.body', array($this, 'filters_form'));
        $this->rc->output->set_pagetitle($this->gettext('filters'));

        if (isset($_GET['filterid'])) {
            $filter_id = (int)$_GET['filterid'];
            $arr_prefs = $user->get_prefs();
            
            if (isset($arr_prefs['filters']) && isset($arr_prefs['filters'][$filter_id])) {
                // Dizi elemanýný kaldýr
                unset($arr_prefs['filters'][$filter_id]);
                // Diziyi yeniden indeksle
                $arr_prefs['filters'] = array_values($arr_prefs['filters']);
                
                if ($user->save_prefs($arr_prefs)) {
                    $this->rc->output->command('display_message', $this->gettext('successfullydeleted'), 'confirmation');
                    $_SESSION['filters_refresh'] = true;
                } else {
                    $this->rc->output->command('display_message', $this->gettext('unsuccessfullydeleted'), 'error');
                }
            }
        }

        $this->rc->overwrite_action('plugin.filters');
        $this->rc->output->send('plugin');
    }

    function filters_form()
    {
        if (method_exists($this->rc, 'imap_connect')) {
            $this->rc->imap_connect();
        } else {
            $this->rc->storage_connect();
        }

        $table = new html_table(array('cols' => 2));
        $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('whatfilter') . ":", 'html'));

        $select = new html_select(array('name' => '_whatfilter', 'id' => 'whatfilter'));
        $select->add($this->gettext('from'), 'from');
        $select->add($this->gettext('to'), 'to');
        $select->add($this->gettext('cc'), 'cc');
        $select->add($this->gettext('subject'), 'subject');
        foreach ($this->spam_headers as $spam_header) {
            $select->add($spam_header, $spam_header);
        }
        $table->add('', $select->show($this->gettext('from')));

        $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('searchstring') . ":"), 'html');
        $inputfield = new html_inputfield(array('name' => '_searchstring', 'id' => 'searchstring'));
        $table->add('', $inputfield->show(""));

        // new option: source folder
        $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('movefrom') . ":"));
        if (function_exists('rcmail::get_instance()->folder_selector')) {
            $select = rcmail::get_instance()->folder_selector(array('name' => '_srcfolders', 'id' => 'srcfolders'));
        } else {
            $select = $this->rc->folder_selector(array('name' => '_srcfolders', 'id' => 'srcfolders'));
        }
        $table->add('title', $select->show());

        $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('moveto') . ":"));
        if (function_exists('rcmail::get_instance()->folder_selector')) {
            $select = rcmail::get_instance()->folder_selector(array('name' => '_folders', 'id' => 'folders'));
        } else {
            $select = $this->rc->folder_selector(array('name' => '_folders', 'id' => 'folders'));
        }
        $table->add('title', $select->show());

        // new option: all, read and unread messages
        $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('messagecount') . ":"), 'html');
        $select = new html_select(array('name' => '_messages', 'id' => 'messages'));
        $select->add($this->gettext('all'), 'all');
        $select->add($this->gettext('unread'), 'unread');
        $select->add($this->gettext('isread'), 'isread');
        $table->add('', $select->show($this->gettext('all')));

        // new option: markread or markunread messages
        $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('markmessages') . ":"), 'html');
        $select = new html_select(array('name' => '_markread', 'id' => 'markread'));
        $select->add($this->gettext('none'), 'none');
        $select->add($this->gettext('markunread'), 'markunread');
        $select->add($this->gettext('markread'), 'markread');
        $table->add('', $select->show($this->gettext('none')));

        // new option: filter priority, "on" as enable and "" as disable
        $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('filterpriority') . ":"), 'html');
        $checkbox = new html_checkbox(array('name' => '_checkbox', 'id' => 'checkbox', 'value' => '1'));
        $table->add('', $checkbox->show("0"));

        // load saved filters
        $user = $this->rc->user;
        $arr_prefs = $user->get_prefs();
        $i = 1;
        $table2 = new html_table(array('cols' => 2));

        if (empty($arr_prefs['filters']) || !is_array($arr_prefs['filters'])) {
            $table2->add(array('colspan' => 2), rcube_utils::rep_specialchars_output($this->gettext('msg_no_stored_filters'), 'html'));
        } else {
            foreach ($arr_prefs['filters'] as $key => $saved_filter) {
                if (empty($saved_filter['markread'])) {
                    $saved_filter['markread'] = 'none';
                }
                if (empty($saved_filter['srcfolder'])) {
                    $saved_filter['srcfolder'] = 'INBOX';
                }
                if (empty($saved_filter['filterpriority'])) {
                    $saved_filter['filterpriority'] = '0';
                }
                
                $srcfolder_id = $saved_filter['srcfolder'];
                $folder_id = $saved_filter['destfolder'];
                if (function_exists('rcmail::get_instance()->localize_folderpath')) {
                    $srcfolder_name = rcmail::get_instance()->localize_folderpath($srcfolder_id);
                    $folder_name = rcmail::get_instance()->localize_folderpath($folder_id);
                } else {
                    $srcfolder_name = $this->rc->localize_folderpath($srcfolder_id);
                    $folder_name = $this->rc->localize_folderpath($folder_id);
                }

                $messages = $saved_filter['messages'];

                $msg = $i . " - " . $this->gettext('msg_if_field') . " <b>" . $this->gettext($saved_filter['whatfilter']) . "</b> " . $this->gettext('msg_contains') .
                    " <b>" . htmlspecialchars_decode(stripslashes($saved_filter['searchstring'])) . "</b> " . $this->gettext('msg_move_msg_in') . " <b>" . $folder_name .
                    "</b> " . $this->gettext('msg_move_msg_from') . " <b>" . $srcfolder_name . "</b> " .
                    "(" . $this->gettext('messagecount') . ": " . $this->gettext($saved_filter['messages']) .
                    ", " . $this->gettext('mark') . ": " . $this->gettext($saved_filter['markread']) . ")";
                    
                if (!empty($saved_filter['filterpriority']) && $saved_filter['filterpriority'] == '1') {
                    $msg = "<span style='color:green; font-weight:bold;'>" . $msg . "</span>";
                }
                
                $table2->add('title', $msg);
                $dlink = "<a href='./?_task=settings&_action=plugin.filters-delete&filterid=" . $key . "' onclick='return confirm(\"" . $this->gettext('confirmdelete') . "\");'>" . $this->gettext('delete') . "</a>";
                $table2->add('title', $dlink);
                $i++;
            }
        }

        $out = html::div(
            array('class' => 'box'),
            html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('filters')) .
                html::div(
                    array('class' => 'boxcontent'),
                    $table->show() .
                        html::p(
                            null,
                            $this->rc->output->button(array(
                                'command' => 'plugin.filters-save',
                                'type' => 'input',
                                'class' => 'button mainaction',
                                'label' => 'save'
                            ))
                        )
                )
        );
        
        $out .= html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('storedfilters')) .
            html::div(
                array('class' => 'uibox listbox scroller', 'style' => 'margin-top:20px;'),
                html::div(array('class' => 'boxcontent'), $table2->show())
            );

        $this->rc->output->add_gui_object('filtersform', 'filters-form');

        return $this->rc->output->form_tag(
            array(
                'id' => 'filters-form',
                'name' => 'filters-form',
                'method' => 'post',
                'class' => 'propform',
                'action' => './?_task=settings&_action=plugin.filters-save',
            ),
            $out
        );
    }

    function filters_addMoveSpamRule()
    {
        $user = $this->rc->user;
        if (!$user || !$user->ID) {
            return;
        }
        
        $searchstring = $this->spam_subject;
        $destfolder = $this->rc->config->get('junk_mbox', null);
        $whatfilter = "subject";
        $messages = "all";

        // load filters
        $arr_prefs = $this->rc->config->get('filters', array());

        // check if the rule is already enabled
        $found = false;
        foreach ($arr_prefs as $key => $saved_filter) {
            if (isset($saved_filter['searchstring']) && $saved_filter['searchstring'] == $searchstring && 
                isset($saved_filter['whatfilter']) && $saved_filter['whatfilter'] == $whatfilter) {
                $found = true;
            }
            if (isset($saved_filter['searchstring']) && $saved_filter['searchstring'] == 'Yes' && 
                isset($saved_filter['whatfilter']) && $saved_filter['whatfilter'] == 'X-Spam-Flag') {
                $found = true;
            }
        }

        if (!$found && $destfolder !== null && $destfolder !== "") {
            $new_arr['whatfilter'] = $whatfilter;
            $new_arr['searchstring'] = $searchstring;
            $new_arr['srcfolder'] = 'INBOX';
            $new_arr['destfolder'] = $destfolder;
            $new_arr['messages'] = $messages;
            $new_arr['filterpriority'] = '0';
            $new_arr['markread'] = 'none';
            
            $arr_prefs = $user->get_prefs();
            if (!isset($arr_prefs['filters']) || !is_array($arr_prefs['filters'])) {
                $arr_prefs['filters'] = array();
            }
            
            $arr_prefs['filters'][] = $new_arr;
            $user->save_prefs($arr_prefs);
            
            $new_arr['whatfilter'] = 'X-Spam-Flag';
            $new_arr['searchstring'] = 'Yes';
            $new_arr['srcfolder'] = 'INBOX';
            $new_arr['destfolder'] = $destfolder;
            $new_arr['messages'] = $messages;
            $new_arr['filterpriority'] = '0';
            $new_arr['markread'] = 'none';
            
            $arr_prefs = $user->get_prefs();
            $arr_prefs['filters'][] = $new_arr;
            $user->save_prefs($arr_prefs);
        }
    }
}