<?php
/*
 * This class collects all methods of Openmailadmin, except for the view
 * and data storage. It is not finished, yet!
 *
 * This file is soo long!
 * - Forgive me, but PHP does not support "require_once" inside of class definitions.
 */

class openmailadmin {
    var $current_user;		// What user do we edit/display currently?
    var $authenticated_user;	// What user did log in?

    var $error;			// This will store any errors. (Array)
    var $info;			// Array for informations.

    var $regex_valid_email	= '[a-z0-9][a-z0-9\.\-\_\+]{1,}@[a-z0-9\.\-\_]{2,}\.[a-z]{2,}';
    var $regex_valid_domain	= '[a-z0-9\-\_\.]{2,}\.[a-z]{2,}';

    function openmailadmin() {
	$this->status_reset();
    }

    /*
     * Sets $errors to 'no errors occured' and $info to 'no info'.
     */
    function status_reset() {
	$this->error		= array();
	$this->info		= array();
    }
    /*
     * If any errors occured, returns true.
     */
    function errors_occured() {
	return (count($this->error) > 0);
    }
    function info_occured() {
	return (count($this->info) > 0);
    }
    /*
     * Concatenates every error message.
     */
    function errors_get() {
	$err	= implode(' ',$this->error);
	return $err;
    }
    function info_get() {
	$err	= implode(' ',$this->info);
	return $err;
    }

    /*
     * Returns a long list with every active mailbox.
     */
    function get_mailbox_names() {
	global $cfg;
	$tmp	= array();

	$result = mysql_query('SELECT mbox FROM '.$cfg['tablenames']['user'].' WHERE active = 1 AND mbox_exists = 1');
	if(mysql_num_rows($result) > 0) {
	    while($row = mysql_fetch_assoc($result)) {
		$tmp[] = $row['mbox'];
	    }
	    mysql_free_result($result);
	}
	return $tmp;
    }

    /*
     * As the name says, returns an array containing the entire row
     * of the "user" table belonging to that mailbox.
     */
    function get_user_row($mailbox) {
	global $cfg;

	$result = mysql_query('SELECT * FROM '.$cfg['tablenames']['user']
				.' WHERE mbox="'.mysql_real_escape_string($mailbox).'"'
				.' LIMIT 1');
	if(mysql_num_rows($result) > 0) {
	    $tmp = mysql_fetch_assoc($result);
	    mysql_free_result($result);
	    return $tmp;
	}

	return false;
    }

    /*
     * Accepts a string containing possible destination for an email-address,
     * selects valid destinations and returns them.
     */
    function get_valid_destinations($possible) {
	global $cfg;

	// Define what addresses we will accept.
	$pattern  = $this->regex_valid_email;
	$pattern .= '|'.$this->current_user['mbox'].'|'.txt('5').'|'.strtolower(txt('5'));
	if($cfg['allow_mbox_as_target']) {
	    $mailboxes = &$this->get_mailbox_names();
	    if(count($mailboxes) > 0) {
		$pattern .= '|'.implode('|', $mailboxes);
	    }
	}
	else if($cfg['allow_wcyr_as_target']) {
	    $pattern .= '|[a-z]{2,}[0-9]{4}';
	}

	// Get valid destinations.
	if(preg_match_all('/'.$pattern.'/', $possible, $matched)) {
	    if(is_array($matched[0])) {
		// Replace every occurence of 'mailbox' with the correct name.
		array_walk($matched[0],
				create_function('&$item,$index',
						'if(strtolower($item) == \''.strtolower(txt('5')).'\') $item = \''.$this->current_user['mbox'].'\';'
					       ));
		return $matched[0];
	    }
	}
	return array();
    }

/* ******************************* addresses ******************************** */
    /*
     * Returns a long list with all addresses (the virtual table).
     */
    function get_addresses() {
	global $cfg;
	$alias = array();

	$result = mysql_query('SELECT address, dest, SUBSTRING_INDEX(address, "@", 1) as alias, SUBSTRING_INDEX(address, "@", -1) as domain, active'
				.' FROM '.$cfg['tablenames']['virtual']
				.' WHERE owner="'.$this->current_user['mbox'].'"'.$_SESSION['filter']['str']['address']
				.' ORDER BY domain, dest, alias'
				.$_SESSION['limit']['str']['address']);
	if(mysql_num_rows($result) > 0) {
	    while($row = mysql_fetch_assoc($result)) {
		// explode all destinations (as there may be many)
		$dest = array();
		foreach(explode(',', $row['dest']) as $key => $value) {
		    $value = trim($value);
		    // replace the current user's name with "mailbox"
		    if($value == $this->current_user['mbox'])
			$dest[] = txt('5');
		    else
			$dest[] = $value;
		}
		$row['dest'] = $dest;
		//turn the alias of catchalls to a star
		if($row['address']{0} == '@')
		    $row['alias'] = '*';
		// add the current entry to our list of aliases
		$alias[] = $row;
	    }
	    mysql_free_result($result);
	}

	return $alias;
    }

    /*
     * Creates a new email-address.
     */
    function address_create($alias, $domain, $arr_destinations) {
	global $cfg;

	// May the user create another address?
	if($this->current_user['used_alias'] < $this->current_user['max_alias'] || $this->authenticated_user['a_super'] >= 1) {
	    // If he did choose a catchall, may he create such an address?
	    if($alias == '*' && $cfg['address']['allow_catchall']) {
		if($cfg['address']['restrict_catchall']) {
		    // If either the current or the authenticated user is
		    // owner of that given domain, we can permit creation of that catchall.
		    $result = mysql_query('SELECT domain FROM '.$cfg['tablenames']['domains']
						.' WHERE domain = "'.mysql_real_escape_string($domain).'"'
						.' AND (owner="'.$this->current_user['mbox'].'" OR owner="'.$this->authenticated_user['mbox'].'")'
						.' LIMIT 1');
		    if(mysql_num_rows($result) > 0) {
			mysql_free_result($result);
		    }
		    else {
			$this->error[]	= txt('16');
			return false;
		    }
		    // There shall be no local part in the address. That is characteristic for catchalls.
		    $alias = '';
		}
	    }
	    // Will his new address be a valid one?
	    else if(preg_match('/([A-Z0-9\.\-\_]{'.strlen($alias).'})/i', $alias)) {
		if(!($this->current_user['reg_exp'] == '' || preg_match($this->current_user['reg_exp'], $alias.'@'.$domain))) {
		    $this->error[]	= txt('12');
		    return false;
		}
	    }
	    else {
		$this->error[]	= txt('13');
		return false;
	    }

	    // Finally, create that address.
	    mysql_query('INSERT INTO '.$cfg['tablenames']['virtual'].' (address, dest, owner)'
			.' VALUES ("'.mysql_real_escape_string(strtolower($alias.'@'.$domain)).'", "'.implode(',', $arr_destinations).'", "'.$this->current_user['mbox'].'")');
	    if(mysql_affected_rows() < 1) {
		$this->error[]	=mysql_error();
	    }
	    else {
		$this->current_user['used_alias']++;
		return true;
	    }
	}
	else {
	    $this->error[]	= txt('14');
	}

	return false;
    }
    /*
     * Deletes the given addresses if they belong to the current user.
     */
    function address_delete($arr_addresses) {
	global $cfg;

	mysql_query('DELETE FROM '.$cfg['tablenames']['virtual']
			.' WHERE owner = "'.$this->current_user['mbox'].'"'
			.' AND FIND_IN_SET(address, "'.mysql_real_escape_string(implode(',', $arr_addresses)).'")'
			.' LIMIT '.count($arr_addresses));
	if(mysql_affected_rows() < 1) {
	    if(mysql_errno() != 0) {
		$this->error[]	= mysql_error();
	    }
	}
	else {
	    $this->info[]	= sprintf(txt('15'), implode(',', $arr_addresses));
	    $this->current_user['used_alias'] -= mysql_affected_rows();
	    return true;
	}

	return false;
   }
    /*
     * Changes the destination of the given addresses if they belong to the current user.
     */
    function address_change_destination($arr_addresses, $arr_destinations) {
	global $cfg;

	mysql_query('UPDATE '.$cfg['tablenames']['virtual'].' SET dest = "'.mysql_real_escape_string(implode(',', $arr_destinations)).'", neu = 1'
			.' WHERE owner = "'.$this->current_user['mbox'].'"'
			.' AND FIND_IN_SET(address, "'.mysql_real_escape_string(implode(',', $arr_addresses)).'")'
			.' LIMIT '.count($arr_addresses));
	if(mysql_affected_rows() < 1) {
	    if(mysql_errno() != 0) {
		$this->error[]	= mysql_error();
	    }
	}
	else {
	    return true;
	}

	return false;
    }
    /*
     * Toggles the 'active'-flag of a set of addresses  of the current user
     * and thus sets inactive ones to active ones and vice versa.
     */
    function address_toggle_active($arr_addresses) {
	global $cfg;

	mysql_query('UPDATE '.$cfg['tablenames']['virtual'].' SET active = NOT active, neu = 1'
			.' WHERE owner = "'.$this->current_user['mbox'].'"'
			.' AND FIND_IN_SET(address, "'.mysql_real_escape_string(implode(',', $arr_addresses)).'")'
			.' LIMIT '.count($arr_addresses));
	if(mysql_affected_rows() < 1) {
	    if(mysql_errno() != 0) {
		$this->error[]	= mysql_error();
	    }
	}
	else {
	    return true;
	}

	return false;
    }

/* ******************************* domains ********************************** */
    var $editable_domains;	// How many domains can the current user change?
    /*
     * Returns a long list with all domains (from table 'domains').
     */
    function get_domains() {
	global $cfg;
	$this->editable_domains = 0;
	$domains = array();

	$query  = 'SELECT SQL_CALC_FOUND_ROWS *'
		 .' FROM '.$cfg['tablenames']['domains'];
	if($this->authenticated_user['a_super'] > 0) {
		$query .= ' WHERE 1=1 '.$_SESSION['filter']['str']['domain'];
	}
	else {
		$query .= ' WHERE (owner=\''.$this->current_user['mbox'].'\' or a_admin LIKE \'%'.$this->current_user['mbox'].'%\')'
			 .$_SESSION['filter']['str']['domain'];
	}
	$query .= ' ORDER BY owner, length(a_admin), domain'
		 .$_SESSION['limit']['str']['domain'];;

	$result = mysql_query($query);
	if(mysql_num_rows($result) > 0) {
	    while($row = mysql_fetch_assoc($result)) {
		if($row['owner'] == $this->authenticated_user['mbox']
		    || find_in_set($this->authenticated_user['mbox'], $row['a_admin'])) {
		    $row['selectable']	= true;
		    ++$this->editable_domains;
		}
		else {
		    $row['selectable']	= false;
		}
		$domains[] = $row;
	    }
	    mysql_free_result($result);
	    $result = mysql_query('SELECT FOUND_ROWS()');
	    $this->current_user['n_domains'] = mysql_result($result, 0, 0);
	    mysql_free_result($result);

	}

	return $domains;
    }
    /*
     * Adds a new domain into the corresponding table.
     * Categories are for grouping domains.
     */
    function domain_add($domain, $categories, $owner, $administrators) {
	global $cfg;

	if(!stristr($categories, 'all'))
	    $categories = 'all, '.$categories;
	if(trim($owner) == '')
	    $owner = $this->current_user['mbox'];
	if(trim($administrators) == '')
	    $administrators = $this->current_user['mbox'];
	if(!stristr($administrators.$owner, $this->current_user['mbox']))
	    $administrators .= ' '.$this->current_user['mbox'];

	if(preg_match('/^'.$this->regex_valid_domain.'$/i', $domain)) {
	    mysql_query('INSERT INTO '.$cfg['tablenames']['domains'].' (domain, categories, owner, a_admin)'
			.' VALUES ("'.mysql_real_escape_string($domain).'", "'.mysql_real_escape_string($categories).'", "'.mysql_real_escape_string($owner).'", "'.mysql_real_escape_string($administrators).'")');
	    if(mysql_affected_rows() < 1) {
		$this->error[]	= mysql_error();
	    }
	    else {
		return true;
	    }
	}
	else {
	    $this->error[]	= txt('51');
	}

	return false;
    }
    /*
     * Not only removes the given domains by their ids,
     * it deactivates every address which ends in that domain.
     */
    function domain_remove($domains) {
	global $cfg;

	// We need the old domain name later...
	if(is_array($domains) && count($domains) > 0) {
	    if($cfg['admins_delete_domains'])
		$result = mysql_query('SELECT ID, domain'
					.' FROM '.$cfg['tablenames']['domains']
					.' WHERE (owner="'.$this->authenticated_user['mbox'].'" or a_admin LIKE "%'.$this->authenticated_user['mbox'].'%") AND FIND_IN_SET(ID, "'.mysql_real_escape_string(implode(',', $domains)).'")'
					.' LIMIT '.count($domains));
	    else
		$result = mysql_query('SELECT ID, domain'
					.' FROM '.$cfg['tablenames']['domains']
					.' WHERE owner="'.$this->authenticated_user['mbox'].'" AND FIND_IN_SET(ID, "'.mysql_real_escape_string(implode(',', $domains)).'")'
					.' LIMIT '.count($domains));
	    if(mysql_num_rows($result) > 0) {
		while($domain = mysql_fetch_assoc($result)) {
		    $del_ID[] = $domain['ID'];
		    $del_nm[] = $domain['domain'];
		}
		mysql_free_result($result); unset($domain);
		mysql_query('DELETE FROM '.$cfg['tablenames']['domains'].' WHERE FIND_IN_SET(ID, "'.implode(',',$del_ID).'") LIMIT '.count($del_ID));
		if(mysql_affected_rows() < 1) {
		    if(mysql_errno() != 0) {
			$this->error[]	= mysql_error();
		    }
		}
		else {
		    $this->info[]	= txt('52').'<br />'.implode(', ', $del_nm);
		    // We better deactivate all aliases containing that domain, so users can see the domain was deleted.
		    mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual'].' SET active = 0, neu = 1 WHERE FIND_IN_SET(SUBSTRING(address, LOCATE(\'@\', address)+1), \''.implode(',', $del_nm).'\')');
		    // We can't do such on REGEXP addresses: They may catch more than the given domains.
		    return true;
		}
	    }
	    else {
		$this->error[]	= txt('16');
	    }
	}
	else {
	    $this->error[]	= txt('11');
	}

	return false;
    }
    /*
     * Every parameter is an array.
     */
    function domain_change($domains, $change, $data) {
	global $cfg;

	if(!is_array($change)) {
	    $this->error[]	= txt('53');
	    return false;
	}
	if($cfg['admins_delete_domains'] && in_array('owner', $change))
	    $toc[] = 'owner="'.mysql_real_escape_string($data['owner']).'"';
	if(in_array('a_admin', $change))
	    $toc[] = 'a_admin="'.mysql_real_escape_string($data['a_admin']).'"';
	if(in_array('categories', $change))
	    $toc[] = 'categories="'.mysql_real_escape_string($data['categories']).'"';
	if(isset($toc) && is_array($toc)) {
	    mysql_query('UPDATE '.$cfg['tablenames']['domains']
			.' SET '.implode(',', $toc)
			.' WHERE (owner="'.$this->authenticated_user['mbox'].'" or a_admin LIKE "%'.$this->authenticated_user['mbox'].'%") AND FIND_IN_SET(ID, "'.mysql_real_escape_string(implode(',', $domains)).'")'
			.' LIMIT '.count($domains));
	    if(mysql_affected_rows() < 1) {
		if(mysql_errno() != 0) {
		    $this->error[]	= mysql_error();
		}
		else {
		    $this->error[]	= txt('16');
		}
	    }
	}
	// changing ownership if $cfg['admins_delete_domains'] == false
	if(!$cfg['admins_delete_domains'] && in_array('owner', $change)) {
	    mysql_query('UPDATE '.$cfg['tablenames']['domains']
			.' SET owner="'.mysql_real_escape_string($data['owner']).'"'
			.' WHERE owner="'.$this->authenticated_user['mbox'].'" AND FIND_IN_SET(ID, "'.mysql_real_escape_string(implode(',', $domains)).'")'
			.' LIMIT '.count($domains));
	}
	// No domain be renamed?
	if(! in_array('domain', $change)) {
	    return true;
	}
	// Otherwise (and if only one) try adapting older addresses.
	if(count($domains) == 1) {
	    if(preg_match('/^'.$this->regex_valid_domain.'$/i', $data['domain'])) {
		// Grep the old name, we will need it later for replacement.
		$result = mysql_query('SELECT ID, domain AS name FROM '.$cfg['tablenames']['domains'].' WHERE ID = "'.mysql_real_escape_string($domains[0]).'" AND (owner="'.$this->authenticated_user['mbox'].'" or a_admin LIKE "%'.$this->authenticated_user['mbox'].'%")');
		if(mysql_num_rows($result) == 1) {
		    $domain = mysql_fetch_assoc($result);
		    mysql_free_result($result);
		    // First, update the name. (Corresponding field is marked as unique, therefore we will not receive doublettes.)...
		    mysql_query('UPDATE '.$cfg['tablenames']['domains'].' SET domain = "'.$data['domain'].'" WHERE ID = '.$domain['ID'].' LIMIT 1');
		    // ... and then, change every old address.
		    if(mysql_affected_rows() == 1) {
			// address
			mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual'].' SET neu = 1, address = REPLACE(address, "@'.$domain['name'].'", "@'.$data['domain'].'") WHERE address LIKE "%@'.$domain['name'].'"');
			// dest
			mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual'].' SET neu = 1, dest = REPLACE(dest, "@'.$domain['name'].'", "@'.$data['domain'].'") WHERE dest LIKE "%@'.$domain['name'].'%"');
			mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual_regexp'].' SET neu = 1, dest = REPLACE(dest, "@'.$domain['name'].'", "@'.$data['domain'].'") WHERE dest LIKE "%@'.$domain['name'].'%"');
			// canonical
			mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['user'].' SET canonical = REPLACE(canonical, "@'.$domain['name'].'", "@'.$data['domain'].'") WHERE canonical LIKE "%@'.$domain['name'].'"');
		    }
		    else {
			$this->error[]	= mysql_error();
		    }

		    return true;
		}
		else
		    $this->error[]	= txt('91');
	    }
	    else
		$this->error[]	= txt('51');
	}
	else
	    $this->error[]	= txt('53');

	return false;
    }

/* ******************************* passwords ******************************** */
    /*
     * (Re)sets the user's password.
     * Use this if the old password does not matter.
     * Includes the check whether the user has the right to do this.
     */
    function user_set_password($username, $plaintext_password) {
	global $cfg;

	// Check whether the authenticated user has the right to do that.
	if($this->authenticated_user['a_super'] < 1
		&& $username != $this->authenticated_user['mbox']
		&& !IsDescendant($username, $this->authenticated_user['mbox'])) {
	    $this->error[]	= txt('49');
	    return false;
	}

	if($plaintext_password != '') {
	    $new_crypt	= crypt($plaintext_password, substr($plaintext_password,0,2));
	    $new_md5	= md5($plaintext_password);
	}
	else {
	    $new_crypt = '';
	    $new_md5 = '';
	}
	mysql_query('UPDATE '.$cfg['tablenames']['user']
			.' SET pass_crypt="'.$new_crypt.'", pass_md5="'.$new_md5.'"'
			.' WHERE mbox="'.$username.'" LIMIT 1');
	if(mysql_affected_rows() > 0) {
	    $this->info[]	= txt('48');
	    return true;
	}
	else {
	    if(mysql_errno() != 0) {
		$this->error[]	= mysql_error();
	    }
	    return false;
	}
    }

    /*
     * Changes the current user's password.
     * This requires the former password for authentication if current user and
     * authenticated user are the same.
     */
   function user_change_password($new, $new_repeat, $old_passwd = null) {
	global $cfg;

	if($this->current_user['mbox'] == $this->authenticated_user['mbox']
		&& !is_null($old_passwd)
		&& !(passwd_check($old_passwd, $this->current_user['pass_crypt'])
			|| passwd_check($old_passwd, $this->current_user['pass_md5']))) {
	    $this->error[]	= txt('45');
	}
	else if($new != $new_repeat) {
	    $this->error[]	= txt('44');
	}
	else if(strlen($new) < $cfg['passwd']['min_length']
		|| strlen($new) > $cfg['passwd']['max_length']) {
	    $this->error[]	= sprintf(txt('46'), $cfg['passwd']['min_length'], $cfg['passwd']['max_length']);
	}
	else {
	    // Warn about insecure passwords, but let them pass.
	    if(!(preg_match('/[a-z]{1}/', $new) && preg_match('/[A-Z]{1}/', $new) && preg_match('/[0-9]{1}/', $new))) {
		$this->error[]	= txt('47');
	    }
	    if($this->user_set_password($this->current_user['mbox'], $new)) {
		return true;
	    }
	}

	return false;
    }

/* ******************************* regexp *********************************** */
    /*
     * Returns a long list with all regular expressions (the virtual_regexp table).
     * If $match_against is given, the flag "matching" will be set on matches.
     */
    function get_regexp($match_against = null) {
	global $cfg;
	$regexp = array();

	$result = mysql_query('SELECT * FROM '.$cfg['tablenames']['virtual_regexp']
			.' WHERE owner="'.$this->current_user['mbox'].'"'.$_SESSION['filter']['str']['regexp']
			.' ORDER BY dest'.$_SESSION['limit']['str']['regexp']);
	if(mysql_num_rows($result) > 0) {
	    while($row = mysql_fetch_assoc($result)) {
		// if ordered, check whether expression matches probe address
		if(!is_null($match_against)
			&& @preg_match($row['reg_exp'], $match_against)) {
		    $row['matching']	= true;
		}
		else {
		    $row['matching']	= false;
		}
		// explode all destinations (as there may be many)
		$dest = array();
		foreach(explode(',', $row['dest']) as $key => $value) {
		    $value = trim($value);
		    // replace the current user's name with "mailbox"
		    if($value == $this->current_user['mbox'])
			$dest[] = txt('5');
		    else
			$dest[] = $value;
		}
		$row['dest'] = $dest;
		// add the current entry to our list of aliases
		$regexp[] = $row;
	    }
	    mysql_free_result($result);
	}

	return $regexp;
    }
    /*
     * Creates a new regexp-address.
     */
    function regexp_create($regexp, $arr_destinations) {
	global $cfg;

	// some dull checks;
	// if someone knows how to find out whether an string is a valid regexp -> write me please
	if($regexp == '' || $regexp{0} != '/') {
	    $this->error[]	= txt('127');
	    return false;
	}

	if($this->current_user['used_regexp'] < $this->current_user['max_regexp']
		|| $this->authenticated_user['a_super'] > 0) {
	    mysql_query('INSERT INTO '.$cfg['tablenames']['virtual_regexp'].' (reg_exp, dest, owner)'
			.' VALUES ("'.mysql_real_escape_string($regexp).'", "'.implode(',', $arr_destinations).'", "'.$this->current_user['mbox'].'")');
	    if(mysql_affected_rows() < 1) {
		if(mysql_errno() != 0) {
		    $this->error[]	= mysql_error();
		}
	    }
	    else {
		$this->current_user['used_regexp']++;
		return true;
	    }
	}
	else {
	    $this->error[]	= txt('31');
	}

	return false;
    }
    /*
     * Deletes the given regular expressions if they belong to the current user.
     */
    function regexp_delete($arr_regexp_ids) {
	global $cfg;

	mysql_query('DELETE FROM '.$cfg['tablenames']['virtual_regexp']
			.' WHERE owner = "'.$this->current_user['mbox'].'"'
			.' AND FIND_IN_SET(ID, "'.mysql_real_escape_string(implode(',', $arr_regexp_ids)).'")'
			.' LIMIT '.count($arr_regexp_ids));
	if(mysql_affected_rows() < 1) {
	    if(mysql_errno() != 0) {
		$this->error[]	= mysql_error();
	    }
	}
	else {
	    $this->info[]	= txt('32');
	    $this->current_user['used_regexp'] -= mysql_affected_rows();
	    return true;
	}

	return false;
    }
    /*
     * See "address_change_destination".
     */
    function regexp_change_destination($arr_regexp_ids, $arr_destinations) {
	global $cfg;

	mysql_query('UPDATE '.$cfg['tablenames']['virtual_regexp'].' SET dest = "'.implode(',', $arr_destinations).'", neu = 1'
			.' WHERE owner = "'.$this->current_user['mbox'].'"'
			.' AND FIND_IN_SET(ID, "'.mysql_real_escape_string(implode(',', $arr_regexp_ids)).'")'
			.' LIMIT '.count($arr_regexp_ids));
	if(mysql_affected_rows() < 1) {
	    if(mysql_errno() != 0) {
		$this->error[]	= mysql_error();
	    }
	}
	else {
	    return true;
	}

	return false;
    }
    /*
     * See "address_toggle_active".
     */
    function regexp_toggle_active($arr_regexp_ids) {
	global $cfg;

	mysql_query('UPDATE '.$cfg['tablenames']['virtual_regexp'].' SET active = NOT active, neu = 1'
			.' WHERE owner = "'.$this->current_user['mbox'].'"'
			.' AND FIND_IN_SET(ID, "'.mysql_real_escape_string(implode(',', $arr_regexp_ids)).'")'
			.' LIMIT '.count($arr_regexp_ids));
	if(mysql_affected_rows() < 1) {
	    if(mysql_errno() != 0) {
		$this->error[]	= mysql_error();
	    }
	}
	else {
	    return true;
	}

	return false;
    }

/* ******************************* mailboxes ******************************** */
    var $blacklist	= array('cyrus');	// Pretend these users don't exist.
    function get_mailboxes() {
	global $cfg;
	$mailboxes = array();

	if($this->current_user['mbox'] == $this->authenticated_user['mbox']
		&& $this->authenticated_user['a_super'] >= 1) {
	    $where_clause = ' WHERE TRUE';
	}
	else {
	    $where_clause = ' WHERE pate="'.$this->current_user['mbox'].'"';
	}
	$result = mysql_query('SELECT SQL_CALC_FOUND_ROWS mbox, person, canonical, pate, max_alias, max_regexp, active, date(last_login) AS lastlogin, a_super, a_admin_domains, a_admin_user, '
				    .'(SELECT count(*) FROM '.$cfg['tablenames']['virtual']
				    .' WHERE '.$cfg['tablenames']['virtual'].'.owner=mbox) AS num_alias, '
				    .'(SELECT count(*) FROM '.$cfg['tablenames']['virtual_regexp']
				    .' WHERE '.$cfg['tablenames']['virtual_regexp'].'.owner=mbox) AS num_regexp'
				.' FROM '.$cfg['tablenames']['user']
				.$where_clause.$_SESSION['filter']['str']['mbox']
				.' ORDER BY pate, mbox'.$_SESSION['limit']['str']['mbox']);

	$this->current_user['n_mbox'] = 0;
	if(mysql_num_rows($result) > 0) {
	    $result2 = mysql_query('SELECT FOUND_ROWS()');
	    $this->current_user['n_mbox'] = mysql_result($result2, 0, 0);
	    mysql_free_result($result2);

	    while($row = mysql_fetch_assoc($result)) {
		if(in_array($row['mbox'], $this->blacklist))
		    continue;

		$row['quota'] = hsys_format_quota($row['mbox']);
		$mailboxes[] = $row;
	    }
	    mysql_free_result($result);
	}

	return $mailboxes;
    }

    function get_selectable_paten($whose) {
	global $cfg;

	if(!isset($_SESSION['paten'][$whose])) {
	    $selectable_paten = array();
	    if($this->authenticated_user['a_super'] >= 1) {
		$result = mysql_query('SELECT mbox FROM '.$cfg['tablenames']['user']);
	    }
	    else {
		$result = mysql_query('SELECT mbox FROM '.$cfg['tablenames']['user']
				    .' WHERE pate="'.$whose.'"');
	    }
	    while($row = mysql_fetch_assoc($result)) {
		if(in_array($row['mbox'], $this->blacklist))
		    continue;
		$selectable_paten[] = $row['mbox'];
	    }
	    mysql_free_result($result);
	    $selectable_paten[] = $whose;
	    $selectable_paten[] = $this->authenticated_user['mbox'];

	    // Array_unique() will do alphabetical sorting.
	    $_SESSION['paten'][$whose] = array_unique($selectable_paten);
	}

	return $_SESSION['paten'][$whose];
    }

    function mailbox_filter_manipulable($who, $desired_mboxes) {
	$allowed = array();

	// Does the authenticated user have the right to do that?
	if($this->authenticated_user['a_super'] >= 1) {
	    $allowed = $desired_mboxes;
	}
	else {
	    foreach($mboxnames as $mbox) {
		if(IsDescendant($mbox, $who)) {
		    $allowed[] = $mbox;
		}
	    }
	}

	return $allowed;
    }

    function mailbox_check_requirements($action, $props) {
	global $cfg;

	// beautify
	if(isset($props['quota']) && $props['quota'] != 'NOT-SET') {
	    $props['quota'] = abs(intval($props['quota']));
	}
	if(isset($props['max_alias']))
	    $props['max_alias'] = abs(intval($props['max_alias']));
	else
	    $props['max_alias'] = 0;
	if(isset($props['max_regexp']))
	    $props['max_regexp'] = abs(intval($props['max_regexp']));
	else
	    $props['max_regexp'] = 0;
	// requirements
	if(($action == 'change' && in_array('quota', $props['change']) || $action == 'new')
		&& $props['quota'] == 'NOT-SET'
		&& ($this->authenticated_user['a_super'] == 0 || hsys_getMaxQuota($this->current_user['mbox']) != 'NOT-SET')) {
	    $this->error[] = txt('63');
	}
	if($action == 'change' || $action == 'delete' || $action == 'active') {
	    if(count($props['user']) < 1) {
		$this->error[] = txt('11');
	    }
	}
	if($action == 'change' && in_array('mbox', $props['change']) && count($props['user']) != 1) {
	    $this->error[] = txt('91');
	}
	if(!$this->errors_occured() && ($action == 'new' || $action == 'change')) {
	    if($action == 'new' && !(isset($props['mbox']) && isset($props['person']) && isset($props['canonical']) && isset($props['reg_exp']) && isset($props['domains']))) {
		$this->error[] = txt('61');
	    }
	    if(($action == 'new' || in_array('mbox', $props['change']))
			&& (strlen($props['mbox']) < 4 || strlen($props['mbox']) > 16 || !preg_match('/[a-zA-Z0-9]{'.strlen($props['mbox']).'}/', $props['mbox']))) {
		$this->error[] = txt('62');
	    }
	    if(($action == 'new' || in_array('canonical', $props['change']))
			&& !preg_match('/'.$this->regex_valid_email.'/i', $props['canonical'])) {
		$this->error[] = txt('64');
	    }
	    if($action == 'change' && in_array('mbox', $props['change']) && $props['mbox'] == $props['user']['0']) {
		$this->error[] = txt('93');
	    }
	    if(!$this->errors_occured() && $action == 'change' && in_array('mbox', $props['change'])) {
		// Is the desired name already in use?
		$result = mysql_query('SELECT COUNT(mbox) AS number FROM '.$cfg['tablenames']['user'].' WHERE mbox = "'.$props['mbox'].'"');
		$tmp = mysql_fetch_assoc($result);
		mysql_free_result($result);
		if($tmp['number'] > 0) {
		    $this->error[] = txt('92');
		}
		unset($tmp); unset($result);
	    }
	    // check quota and address contingents
	    if(($action == 'change' && in_array('domains', $props['change']))
			|| $action == 'new') {
		if(!isset($this->current_user['domain_set']))
		    $this->current_user['domain_set'] = getDomainSet($this->current_user['mbox'], $this->current_user['domains']);
		// new domain-key must not lead to more domains than the user already has to choose from
		// what domains the new user will have to choose from? A
		$dom_a = getDomainSet(mysql_escape_string($action == 'change' ? $props['user'][0] : $props['mbox']), mysql_escape_string($props['domains']));
		// what domains the creator may choose from? B
		// okay, if A plus B is A (thus, no additional domains are added)
		$dom_ab = array_unique(array_merge($dom_a, $this->current_user['domain_set']));
		if(count($dom_a) == 0) {
		    $this->error[] = txt('80');
		}
		else if(count($this->current_user['domain_set']) != count($dom_ab)) {
		    $this->error[] = txt('81');
		}
	    }
	    if($this->authenticated_user['a_super'] == 0 && hsys_getMaxQuota($this->current_user['mbox']) != 'NOT-SET' &&
		    $action == 'new' && $props['quota'] > (hsys_getMaxQuota($this->current_user['mbox']) - hsys_getUsedQuota($this->current_user['mbox']))) {
		$this->error[] = txt('65');	// does not apply if action==change! (see below)
	    }
	    if($this->authenticated_user['a_super'] == 0
		&& ($action == 'new' || in_array('alias', $props['change']) || in_array('reg_exp', $props['change']))
		&& ($props['max_alias'] > ($this->current_user['max_alias'] - hsys_getUsedAlias($this->current_user['mbox']))
		    || $props['max_regexp'] > ($this->current_user['max_regexp'] - hsys_getUsedRegexp($this->current_user['mbox'])))) {
		$this->error[] = txt('66');
	    }
	    if(!$this->errors_occured()) {
		// are the users descendants of the authentificated user?
		if($this->authenticated_user['a_super'] == 0 && isset($props['user']) && is_array($props['user'])) {
		    foreach($props['user'] as $key=>$user) {
			if($user != '') {
			    if(!IsDescendant($user, $this->authenticated_user['mbox'])) {
				$this->error[] = txt('16');
				break;
			    }
			}
		    }
		    reset($props['user']);
		}
		// Quota
		if(!$this->errors_occured() && $action == 'change' && $this->authenticated_user['a_super'] == 0
			&& in_array('quota', $props['change']) && hsys_getMaxQuota($this->current_user['mbox']) != 'NOT-SET') {
		    foreach($props['user'] as $key=>$user) {
			if($user != '') {
			    if(hsys_getMaxQuota($user) != 'NOT-SET')
				$add_quota += $props['quota'] - hsys_getMaxQuota($user);
			}
		    }
		    reset($props['user']);
		    if(hsys_getMaxQuota($this->current_user['mbox']) - hsys_getUsedQuota($this->current_user['mbox']) < $add_quota) {
			$this->error[] = txt('65');
			$this->error[] = sprintf(txt('67'), $add_quota, (hsys_getMaxQuota($this->current_user['mbox']) - hsys_getUsedQuota($this->current_user['mbox'])));
		    }
		    unset($add_quota);
		}
	    }
	}

	if(!$this->errors_occured()) {
	    return true;
	}

	return false;
    }

    function mailbox_create($mboxname, $props) {
	global $cfg;
	global $cyr;

	$mboxname = mysql_real_escape_string($mboxname);

	// first create the default-from (canonical) (must not already exist!)
	if($cfg['create_canonical']) {
	    if(preg_match('/'.$this->regex_valid_email.'/i', $props['canonical'])) {
		mysql_query('INSERT INTO '.$cfg['tablenames']['virtual'].' (address, dest, owner)'
			    .' VALUES ("'.$props['canonical'].'", "'.$mboxname.'", "'.$mboxname.'")');
		if(mysql_affected_rows() < 1) {
		    $this->error[]	= mysql_error();
		    return false;
		}
	    }
	    else {
		$this->error[]		= txt('64');
		return false;
	    }
	}
	// on success write the new user to database
	$sql_a1 = ''; $sql_a2 = '';
	if(isset($props['a_a_dom']) && is_numeric($props['a_a_dom']) && $this->authenticated_user['a_admin_domains'] >= 2) {
	    $sql_a1 .= ', a_admin_domains';
	    $sql_a2 .= ', '.(max(0, min(intval($props['a_a_dom']), $this->authenticated_user['a_admin_domains'])));
	}
	if(isset($props['a_a_usr']) && is_numeric($props['a_a_usr']) && $this->authenticated_user['a_admin_user'] >= 2) {
	    $sql_a1 .= ', a_admin_user';
	    $sql_a2 .= ', '.(max(0, min(intval($props['a_a_usr']), $this->authenticated_user['a_admin_user'])));
	}
	if(isset($props['a_super']) && is_numeric($props['a_super']) && $this->authenticated_user['a_super'] >= 2) {
	    $sql_a1 .= ', a_super';
	    $sql_a2 .= ', '.(max(0, min(intval($props['a_super']), $this->authenticated_user['a_super'])));
	}

	mysql_query('INSERT INTO '.$cfg['tablenames']['user'].' (mbox, person, pate, canonical, reg_exp, domains, max_alias, max_regexp, created'.$sql_a1.')'
		    .' VALUES ("'.mysql_real_escape_string($props['mbox']).'","'.mysql_real_escape_string($props['person']).'","'.mysql_real_escape_string($props['pate']).'","'.mysql_real_escape_string($props['canonical']).'","'.mysql_real_escape_string($props['reg_exp']).'","'.mysql_real_escape_string($props['domains']).'",'.intval($props['max_alias']).','.intval($props['max_regexp']).', now()'.$sql_a2.')');
	if(mysql_affected_rows() < 1) {
	    $this->error[]	= mysql_error();
	    return false;
	}
	// decrease current users's contingents
	if($this->authenticated_user['a_super'] == 0) {
	    mysql_unbuffered_query('UPDATE '.$cfg['tablenames']['user']
			.' SET max_alias='.($this->current_user['max_alias']-intval($props['max_alias'])).', max_regexp='.($this->current_user['max_regexp']-intval($props['max_regexp']))
			.' WHERE mbox="'.$this->current_user['mbox'].'" LIMIT 1');
	}
	// ... and then, on success, create the user in cyrus
	$result = $cyr->createmb(cyrus_format_user($mboxname));
	if($result) {
	    $this->error[]	= var_export($result, true);
	    // Rollback
	    mysql_unbuffered_query('UPDATE '.$cfg['tablenames']['user'].' SET max_alias='.$this->current_user['max_alias'].', max_regexp='.$this->current_user['max_regexp'].' WHERE mbox="'.$this->current_user['mbox'].'" LIMIT 1');
	    mysql_unbuffered_query('DELETE FROM '.$cfg['tablenames']['user'].' WHERE mbox="'.$mboxname.'" LIMIT 1');
	    if($cfg['create_canonical'])
		mysql_unbuffered_query('DELETE FROM '.$cfg['tablenames']['virtual'].' WHERE address="'.$props['canonical'].'" LIMIT 1');
	    return false;
	}
	else {
	    mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['user'].' SET mbox_exists=1 WHERE mbox="'.$mboxname.'" LIMIT 1');
	    if(isset($cfg['folders']['create_default']) && is_array($cfg['folders']['create_default'])) {
		foreach($cfg['folders']['create_default'] as $key => $new_folder) {
		    $cyr->createmb(cyrus_format_user($mboxname, $new_folder));
		}
		unset($new_folder); unset($key);
	    }
	}
	// (define quota)
	if($this->authenticated_user['a_super'] == 0 && hsys_getMaxQuota($this->current_user['mbox']) != 'NOT-SET') {
	    $result = $cyr->setmbquota(cyrus_format_user($this->current_user['mbox']), hsys_getMaxQuota($this->current_user['mbox'])-$props['quota']);
	    if($result) {
		$this->error[]	= var_export($result, true);
		// Rollback
		$cyr->deletemb(cyrus_format_user($mboxname));
		mysql_unbuffered_query('UPDATE '.$cfg['tablenames']['user'].' SET max_alias='.$this->current_user['max_alias'].', max_regexp='.$this->current_user['max_regexp'].' WHERE mbox="'.$this->current_user['mbox'].'" LIMIT 1');
		mysql_unbuffered_query('DELETE FROM '.$cfg['tablenames']['user'].' WHERE mbox="'.$mboxname.'" LIMIT 1');
		if($cfg['create_canonical'])
		    mysql_unbuffered_query('DELETE FROM '.$cfg['tablenames']['virtual'].' WHERE address="'.$props['canonical'].'" LIMIT 1');
		return false;
	    }
	    $this->info[]	= sprintf(txt('69'), hsys_getMaxQuota($this->current_user['mbox'])-$props['quota']);
	}
	else {
	    $this->info[]	= txt('71');
	}

	if(is_numeric($props['quota'])) {
	    $result = $cyr->setmbquota(cyrus_format_user($mboxname), $props['quota']);
	    if($result) {
		$this->error[]	= var_export($result, true);
		// Rollback
		$cyr->deletemb(cyrus_format_user($mboxname));
		if($this->authenticated_user['a_super'] == 0 && hsys_getMaxQuota($this->current_user['mbox']) != 'NOT-SET')
		    $cyr->setmbquota(cyrus_format_user($this->current_user['mbox']), hsys_getMaxQuota($this->current_user['mbox']));
		mysql_unbuffered_query('UPDATE '.$cfg['tablenames']['user'].' SET max_alias='.$this->current_user['max_alias'].', max_regexp='.$this->current_user['max_regexp'].' WHERE mbox="'.$this->current_user['mbox'].'" LIMIT 1');
		mysql_unbuffered_query('DELETE FROM '.$cfg['tablenames']['user'].' WHERE mbox="'.$mboxname.'" LIMIT 1');
		if($cfg['create_canonical'])
		    mysql_unbuffered_query('DELETE FROM '.$cfg['tablenames']['virtual'].' WHERE address="'.$props['canonical'].'" LIMIT 1');
		return false;
	    }
	}
	$this->info[]	= sprintf(txt('72'), B($mboxname), B($props['person']));
	if(isset($_SESSION['paten'][$props['pate']])) {
	    $_SESSION['paten'][$props['pate']][] = $mboxname;
	}
    }

    function mailbox_change($mboxnames, $change, $props) {
	global $cfg;
	global $cyr;

	$aux_tmp = mysql_real_escape_string(implode(',', $this->mailbox_filter_manipulable($this->authenticated_user['mbox'], $mboxnames)));

	if(count($aux_tmp) == 1) {
	    if(in_array('person', $change))
		$to_change[]	= 'person = "'.mysql_escape_string($props['person']).'"';
	    if(in_array('canonical', $change))
		$to_change[]	= 'canonical = "'.mysql_escape_string($props['canonical']).'"';
	}
	if(in_array('pate', $change))
	    $to_change[]	= 'pate = "'.mysql_escape_string($props['pate']).'"';
	if(in_array('domains', $change))
	    $to_change[]	= 'domains = "'.mysql_escape_string($props['domains']).'"';
	if(in_array('max_alias', $change))
	    $to_change[]	= 'max_alias = '.intval($props['max_alias']);
	if(in_array('max_regexp', $change))
	    $to_change[]	= 'max_regexp = '.intval($props['max_regexp']);
	if(in_array('reg_exp', $change))
	    $to_change[]	= 'reg_exp = "'.mysql_escape_string($props['reg_exp']).'"';

	if(in_array('a_a_dom', $change) && is_numeric($props['a_a_dom']) && $this->authenticated_user['a_admin_domains'] >= 2) {
	    $to_change[]	= 'a_admin_domains = '.(max(0, min(intval($props['a_a_dom']), $this->authenticated_user['a_admin_domains'])));
	}
	if(in_array('a_a_usr', $change) && is_numeric($props['a_a_usr']) && $this->authenticated_user['a_admin_user'] >= 2) {
	    $to_change[]	= 'a_admin_user = '.(max(0, min(intval($props['a_a_usr']), $this->authenticated_user['a_admin_user'])));
	}
	if(in_array('a_super', $change) && is_numeric($props['a_super']) && $this->authenticated_user['a_super'] >= 2) {
	    $to_change[]	= 'a_super = '.(max(0, min(intval($props['a_super']), $this->authenticated_user['a_super'])));
	}

	if($this->authenticated_user['a_super'] == 0 && in_array(array('alias', 'reg_exp'), $change)) {
	    $result = mysql_query('SELECT count(*) AS anzahl, SUM(max_alias) AS nr_alias, SUM(max_regexp) AS nr_regexp'
			.' FROM '.$cfg['tablenames']['user']
			.' WHERE FIND_IN_SET(mbox, "'.$aux_tmp.'")');
	    if(mysql_num_rows($result) > 0) {
		$tmp = mysql_fetch_assoc($result);
		mysql_free_result($result);
		mysql_query('UPDATE '.$cfg['tablenames']['user']
			.' SET max_alias='.($this->current_user['max_alias']-$tmp['anzahl']*$props['max_alias']+$tmp['nr_alias']).', max_regexp='.($this->current_user['max_regexp']-$tmp['anzahl']*intval($props['max_regexp'])+$tmp['nr_regexp'])
			.' WHERE mbox="'.$this->current_user['mbox'].'" LIMIT 1');
		unset($tmp);
	    }
	}
	if(isset($to_change) && is_array($to_change))
	    mysql_query('UPDATE '.$cfg['tablenames']['user'].' SET '.implode(',', $to_change).' WHERE FIND_IN_SET(mbox, "'.$aux_tmp.'") LIMIT '.count($aux_tmp));
	unset($to_change);

	if(in_array('quota', $change)) {
	    if($this->authenticated_user['a_super'] == 0) {
		foreach($aux_tmp as $key=>$user) {
		    if($user != '') {
			if(hsys_getMaxQuota($user) == intval($props['quota']))
			    continue;
			if(hsys_getMaxQuota($user) != 'NOT-SET')
			    $add_quota += intval($props['quota']) - hsys_getMaxQuota($user);
		    }
		}
		if(isset($add_quota) && hsys_getMaxQuota($this->current_user['mbox']) != 'NOT-SET') {
		    $cyr->setmbquota(cyrus_format_user($this->current_user['mbox']), hsys_getMaxQuota($this->current_user['mbox'])-$add_quota);
		    info(sprintf(txt('78'), (hsys_getMaxQuota($this->current_user['mbox'])-$add_quota)));
		}
	    }
	    reset($aux_tmp);
	    foreach($aux_tmp as $key=>$user) {
		if($user != '') {
		    $result = $cyr->setmbquota(cyrus_format_user($user), intval($props['quota']));
		    if($result) {
			error(var_export($result, true));
		    }
		}
	    }
	    unset($key); unset($value);
	}

	if(in_array('mbox', $change)) {
	    if($cyr->renamemb(cyrus_format_user($aux_tmp['0']), cyrus_format_user($props['mbox']))) {
		mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['user'].' SET mbox = "'.$props['mbox'].'" WHERE mbox = "'.$aux_tmp['0'].'" LIMIT 1');
		mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['domains'].' SET owner = "'.$props['mbox'].'" WHERE owner = "'.$aux_tmp['0'].'"');
		mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['domains'].' SET a_admin = REPLACE(a_admin, "'.$aux_tmp['0'].'", "'.$props['mbox'].'") WHERE a_admin LIKE "%'.$aux_tmp['0'].'%"');
		mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual'].' SET dest = REPLACE(dest, "'.$aux_tmp['0'].'", "'.$props['mbox'].'"), neu = 1 WHERE dest REGEXP "'.$aux_tmp['0'].'[^@]{1,}" OR dest LIKE "%'.$aux_tmp['0'].'"');
		mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual'].' SET owner = "'.$props['mbox'].'" WHERE owner = "'.$aux_tmp['0'].'"');
		mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual_regexp'].' SET dest = REPLACE(dest, "'.$aux_tmp['0'].'", "'.$props['mbox'].'"), neu = 1 WHERE dest REGEXP "'.$aux_tmp['0'].'[^@]{1,}" OR dest LIKE "%'.$aux_tmp['0'].'"');
		mysql_unbuffered_query('UPDATE LOW_PRIORITY '.$cfg['tablenames']['virtual_regexp'].' SET owner = "'.$props['mbox'].'" WHERE owner = "'.$aux_tmp['0'].'"');
	    }
	    else {
		error($cyr->error_msg.'<br />'.txt('94'));
	    }
	}

	if(isset($_SESSION['paten']) && in_array(array('mbox', 'pate'), $change)) {
	    unset($_SESSION['paten']);	// again: inefficient, but maybe we come up with something more elegant
	}

	return true;
    }

    function mailbox_delete($mboxnames) {
	global $cfg;
	global $cyr;

	$aux_tmp = mysql_real_escape_string(implode(',', $this->mailbox_filter_manipulable($this->authenticated_user['mbox'], $mboxnames)));

	if($this->authenticated_user['a_super'] == 0) {
	    $result = mysql_query('SELECT SUM(max_alias) AS nr_alias, SUM(max_regexp) AS nr_regexp'
				.' FROM '.$cfg['tablenames']['user']
				.' WHERE FIND_IN_SET(mbox, "'.$aux_tmp.'")');
	    if(mysql_num_rows($result) > 0) {
		$tmp = mysql_fetch_assoc($result);
		mysql_free_result($result);
	    }
	}
	// delete from cyrus
	$add_quota = 0;
	foreach($mboxnames as $key=>$user) {
	    if($user != '') {
		if($this->authenticated_user['a_super'] == 0) {
		    if(hsys_getMaxQuota($user) != 'NOT-SET')
			$toadd = hsys_getMaxQuota($user);
		}
		$result = $cyr->deletemb(cyrus_format_user($user));
		if($result) {
		    $this->error[]	= var_export($result, true);
		    $cyr->setmbquota(cyrus_format_user($this->current_user['mbox']), hsys_getMaxQuota($this->current_user['mbox'])+$add_quota);
		    break;
		}
		else {
		    $add_quota += $toadd;
		}
	    }
	}

	// virtual
	mysql_query('DELETE FROM '.$cfg['tablenames']['virtual'].' WHERE FIND_IN_SET(owner, "'.$aux_tmp.'")');
	mysql_query('UPDATE '.$cfg['tablenames']['virtual'].' SET active=0, neu=1 WHERE FIND_IN_SET(dest, "'.$aux_tmp.'")');
	// virtual.regexp
	mysql_query('DELETE FROM '.$cfg['tablenames']['virtual_regexp'].' WHERE FIND_IN_SET(owner, "'.$aux_tmp.'")');
	mysql_query('UPDATE '.$cfg['tablenames']['virtual_regexp'].' SET active=0, neu=1 WHERE FIND_IN_SET(dest, "'.$aux_tmp.'")');
	// domain (if the one to be deleted owns domains, the deletor will inherit them)
	mysql_query('UPDATE '.$cfg['tablenames']['domains'].' SET owner="'.$this->current_user['mbox'].'" WHERE FIND_IN_SET(owner, "'.$aux_tmp.'")');
	// user
	mysql_query('DELETE FROM '.$cfg['tablenames']['user'].' WHERE FIND_IN_SET(mbox, "'.$aux_tmp.'")');
	if($this->authenticated_user['a_super'] == 0 && isset($tmp['nr_alias'])) {
	    mysql_unbuffered_query('UPDATE '.$cfg['tablenames']['user'].' SET max_alias='.($this->current_user['max_alias']+$tmp['nr_alias']).', max_regexp='.($this->current_user['max_regexp']+$tmp['nr_regexp'])
			.' WHERE mbox="'.$this->current_user['mbox'].'" LIMIT 1');
	    unset($tmp);
	}
	// patenkinder (will be inherited by the one deleting)
	mysql_unbuffered_query('UPDATE '.$cfg['tablenames']['user']
		.' SET pate="'.$this->current_user['mbox'].'"'
		.' WHERE FIND_IN_SET(pate, "'.$aux_tmp.'")');

	info(txt('75').$aux_tmp.'. ');
	if($this->authenticated_user['a_super'] == 0
		&& hsys_getMaxQuota($this->current_user['mbox']) != 'NOT-SET') {
	    $cyr->setmbquota(cyrus_format_user($this->current_user['mbox']), hsys_getMaxQuota($this->current_user['mbox'])+$add_quota);
	    info(sprintf(txt('76'), (hsys_getMaxQuota($this->current_user['mbox'])+$add_quota)));
	}
	unset($user); unset($aux_tmp); unset($key);
	if(isset($_SESSION['paten'])) unset($_SESSION['paten']); // inefficient, but maybe we come up with something more elegant

	return true;
    }

    function mailbox_toggle_active($mboxnames) {
	global $cfg;

	$tobechanged = $this->mailbox_filter_manipulable($this->current_user['mbox'], $mboxnames);

	if(count($tobechanged) > 0) {
	    mysql_query('UPDATE '.$cfg['tablenames']['user']
			    .' SET active = NOT active'
			    .' WHERE FIND_IN_SET(mbox, "'.mysql_real_escape_string(implode(',', $tobechanged)).'")'
			    .' LIMIT '.count($tobechanged));

	    if(mysql_affected_rows() < 1) {
		if(mysql_errno() != 0) {
		    $this->error[]	= mysql_error();
		}
	    }
	    else {
		return true;
	    }
	}

	return false;
    }

}
?>