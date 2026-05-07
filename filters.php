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

class filters extends rcube_plugin{

  public $rc; /** better long term fix https://github.com/roundcubevnz/roundcube-plugin-filters/pull/3/changes#diff-32a845190b3b603fd750528baffd71709ab0627877ba794f3d4984d403d68686R17 */
	
  public $task = 'login|mail|settings';

  private $autoAddSpamFilterRule;
  private $spam_subject;
  private $spam_headers = array();
  private $caseInsensitiveSearch;
  private $decodeBase64Msg;
  private $searchstring = array();
  private $srcfolder = array();
  private $destfolder = array();
  private $msg_uids = array();
  private $open_mbox;


  function init(){

    /* Filters parameters initialization. See readme.txt */
    $this->load_config();
    /* ***************************************************** */

    $rcmail = rcmail::get_instance();
    $this->rc = &$rcmail;

    $this->autoAddSpamFilterRule = $this->rc->config->get('autoAddSpamFilterRule',TRUE);
    $this->spam_subject = $this->rc->config->get('spam_subject','[SPAM]');
    $this->caseInsensitiveSearch = $this->rc->config->get('caseInsensitiveSearch',TRUE);
    $this->decodeBase64Msg = $this->rc->config->get('decodeBase64Msg',FALSE);
    $this->spam_headers = $this->rc->config->get('spam_headers','X-Spam-Flag');

    if($this->rc->task == 'mail' && !isset($_GET["_q"])){
      $this->add_hook('storage_init', array($this, 'storage_init'));
      $this->add_hook('messages_list', array($this, 'filters_checkmsg'));
    }
    else if ($this->rc->task == 'settings'){
      $this->add_hook('settings_actions', array($this, 'settings_actions'));
      $this->register_action('plugin.filters', array($this, 'filters_init'));
      $this->register_action('plugin.filters-save', array($this, 'filters_save'));
      $this->register_action('plugin.filters-delete', array($this, 'filters_delete'));
      $this->add_texts('localization/', array('filters','nosearchstring'));
      $this->rc->output->add_label('filters');
      $this->include_script('filters.js');      
    }
    else if ($this->rc->task == 'login'){
      if ($this->autoAddSpamFilterRule)
	$this->add_hook('login_after', array($this, 'filters_addMoveSpamRule'));
    }

  }

 function storage_init($p)
  {
    if ($add_headers = (array)$this->rc->config->get('spam_headers', array()))
      $p['fetch_headers'] = trim($p['fetch_headers'].' ' . strtoupper(join(' ', $add_headers)));
    return $p;
  }

  function settings_actions($args){
    $args['actions'][] = array(
      'action' => 'plugin.filters',
      'class' => 'filters',
      'label' => 'filters',
      'domain' => 'filters',
    );

    return $args;
  }

  function filters_checkmsg($mlist){
    $user = $this->rc->user;
    if (method_exists($this->rc->imap,'get_mailbox_name')) {
      $imap = $this->rc->imap;
      $open_mbox = $imap->get_mailbox_name();
    }
    else{
      $imap = $this->rc->storage;
      $open_mbox = $imap->get_folder();
    }

    $this->open_mbox=$open_mbox;

	// does not consider the messages already in the trash
    //if ($open_mbox == $this->rc->config->get('trash_mbox'))
		//return;

	//load filters
	$arr_prefs = $this->rc->config->get('filters', array());

	foreach ($arr_prefs as $key => $saved_filter){
	  // if saved source folder not exists set to INBOX
	  if (empty($saved_filter['srcfolder'])) $saved_filter['srcfolder'] = 'INBOX';
	  // if saved destination folder exists and current folder is "check folder"
	  if (method_exists($imap,'mailbox_exists')){
		if ($imap->mailbox_exists($saved_filter['destfolder']) && $imap->mailbox_exists($saved_filter['srcfolder']) && $saved_filter['srcfolder']==$open_mbox && $saved_filter['destfolder']!=$saved_filter['srcfolder']){
		  $saved_filter['searchstring'] = html_entity_decode($saved_filter['searchstring']);
		  // destfolder#messages#filterpriority#markread
		  $this->searchstring[ $saved_filter['whatfilter'] ][ $saved_filter['searchstring'] ] = 
			$saved_filter['destfolder']."#".$saved_filter['messages']."#".$saved_filter['filterpriority']."#".$saved_filter['markread'];
		}
	  }
	  if (!method_exists($imap,'mailbox_exists')){
		if ($imap->folder_exists($saved_filter['destfolder']) && $imap->folder_exists($saved_filter['srcfolder']) && $saved_filter['srcfolder']==$open_mbox && $saved_filter['destfolder']!=$saved_filter['srcfolder']){
                  $saved_filter['searchstring'] = html_entity_decode($saved_filter['searchstring']);
                  // destfolder#messages#filterpriority#markread
                  $this->searchstring[ $saved_filter['whatfilter'] ][ $saved_filter['searchstring'] ] =
                        $saved_filter['destfolder']."#".$saved_filter['messages']."#".$saved_filter['filterpriority']."#".$saved_filter['markread'];
		}
	  }
	}
    // if there aren't filters return
    if(!count($arr_prefs) || !count($this->searchstring) || !isset($mlist['messages']) || !is_array($mlist['messages']))
      return;

    // scan the messages
    foreach($mlist["messages"] as $message)
      $this->filters_search($message);

    // move the filtered messages
    if (count($this->destfolder) > 0){
      foreach ($this->destfolder as $dfolder){
        $uids = array();
        foreach ($this->msg_uids[$dfolder] as $muids){
          $uids[] = $muids;
        }
        if (count($uids)){
		  $imap->move_message($uids, $dfolder, $open_mbox);
		  // refresh
      	  $unseen = $this->rc->storage->count($dfolder, 'UNSEEN');
      	  $this->api->output->command('set_unread_count',$dfolder, $unseen);
		  $this->api->output->command('list_mailbox');
		  $this->api->output->send();
        }
      }
    }

  }
  

  function filters_init(){
    $this->add_texts('localization/');
    $this->register_handler('plugin.body', array($this, 'filters_form'));
    $this->rc->output->set_pagetitle($this->gettext('filters'));
    $this->rc->output->send('plugin');
  }

  function filters_save(){
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

    if ($searchstring == "")
      $this->rc->output->command('display_message', $this->gettext('nosearchstring'), 'error');
    else{
      $new_arr['whatfilter'] = $whatfilter;
      $new_arr['searchstring'] = htmlspecialchars(addslashes($searchstring));
      $new_arr['srcfolder'] = $srcfolder;
      $new_arr['destfolder'] = $destfolder;
      $new_arr['messages'] = $messages;
      $new_arr['filterpriority'] = $filterpriority;
      $new_arr['markread'] = $markread;
      $arr_prefs = $user->get_prefs();
      $arr_prefs['filters'][] = $new_arr;
      if ($user->save_prefs($arr_prefs))
        $this->rc->output->command('display_message', $this->gettext('successfullysaved'), 'confirmation');
      else
        $this->rc->output->command('display_message', $this->gettext('unsuccessfullysaved'), 'error');
    }
    $this->rc->overwrite_action('plugin.filters');
    $this->rc->output->send('plugin');
  }

  function filters_delete(){
    $user = $this->rc->user;

    $this->add_texts('localization/');
    $this->register_handler('plugin.body', array($this, 'filters_form'));
    $this->rc->output->set_pagetitle($this->gettext('filters'));

    if (isset($_GET[filterid])){
      $filter_id = $_GET[filterid];
      $arr_prefs = $user->get_prefs();
      $arr_prefs['filters'][$filter_id] = '';
      $arr_prefs['filters'] = array_diff($arr_prefs['filters'], array(''));
      if ($user->save_prefs($arr_prefs))
        $this->rc->output->command('display_message', $this->gettext('successfullydeleted'), 'confirmation');
      else
        $this->rc->output->command('display_message', $this->gettext('unsuccessfullydeleted'), 'error');
    }

    if (function_exists('rcmail::get_instance()->overwrite_action'))
      rcmail::get_instance()->overwrite_action('plugin.filters');
    else $this->rc->overwrite_action('plugin.filters');

    $this->rc->output->send('plugin');
  }

  function filters_form(){

    if (method_exists($this->rc,'imap_connect')) $this->rc->imap_connect();
    else $this->rc->storage_connect();

    $table = new html_table(array('cols' => 2));
    $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('whatfilter').":", 'html'));

    $select = new html_select(array('name' => '_whatfilter', 'id' => 'whatfilter'));
    $select->add($this->gettext('from'), 'from');
    $select->add($this->gettext('to'), 'to');
    $select->add($this->gettext('cc'), 'cc');
    $select->add($this->gettext('subject'), 'subject');
    foreach ($this->spam_headers as $spam_header) $select->add($spam_header,$spam_header);
    $table->add('', $select->show($this->gettext('from')));

    $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('searchstring').":"), 'html');
    $inputfield = new html_inputfield(array('name' => '_searchstring', 'id' => 'searchstring'));
    $table->add('', $inputfield->show(""));

    // new option: source folder
    $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('movefrom').":"));
    if (function_exists('rcmail::get_instance()->folder_selector'))
      $select = rcmail::get_instance()->folder_selector(array('name' => '_srcfolders', 'id' => 'srcfolders'));
    else $select = $this->rc->folder_selector(array('name' => '_srcfolders', 'id' => 'srcfolders'));
    $table->add('title',  $select->show());

    $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('moveto').":"));
    if (function_exists('rcmail::get_instance()->folder_selector'))
      $select = rcmail::get_instance()->folder_selector(array('name' => '_folders', 'id' => 'folders'));
    else $select = $this->rc->folder_selector(array('name' => '_folders', 'id' => 'folders'));
    $table->add('title',  $select->show());

    // new option: all, read and unread messages
    $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('messagecount').":"), 'html');
    $select = new html_select(array('name' => '_messages', 'id' => 'messages'));
    $select->add($this->gettext('all'), 'all');
    $select->add($this->gettext('unread'), 'unread');
    $select->add($this->gettext('isread'), 'isread');
    $table->add('', $select->show($this->gettext('all')));

    // new option: markread or markunread messages
    $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('markmessages').":"), 'html');
    $select = new html_select(array('name' => '_markread', 'id' => 'markread'));
    $select->add($this->gettext('none'), 'none');
    $select->add($this->gettext('markunread'), 'markunread');
    $select->add($this->gettext('markread'), 'markread');
    $table->add('', $select->show($this->gettext('none')));

    // new option: filter priority, "on" as enable and "" as disable
    $table->add('title', rcube_utils::rep_specialchars_output($this->gettext('filterpriority').":"), 'html');
    $checkbox = new html_checkbox(array('name' => '_checkbox', 'id' => 'checkbox'));
    $table->add('', $checkbox->show("0"));

    // load saved filters
    $user = $this->rc->user;
    $arr_prefs = $user->get_prefs();
    $i = 1;
    $table2 = new html_table(array('cols' => 2));

    if(empty($arr_prefs['filters']))
      $table2->add('title',rcube_utils::rep_specialchars_output($this->gettext('msg_no_stored_filters'), 'html'));
    else {
      foreach ($arr_prefs['filters'] as $key => $saved_filter){
	if (empty($saved_filter['markread'])) $saved_filter['markread'] = 'none';
	if (empty($saved_filter['srcfolder'])) $saved_filter['srcfolder'] = 'INBOX';
	$srcfolder_id = $saved_filter['srcfolder'];
	$folder_id = $saved_filter['destfolder'];
	if (function_exists('rcmail::get_instance()->localize_folderpath')){
	  $srcfolder_name = rcmail::get_instance()->localize_folderpath($srcfolder_id);
	  $folder_name = rcmail::get_instance()->localize_folderpath($folder_id);
	}
	else{
	  $srcfolder_name = $this->rc->localize_folderpath($srcfolder_id);
	  $folder_name = $this->rc->localize_folderpath($folder_id);
	}

	$messages = $saved_filter['messages'];

	$msg = $i." - ".$this->gettext('msg_if_field')." <b>".$this->gettext($saved_filter['whatfilter'])."</b> ".$this->gettext('msg_contains').
	  " <b>".stripslashes($saved_filter['searchstring'])."</b> ".$this->gettext('msg_move_msg_in')." <b>".$folder_name.
	    "</b> ".$this->gettext('msg_move_msg_from')." <b>".$srcfolder_name."</b> ".
	      "(".$this->gettext('messagecount').": ".$this->gettext($saved_filter['messages']).
		", ".$this->gettext('mark').": ".$this->gettext($saved_filter['markread']).")";
	if ( !empty($saved_filter['filterpriority']))
	  $msg = "<font color='green'>".$msg."</font>";
	  $table2->add('title',$msg);
	  $dlink = "<a href='./?_task=settings&_action=plugin.filters-delete&filterid=".$key."'>".$this->gettext('delete')."</a>";
	  $table2->add('title',$dlink);
	  $i++;
      }
    }

    $out = html::div(array('class' => 'box'),
      html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('filters')).
      html::div(array('class' => 'boxcontent'), $table->show() .
      html::p(null,
        $this->rc->output->button(array(
          'command' => 'plugin.filters-save',
          'type' => 'input',
          'class' => 'button mainaction',
          'label' => 'save'
      )))));
    $out.= html::div(array('id' => 'prefs-title','class' => 'boxtitle'), $this->gettext('storedfilters')).
      html::div(array('class' => 'uibox listbox scroller','style'=>'margin-top:250px;'),
        html::div(array('class' => 'boxcontent'), $table2->show() ));

    $this->rc->output->add_gui_object('filtersform', 'filters-form');

    return $this->rc->output->form_tag(array(
        'id' => 'filters-form',
        'name' => 'filters-form',
        'method' => 'post',
	'class' => 'propform',
        'action' => './?_task=settings&_action=plugin.filters-save',
    ), $out);

  }

  function filters_search($message){
    // check if a message has been read
    if (isset($message->flags['SEEN']) && $message->flags['SEEN'])
      $msg_read = 1;
      $headers = array_merge(array('from','to','cc','subject'), $this->spam_headers);
      $destination_folder = '';
      $filter_flag = '';
      $mark_flag = '';

      foreach($headers as $whatfilter){
      if (isset($this->searchstring[$whatfilter])){
        foreach ($this->searchstring[$whatfilter] as $from => $dest){
	  $arr = explode("#",$dest);
	  $destination = $arr[0];
	  $msg_filter = $arr[1];
	  $filterpriority = $arr[2];
	  $markread = $arr[3];
	  $field = isset($message->$whatfilter) ? $message->$whatfilter : (isset ($message->others[strtolower($whatfilter)]) ? $message->others[strtolower($whatfilter)] : "" );

        if ($this->filters_searchString($field, $from) != false && $destination!=$this->open_mbox){
		  if (!empty($filterpriority)){
			$destination_folder = $destination;
			$filter_flag = $msg_filter;
			$mark_flag = $markread;
			break 2;
		  }
		  if (empty($destination_folder)){
			$destination_folder = $destination;
			$filter_flag = $msg_filter;
			$mark_flag = $markread;
		  }
        }
      }
    }
  }
  if (!empty($destination_folder)){
	// if message as read and need unread message, then exit from function
	// Если сообщение как прочитанное и нужно непрочитанное сообщение, то выход из функции  
	if (!empty($msg_read) && $filter_flag == "unread") return;
	// if message as unread and need read message, then exit from function
	// Если сообщение как непрочитанное и нужно прочитанное сообщение, то выход из функции 
	if (empty($msg_read) && $filter_flag == "isread") return;
	  $this->msg_uids[$destination_folder][] = $message->uid;
	  if (!in_array($destination_folder, $this->destfolder)) $this->destfolder[] = $destination_folder;
	// Mark message as read if need mark message as read
	// Отметить сообщение как прочитанное
	if ($mark_flag == "markread") $this->filters_markread($message);
	// Mark message as unread if need mark message as unread
	// Отметить сообщение как непрочитанное
	if ($mark_flag == "markunread") $this->filters_markread($message,'UNSEEN');
  }
}
  // Mark message as read (SEEN) or as unread (UNSEEN)
  function filters_markread($message,$markread='SEEN'){
	$storage = $this->rc->storage;
	$storage->set_flag($message->uid, $markread, NULL);
  }

  function filters_searchString($msg,$stringToSearch){
    $ret = FALSE;
    $ciSearch = $this->caseInsensitiveSearch;
    $decode_msg	= rcube_mime::decode_header((string)$msg);

    $stringToSearch=stripslashes($stringToSearch);

    $decode_msg = addslashes($decode_msg);
    $stringToSearch = addslashes($stringToSearch);

    if ($ciSearch){
      if (function_exists('mb_stripos')){
        $tmp = mb_stripos($decode_msg, $stringToSearch);
      }
      else{
        $tmp = stripos($decode_msg, $stringToSearch);
      }
    }
    else{
      if (function_exists('mb_strpos')){
        $tmp = mb_strpos($decode_msg, $stringToSearch);
      }
      else{
        $tmp = strpos($decode_msg, $stringToSearch);
      }
    }

    if ($tmp !== FALSE){
      $ret = TRUE;
    }

    else{
      if ($this->decodeBase64Msg === TRUE){
        // decode and search BASE64 msg
	$decode_msg = rcube_mime::decode_header(base64_decode($msg));

        if ($decode_msg !== FALSE){

          if ($ciSearch){
            if (function_exists('mb_stripos')){
              $tmp = mb_stripos($decode_msg, $stringToSearch);
            }
            else{
              $tmp = stripos($decode_msg, $stringToSearch);
            }
          }
          else{
            if (function_exists('mb_strpos')){
              $tmp = mb_strpos($decode_msg, $stringToSearch);
            }
            else{
              $tmp = strpos($decode_msg, $stringToSearch);
            }
          }
          if ($tmp !== FALSE){
            $ret = TRUE;
          }
        }
      }
    }

    return $ret;
  }


  function filters_addMoveSpamRule(){

    $user = $this->rc->user;
    $searchstring = $this->spam_subject;
    $destfolder = $this->rc->config->get('junk_mbox', null);
    $whatfilter = "subject";
    $messages = "all";

    //load filters
    $arr_prefs = $this->rc->config->get('filters', array());

    // check if the rule is already enabled
    $found = false;
    foreach ($arr_prefs as $key => $saved_filter){
      if ($saved_filter['searchstring'] == $searchstring && $saved_filter['whatfilter'] == $whatfilter) $found = true;
      if ($saved_filter['searchstring'] == 'Yes' && $saved_filter['whatfilter'] == 'X-Spam-Flag') $found = true;
    }

    if (!$found && $destfolder !== null && $destfolder !== ""){
      $new_arr['whatfilter'] = $whatfilter;
      $new_arr['searchstring'] = $searchstring;
      $new_arr['srcfolder'] = 'INBOX';
      $new_arr['destfolder'] = $destfolder;
      $new_arr['messages'] = $messages;
      $arr_prefs = $user->get_prefs();
      $arr_prefs['filters'][] = $new_arr;
      $user->save_prefs($arr_prefs);
      $new_arr['whatfilter'] = 'X-Spam-Flag';
      $new_arr['searchstring'] = 'Yes';
      $new_arr['srcfolder'] = 'INBOX';
      $new_arr['destfolder'] = $destfolder;
      $new_arr['messages'] = $messages;
      $arr_prefs = $user->get_prefs();
      $arr_prefs['filters'][] = $new_arr;
      $user->save_prefs($arr_prefs);
    }

  }

}
?>
