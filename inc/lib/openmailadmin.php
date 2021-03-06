<?php
/**
 * This class collects all methods of Openmailadmin, except for the view
 * and data storage.
 */
class openmailadmin
{
	public	$current_user;		// What user do we edit/display currently?
	public	$authenticated_user;	// What user did log in?

	private	$db;
	private $validator;
	protected	$ErrorHandler;

	private	$tablenames;
	private	$cfg;
	public	$imap;

	// "alias" == "local part"
	const	regex_valid_alias	= '(?=^.{1,64}$)[a-z0-9]+(?:(?<![!$+\-_.])[!$+\-_.][a-z0-9]+)*';
	const	regex_valid_email	= '[a-z0-9]+(?:(?<![!$+\-_.])[!$+\-_.][a-z0-9]+)*@(?:(?:(?![.-])[a-z0-9\-]{1,63}(?<!-)\.?)+(?:(?<!\.)\.[a-z]{2,}))';
	const	regex_valid_domain	= '(?=^.{1,254}$)(?:^localhost$)|(?:^(?:(?![.-])[a-z0-9\-]{1,63}(?<!-)\.?)+(?:(?<!\.)\.[a-z]{2,})$)';

	function __construct(ADOConnection $adodb_handler, array $tablenames, array $cfg, IMAP_Administrator $imap) {
		$this->db		= $adodb_handler;
		$this->tablenames	= $tablenames;
		$this->cfg		= $cfg;
		$this->imap		= $imap;
		$this->validator	= new InputValidatorSuite($this, $cfg);
		$this->ErrorHandler	= ErrorHandler::getInstance();
	}

	/*
	 * This procedure simply executes every command stored in the array.
	 */
	private function rollback($what) {
		if(is_array($what)) {
			foreach($what as $cmd) {
				eval($cmd.';');
			}
		} else {
			eval($what.';');
		}
	}

	/*
	 * Returns a long list with every active mailbox.
	 */
	private function get_mailbox_names() {
		$tmp	= array();

		$result = $this->db->Execute('SELECT mbox FROM '.$this->tablenames['user'].' WHERE active = 1');
		while(!$result->EOF) {
			if($result->fields['mbox'] != '')
				$tmp[] = $result->fields['mbox'];
			$result->MoveNext();
		}
		return $tmp;
	}

	/*
	 * As the name says, returns an array containing the entire row
	 * of the "user" table belonging to that mailbox.
	 */
	public function get_user_row($mailbox) {
		return $this->db->GetRow('SELECT * FROM '.$this->tablenames['user'].' WHERE mbox='.$this->db->qstr($mailbox));
	}

	/*
	 * Accepts a string containing possible destination for an email-address,
	 * selects valid destinations and returns them.
	 */
	public function get_valid_destinations($possible) {
		// Define what addresses we will accept.
		$pattern  = openmailadmin::regex_valid_email;
		$pattern .= '|'.$this->current_user->mbox.'|'.txt('5').'|'.strtolower(txt('5'));
		if($this->cfg['allow_mbox_as_target']) {
			$mailboxes = &$this->get_mailbox_names();
			if(count($mailboxes) > 0) {
				$mailboxes = array_map('preg_quote', $mailboxes);
				$pattern .= '|'.implode('|', $mailboxes);
			}
		} else if($this->cfg['allow_wcyr_as_target']) {
			$pattern .= '|[a-z]{2,}[0-9]{4}';
		}

		// Get valid destinations.
		if(preg_match_all('/'.$pattern.'/iu', $possible, $matched)) {
			if(is_array($matched[0])) {
				// Replace every occurence of 'mailbox' with the correct name.
				array_walk($matched[0],
					create_function('&$item,$index',
							'if(strtolower($item) == \''.strtolower(txt('5')).'\') $item = \''.$this->current_user->mbox.'\';'
							));
				return $matched[0];
			}
		}
		return array();
	}

	/*
	 * Returns an array containing all domains the user may choose from.
	 */
	public function get_domain_set($user, $categories, $cache = true) {
		$cat = '';
		$poss_dom = array();

		if($cache && isset($_SESSION['cache']['getDomainSet'][$user][$categories])) {
			return $_SESSION['cache']['getDomainSet'][$user][$categories];
		} else {
			foreach(explode(',', $categories) as $value) {
				$poss_dom[] = trim($value);
				$cat .= ' OR categories LIKE '.$this->db->qstr('%'.trim($value).'%');
			}
			$dom = array();
			$result = $this->db->Execute('SELECT domain FROM '.$this->tablenames['domains']
				.' WHERE owner='.$this->db->qstr($user).' OR a_admin LIKE '.$this->db->qstr('%'.$user.'%').' OR '.db_find_in_set($this->db, 'domain', $poss_dom).$cat);
			if(!$result === false) {
				while(!$result->EOF) {
					$dom[] = idn_to_utf8($result->fields['domain']);
					$result->MoveNext();
				}
			}

			$_SESSION['cache']['getDomainSet'][$user][$categories] = $dom;
			return $_SESSION['cache']['getDomainSet'][$user][$categories];
		}
	}

	/*
	 * Checks whether a user is a descendant of another user.
	 * (Unfortunately, PHP does not support inline functions.)
	 */
	public function user_is_descendant($child, $parent, $levels = 7, $cache = array()) {
		// initialize cache
		if(!isset($_SESSION['cache']['IsDescendant'])) {
			$_SESSION['cache']['IsDescendant'] = array();
		}

		if(trim($child) == '' || trim($parent) == '')
			return false;
		if(isset($_SESSION['cache']['IsDescendant'][$parent][$child]))
			return $_SESSION['cache']['IsDescendant'][$parent][$child];

		if($child == $parent) {
			$rec = true;
		} else if($levels <= 0 ) {
			$rec = false;
		} else {
			$inter = $this->db->GetOne('SELECT pate FROM '.$this->tablenames['user'].' WHERE mbox='.$this->db->qstr($child));
			if($inter === false) {
				$rec = false;
			} else {
				if($inter == $parent) {
					$rec = true;
				} else if(in_array($inter, $cache)) {	// avoids loops
					$rec = false;
				} else {
					$rec = $this->user_is_descendant($inter, $parent, $levels--, array_merge($cache, array($inter)));
				}
			}
		}
		$_SESSION['cache']['IsDescendant'][$parent][$child] = $rec;
		return $rec;
	}

	/*
	 * How many aliases the user has already in use?
	 * Does cache, but not session-wide.
	 */
	public function user_get_used_alias($username) {
		static $used = array();
		if(!isset($used[$username])) {
			$used[$username] = $this->db->GetOne('SELECT COUNT(*) FROM '.$this->tablenames['virtual'].' WHERE owner='.$this->db->qstr($username));
		}
		return $used[$username];
	}
	/*
	 * How many regexp-addresses the user has already in use?
	 * Does cache, but not session-wide.
	 */
	public function user_get_used_regexp($username) {
		static $used = array();
		if(!isset($used[$username])) {
			$used[$username] = $this->db->GetOne('SELECT COUNT(*) FROM '.$this->tablenames['virtual_regexp'].' WHERE owner='.$this->db->qstr($username));
		}
		return $used[$username];
	}

	/*
	 * These just count how many elements have been assigned to that given user.
	 */
	public function user_get_number_mailboxes($username) {
		if(!isset($_SESSION['cache']['n_Mailboxes'][$username]['mailboxes'])) {
			$tmp = $this->db->GetOne('SELECT COUNT(*) FROM '.$this->tablenames['user'].' WHERE pate='.$this->db->qstr($username));
			$_SESSION['cache']['n_Mailboxes'][$username]['mailboxes'] = $tmp;
		}
		return $_SESSION['cache']['n_Mailboxes'][$username]['mailboxes'];
	}
	/*
	 * These just count how many elements have been assigned to that given user.
	 */
	public function user_get_number_domains($username) {
		if(!isset($_SESSION['cache']['n_Domains'][$username]['domains'])) {
			$tmp = $this->db->GetOne('SELECT COUNT(*) FROM '.$this->tablenames['domains'].' WHERE owner='.$this->db->qstr($username));
			$_SESSION['cache']['n_Domains'][$username]['domains'] = $tmp;
		}
		return $_SESSION['cache']['n_Domains'][$username]['domains'];
	}
	/*
	 * In case you have changed something about domains...
	 */
	private function user_invalidate_domain_sets() {
		if(isset($_SESSION['cache']['getDomainSet'])) {
			unset($_SESSION['cache']['getDomainSet']);
		}
	}

/* ******************************* addresses ******************************** */
	/*
	 * Returns a long list with all addresses (the virtuals' table).
	 */
	public function get_addresses() {
		$alias = array();

		$result = $this->db->SelectLimit('SELECT address, dest, active'
					.' FROM '.$this->tablenames['virtual']
					.' WHERE owner='.$this->db->qstr($this->current_user->mbox).$_SESSION['filter']['str']['address']
					.' ORDER BY address, dest',
					$_SESSION['limit'], $_SESSION['offset']['address']);
		if(!$result === false) {
			while(!$result->EOF) {
				$row	= $result->fields;
				// explode all destinations (as there may be many)
				$dest = array();
				foreach(explode(',', $row['dest']) as $value) {
					$value = trim($value);
					// replace the current user's name with "mailbox"
					if($value == $this->current_user->mbox)
						$dest[] = txt('5');
					else
						$dest[] = $value;
				}
				sort($dest);
				$row['dest'] = $dest;
				// detect where the "@" is
				$at = strpos($row['address'], '@');
				//turn the alias of catchalls to a star
				if($at == 0)
					$row['alias'] = '*';
				else
					$row['alias'] = substr($row['address'], 0, $at);
				$row['domain'] = idn_to_utf8(substr($row['address'], $at+1));
				// add the current entry to our list of aliases
				$alias[] = $row;
				$result->MoveNext();
			}
			usort($alias, create_function('$a, $b', 'return ($a["domain"] == $b["domain"] ? strcmp($a["alias"], $b["alias"]) : strcmp($a["domain"], $b["domain"]));'));
		}
		return $alias;
	}

	/*
	 * Creates a new email-address.
	 */
	public function address_create($alias, $domain, $arr_destinations) {
		$domain = idn_to_ascii($domain);
		// May the user create another address?
		if($this->current_user->used_alias < $this->current_user->max_alias
		   || $this->authenticated_user->a_super >= 1) {
			// If he did choose a catchall, may he create such an address?
			if($alias == '*' && $this->cfg['address']['allow_catchall']) {
				if($this->cfg['address']['restrict_catchall']) {
					// If either the current or the authenticated user is
					// owner of that given domain, we can permit creation of that catchall.
					$result = $this->db->GetOne('SELECT domain FROM '.$this->tablenames['domains']
								.' WHERE domain='.$this->db->qstr($domain)
								.' AND (owner='.$this->db->qstr($this->current_user->mbox).' OR owner='.$this->db->qstr($this->authenticated_user->mbox).')');
					if($result === false) {			// negative check!
						$this->ErrorHandler->add_error(txt('16'));
						return false;
					}
					// There shall be no local part in the address. That is characteristic for catchalls.
					$alias = '';
				}
			}
			// Will his new address be a valid one?
			else if(! preg_match('/^'.openmailadmin::regex_valid_alias.'$/i', $alias)) {
				$this->ErrorHandler->add_error(txt('13'));
				return false;
			}
			// Restrict amount of possible destinations.
			$max = $alias == '' ? $this->cfg['address']['max_dest_p_catchall'] : $this->cfg['address']['max_dest_p_address'];
			if(count($arr_destinations) > $max) {
				$this->ErrorHandler->add_error(sprintf(txt('136'), $max));
				return false;
			}
			// Finally, create that address.
			$this->db->Execute('INSERT INTO '.$this->tablenames['virtual'].' (address, dest, owner) VALUES (?, ?, ?)',
						array(strtolower($alias.'@'.$domain), implode(',', $arr_destinations), $this->current_user->mbox));
			if($this->db->Affected_Rows() < 1) {
				$this->ErrorHandler->add_error(txt('133'));
			} else {
				$this->current_user->used_alias++;
				return true;
			}
		} else {
			$this->ErrorHandler->add_error(txt('14'));
		}
		return false;
	}
	/*
	 * Deletes the given addresses if they belong to the current user.
	 */
	public function address_delete($arr_addresses) {
		$this->db->Execute('DELETE FROM '.$this->tablenames['virtual']
				.' WHERE owner='.$this->db->qstr($this->current_user->mbox)
				.' AND '.db_find_in_set($this->db, 'address', $arr_addresses));
		if($this->db->Affected_Rows() < 1) {
			if($this->db->ErrorNo() != 0) {
				$this->ErrorHandler->add_error($this->db->ErrorMsg());
			}
		} else {
			array_walk($arr_addresses,
					create_function('&$item,$index',
							'$parts = explode(\'@\', $item); $item = implode(\'@\', array($parts[0], idn_to_utf8($parts[1])));'
							));
			$this->ErrorHandler->add_info(sprintf(txt('15'), implode(',', $arr_addresses)));
			$this->current_user->used_alias -= $this->db->Affected_Rows();
			return true;
		}

		return false;
	}
	/*
	 * Changes the destination of the given addresses if they belong to the current user.
	 */
	public function address_change_destination($arr_addresses, $arr_destinations) {
		$max = $this->cfg['address']['max_dest_p_address'];
		foreach($arr_addresses as $addr) {
			if($addr{0} == '@') { // catchall!
				$max = $this->cfg['address']['max_dest_p_catchall'];
				break;
			}
		}
		if(count($arr_destinations) > $max) {
			$this->ErrorHandler->add_error(sprintf(txt('136'), $max));
			return false;
		}
		$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET dest='.$this->db->qstr(implode(',', $arr_destinations)).', neu=1'
				.' WHERE owner='.$this->db->qstr($this->current_user->mbox)
				.' AND '.db_find_in_set($this->db, 'address', $arr_addresses));
		if($this->db->Affected_Rows() < 1) {
			if($this->db->ErrorNo() != 0) {
				$this->ErrorHandler->add_error($this->db->ErrorMsg());
			}
		} else {
			return true;
		}
		return false;
	}
	/*
	 * Toggles the 'active'-flag of a set of addresses  of the current user
	 * and thus sets inactive ones to active ones and vice versa.
	 */
	public function address_toggle_active($arr_addresses) {
		$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET active=NOT active, neu=1'
				.' WHERE owner='.$this->db->qstr($this->current_user->mbox)
				.' AND '.db_find_in_set($this->db, 'address', $arr_addresses));
		if($this->db->Affected_Rows() < 1) {
			if($this->db->ErrorNo() != 0) {
				$this->ErrorHandler->add_error($this->db->ErrorMsg());
			}
		} else {
			return true;
		}

		return false;
	}

/* ******************************* domains ********************************** */
	public $editable_domains;	// How many domains can the current user change?
	/*
	 * Returns a long list with all domains (from table 'domains').
	 */
	public function get_domains() {
		$this->editable_domains = 0;
		$domains = array();

		$query  = 'SELECT * FROM '.$this->tablenames['domains'];
		if($this->authenticated_user->a_super > 0) {
			$query .= ' WHERE 1=1 '.$_SESSION['filter']['str']['domain'];
		} else {
			$query .= ' WHERE (owner='.$this->db->qstr($this->current_user->mbox).' or a_admin LIKE '.$this->db->qstr('%'.$this->current_user->mbox.'%').')'
				 .$_SESSION['filter']['str']['domain'];
		}
		$query .= ' ORDER BY owner, length(a_admin), domain';

		$result = $this->db->SelectLimit($query, $_SESSION['limit'], $_SESSION['offset']['mbox']);
		if(!$result === false) {
			while(!$result->EOF) {
				$row	= $result->fields;
				if($row['owner'] == $this->authenticated_user->mbox
				   || find_in_set($this->authenticated_user->mbox, $row['a_admin'])) {
					$row['selectable']	= true;
					++$this->editable_domains;
				} else {
					$row['selectable']	= false;
				}
				$row['domain'] = idn_to_utf8($row['domain']);
				$domains[] = $row;
				$result->MoveNext();
			}
		}

		$this->current_user->n_domains = $this->user_get_number_domains($this->current_user->mbox);

		return $domains;
	}
	/**
	 * May the new user only select from domains which have been assigned to
	 * the reference user? If so, return true.
	 *
	 * @param	reference	Instance of User
	 * @param	tobechecked	Mailbox-name.
	 */
	public function domain_check(User $reference, $tobechecked, $domain_key) {
		if(!isset($reference->domain_set)) {
			$reference->domain_set = $this->get_domain_set($reference->mbox, $reference->domains);
		}
		// new domain-key must not lead to more domains than the user already has to choose from
		// A = Domains the new user will be able to choose from.
		$dom_a = $this->get_domain_set($tobechecked, $domain_key, false);
		// B = Domains the creator may choose from (that is $reference['domain_set'])?
		// Okay, if A is part of B. (Thus, no additional domains are added for user "A".)
		// Indication: A <= B
		if(count($dom_a) == 0) {
			// This will be only a warning.
			$this->ErrorHandler->add_error(txt('80'));
		} else if(count($dom_a) > count($reference->domain_set)
			   && count(array_diff($dom_a, $reference->domain_set)) > 0) {
			// A could have domains which the reference cannot access.
			return false;
		}

		return true;
	}
	/*
	 * Adds a new domain into the corresponding table.
	 * Categories are for grouping domains.
	 */
	public function domain_add($domain, $props) {
		$domain = idn_to_ascii($domain);
		$props['domain'] = $domain;
		if(!$this->validator->validate($props, array('domain', 'categories', 'owner', 'a_admin'))) {
			return false;
		}

		if(!stristr($props['categories'], 'all'))
			$props['categories'] = 'all,'.$props['categories'];
		if(!stristr($props['a_admin'], $this->current_user->mbox))
			$props['a_admin'] .= ','.$this->current_user->mbox;

		$this->db->Execute('INSERT INTO '.$this->tablenames['domains'].' (domain, categories, owner, a_admin) VALUES (?, ?, ?, ?)',
				array($domain, $props['categories'], $props['owner'], $props['a_admin']));
		if($this->db->Affected_Rows() < 1) {
			$this->ErrorHandler->add_error(txt('134'));
		} else {
			$this->user_invalidate_domain_sets();
			return true;
		}

		return false;
	}
	/*
	 * Not only removes the given domains by their ids,
	 * it deactivates every address which ends in that domain.
	 */
	public function domain_remove($domains) {
		// We need the old domain name later...
		if(is_array($domains) && count($domains) > 0) {
			if($this->cfg['admins_delete_domains']) {
				$result = $this->db->SelectLimit('SELECT ID, domain'
					.' FROM '.$this->tablenames['domains']
					.' WHERE (owner='.$this->db->qstr($this->authenticated_user->mbox).' OR a_admin LIKE '.$this->db->qstr('%'.$this->authenticated_user->mbox.'%').') AND '.db_find_in_set($this->db, 'ID', $domains),
					count($domains));
			} else {
				$result = $this->db->SelectLimit('SELECT ID, domain'
					.' FROM '.$this->tablenames['domains']
					.' WHERE owner='.$this->db->qstr($this->authenticated_user->mbox).' AND '.db_find_in_set($this->db, 'ID', $domains),
					count($domains));
			}
			if(!$result === false) {
				while(!$result->EOF) {
					$del_ID[] = $result->fields['ID'];
					$del_nm[] = idn_to_utf8($result->fields['domain']);
					$result->MoveNext();
				}
				if(isset($del_ID)) {
					$this->db->Execute('DELETE FROM '.$this->tablenames['domains'].' WHERE '.db_find_in_set($this->db, 'ID', $del_ID));
					if($this->db->Affected_Rows() < 1) {
						if($this->db->ErrorNo() != 0) {
							$this->ErrorHandler->add_error($this->db->ErrorMsg());
						}
					} else {
						$this->ErrorHandler->add_info(txt('52').'<br />'.implode(', ', $del_nm));
						// We better deactivate all aliases containing that domain, so users can see the domain was deleted.
						foreach($del_nm as $domainname) {
							$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET active = 0, neu = 1 WHERE address LIKE '.$this->db->qstr('%'.idn_to_ascii($domainname)));
						}
						// We can't do such on REGEXP addresses: They may catch more than the given domains.
						$this->user_invalidate_domain_sets();
						return true;
					}
				} else {
					$this->ErrorHandler->add_error(txt('16'));
				}
			} else {
				$this->ErrorHandler->add_error(txt('16'));
			}
		} else {
			$this->ErrorHandler->add_error(txt('11'));
		}

		return false;
	}
	/*
	 * Every parameter is an array. $domains contains IDs.
	 */
	public function domain_change($domains, $change, $data) {
		$toc = array();		// to be changed

		if(!$this->validator->validate($data, $change)) {
			return false;
		}

		if(!is_array($change)) {
			$this->ErrorHandler->add_error(txt('53'));
			return false;
		}
		if($this->cfg['admins_delete_domains'] && in_array('owner', $change))
			$toc[] = 'owner='.$this->db->qstr($data['owner']);
		if(in_array('a_admin', $change))
			$toc[] = 'a_admin='.$this->db->qstr($data['a_admin']);
		if(in_array('categories', $change))
			$toc[] = 'categories='.$this->db->qstr($data['categories']);
		if(count($toc) > 0) {
			$this->db->Execute('UPDATE '.$this->tablenames['domains']
				.' SET '.implode(',', $toc)
				.' WHERE (owner='.$this->db->qstr($this->authenticated_user->mbox).' or a_admin LIKE '.$this->db->qstr('%'.$this->authenticated_user->mbox.'%').') AND '.db_find_in_set($this->db, 'ID', $domains));
			if($this->db->Affected_Rows() < 1) {
				if($this->db->ErrorNo() != 0) {
					$this->ErrorHandler->add_error($this->db->ErrorMsg());
				} else {
					$this->ErrorHandler->add_error(txt('16'));
				}
			}
		}
		// changing ownership if $this->cfg['admins_delete_domains'] == false
		if(!$this->cfg['admins_delete_domains'] && in_array('owner', $change)) {
			$this->db->Execute('UPDATE '.$this->tablenames['domains']
				.' SET owner='.$this->db->qstr($data['owner'])
				.' WHERE owner='.$this->db->qstr($this->authenticated_user->mbox).' AND '.db_find_in_set($this->db, 'ID', $domains));
		}
		$this->user_invalidate_domain_sets();
		// No domain be renamed?
		if(! in_array('domain', $change)) {
			return true;
		}
		// Otherwise (and if only one) try adapting older addresses.
		if(count($domains) == 1) {
			// Grep the old name, we will need it later for replacement.
			$domain = $this->db->GetRow('SELECT ID, domain AS name FROM '.$this->tablenames['domains'].' WHERE ID = '.$this->db->qstr($domains[0]).' AND (owner='.$this->db->qstr($this->authenticated_user->mbox).' or a_admin LIKE '.$this->db->qstr('%'.$this->authenticated_user->mbox.'%').')');
			if(!$domain === false) {
				// First, update the name. (Corresponding field is marked as unique, therefore we will not receive doublettes.)...
				$this->db->Execute('UPDATE '.$this->tablenames['domains'].' SET domain = '.$this->db->qstr($data['domain']).' WHERE ID = '.$domain['ID']);
				// ... and then, change every old address.
				if($this->db->Affected_Rows() == 1) {
					// address
					$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET neu = 1, address = REPLACE(address, '.$this->db->qstr('@'.$domain['name']).', '.$this->db->qstr('@'.$data['domain']).') WHERE address LIKE '.$this->db->qstr('%@'.$domain['name']));
					// dest
					$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET neu = 1, dest = REPLACE(dest, '.$this->db->qstr('@'.$domain['name']).', '.$this->db->qstr('@'.$data['domain']).') WHERE dest LIKE '.$this->db->qstr('%@'.$domain['name'].'%'));
					$this->db->Execute('UPDATE '.$this->tablenames['virtual_regexp'].' SET neu = 1, dest = REPLACE(dest, '.$this->db->qstr('@'.$domain['name']).', '.$this->db->qstr('@'.$data['domain']).') WHERE dest LIKE '.$this->db->qstr('%@'.$domain['name'].'%'));
					// canonical
					$this->db->Execute('UPDATE '.$this->tablenames['user'].' SET canonical = REPLACE(canonical, '.$this->db->qstr('@'.$domain['name']).', '.$this->db->qstr('@'.$data['domain']).') WHERE canonical LIKE '.$this->db->qstr('%@'.$domain['name']));
				} else {
					$this->ErrorHandler->add_error($this->db->ErrorMsg());
				}
				return true;
			} else {
				$this->ErrorHandler->add_error(txt('91'));
			}
		} else {
			$this->ErrorHandler->add_error(txt('53'));
		}

		return false;
	}

/* ******************************* passwords ******************************** */
	/**
	 * Changes the current user's password.
	 * This requires the former password for authentication if current user and
	 * authenticated user are the same.
	 */
	public function user_change_password($new, $new_repeat, $old_passwd = null) {
		if($this->current_user->mbox == $this->authenticated_user->mbox
		   && !is_null($old_passwd)
		   && !$this->current_user->password->equals($old_passwd)) {
			$this->ErrorHandler->add_error(txt('45'));
		} else if($new != $new_repeat) {
			$this->ErrorHandler->add_error(txt('44'));
		} else if(strlen($new) < $this->cfg['passwd']['min_length']
			|| strlen($new) > $this->cfg['passwd']['max_length']) {
			$this->ErrorHandler->add_error(sprintf(txt('46'), $this->cfg['passwd']['min_length'], $this->cfg['passwd']['max_length']));
		} else {
			// Warn about insecure passwords, but let them pass.
			if(!Password::is_secure($new)) {
				$this->ErrorHandler->add_error(txt('47'));
			}
			if($this->current_user->password->set($new)) {
				$this->ErrorHandler->add_info(txt('48'));
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
	public function get_regexp($match_against = null) {
		$regexp = array();

		$result = $this->db->SelectLimit('SELECT * FROM '.$this->tablenames['virtual_regexp']
				.' WHERE owner='.$this->db->qstr($this->current_user->mbox).$_SESSION['filter']['str']['regexp']
				.' ORDER BY dest',
				$_SESSION['limit'], $_SESSION['offset']['regexp']);
		if(!$result === false) {
			while(!$result->EOF) {
				$row	= $result->fields;
				// if ordered, check whether expression matches probe address
				if(!is_null($match_against)
				   && @preg_match($row['reg_exp'], $match_against)) {
					$row['matching']	= true;
				} else {
					$row['matching']	= false;
				}
				// explode all destinations (as there may be many)
				$dest = array();
				foreach(explode(',', $row['dest']) as $value) {
					$value = trim($value);
					// replace the current user's name with "mailbox"
					if($value == $this->current_user->mbox)
					$dest[] = txt('5');
					else
					$dest[] = $value;
				}
				sort($dest);
				$row['dest'] = $dest;
				// add the current entry to our list of aliases
				$regexp[] = $row;
				$result->MoveNext();
			}
		}
		return $regexp;
	}
	/*
	 * Creates a new regexp-address.
	 */
	public function regexp_create($regexp, $arr_destinations) {
		// some dull checks;
		// if someone knows how to find out whether an string is a valid regexp -> write me please
		if($regexp == '' || $regexp{0} != '/') {
			$this->ErrorHandler->add_error(txt('127'));
			return false;
		}

		if($this->current_user->used_regexp < $this->current_user->max_regexp
		   || $this->authenticated_user->a_super > 0) {
			$this->db->Execute('INSERT INTO '.$this->tablenames['virtual_regexp'].' (reg_exp, dest, owner) VALUES (?, ?, ?)',
				array($regexp, implode(',', $arr_destinations), $this->current_user->mbox));
			if($this->db->Affected_Rows() < 1) {
				if($this->db->ErrorNo() != 0) {
					$this->ErrorHandler->add_error(txt('133'));
				}
			} else {
				$this->current_user->used_regexp++;
				return true;
			}
		} else {
			$this->ErrorHandler->add_error(txt('31'));
		}

		return false;
	}
	/*
	 * Deletes the given regular expressions if they belong to the current user.
	 */
	public function regexp_delete($arr_regexp_ids) {
		$this->db->Execute('DELETE FROM '.$this->tablenames['virtual_regexp']
				.' WHERE owner='.$this->db->qstr($this->current_user->mbox)
				.' AND '.db_find_in_set($this->db, 'ID', $arr_regexp_ids));
		if($this->db->Affected_Rows() < 1) {
			if($this->db->ErrorNo() != 0) {
				$this->ErrorHandler->add_error($this->db->ErrorMsg());
			}
		} else {
			$this->ErrorHandler->add_info(txt('32'));
			$this->current_user->used_regexp -= $this->db->Affected_Rows();
			return true;
		}

		return false;
	}
	/*
	 * See "address_change_destination".
	 */
	public function regexp_change_destination($arr_regexp_ids, $arr_destinations) {
		$this->db->Execute('UPDATE '.$this->tablenames['virtual_regexp'].' SET dest='.$this->db->qstr(implode(',', $arr_destinations)).', neu = 1'
				.' WHERE owner='.$this->db->qstr($this->current_user->mbox)
				.' AND '.db_find_in_set($this->db, 'ID', $arr_regexp_ids));
		if($this->db->Affected_Rows() < 1) {
			if($this->db->ErrorNo() != 0) {
				$this->ErrorHandler->add_error($this->db->ErrorMsg());
			}
		} else {
			return true;
		}

		return false;
	}
	/*
	 * See "address_toggle_active".
	 */
	public function regexp_toggle_active($arr_regexp_ids) {
		$this->db->Execute('UPDATE '.$this->tablenames['virtual_regexp'].' SET active = NOT active, neu = 1'
				.' WHERE owner='.$this->db->qstr($this->current_user->mbox)
				.' AND '.db_find_in_set($this->db, 'ID', $arr_regexp_ids));
		if($this->db->Affected_Rows() < 1) {
			if($this->db->ErrorNo() != 0) {
				$this->ErrorHandler->add_error($this->db->ErrorMsg());
			}
		} else {
			return true;
		}

		return false;
	}

/* ******************************* mailboxes ******************************** */
	/*
	 * Returns list with mailboxes the current user can see.
	 * Typically all his patenkinder will show up.
	 * If the current user is at his pages and is superuser, he will see all mailboxes.
	 */
	public function get_mailboxes() {
		$mailboxes = array();

		if($this->current_user->mbox == $this->authenticated_user->mbox
		   && $this->authenticated_user->a_super >= 1) {
			$where_clause = ' WHERE TRUE';
		} else {
			$where_clause = ' WHERE pate='.$this->db->qstr($this->current_user->mbox);
		}

		$result = $this->db->SelectLimit('SELECT mbox, person, canonical, pate, max_alias, max_regexp, usr.active, last_login AS lastlogin, a_super, a_admin_domains, a_admin_user, '
						.'COUNT(DISTINCT virt.address) AS num_alias, '
						.'COUNT(DISTINCT rexp.ID) AS num_regexp '
					.'FROM '.$this->tablenames['user'].' usr '
						.'LEFT OUTER JOIN '.$this->tablenames['virtual'].' virt ON (mbox=virt.owner) '
						.'LEFT OUTER JOIN '.$this->tablenames['virtual_regexp'].' rexp ON (mbox=rexp.owner) '
					.$where_clause.$_SESSION['filter']['str']['mbox']
					.' GROUP BY mbox, person, canonical, pate,  max_alias, max_regexp, usr.active, last_login, a_super, a_admin_domains, a_admin_user '
					.'ORDER BY pate, mbox',
					$_SESSION['limit'], $_SESSION['offset']['mbox']);

		if(!$result === false) {
			while(!$result->EOF) {
				if(!in_array($result->fields['mbox'], $this->cfg['user_ignore']))
					$mailboxes[] = $result->fields;
				$result->MoveNext();
			}
		}
		$this->current_user->n_mbox = $this->user_get_number_mailboxes($this->current_user->mbox);

		return $mailboxes;
	}

	/*
	 * This will return a list with $whose's patenkinder for further use in selections.
	 */
	public function get_selectable_paten($whose) {
		if(!isset($_SESSION['paten'][$whose])) {
			$selectable_paten = array();
			if($this->authenticated_user->a_super >= 1) {
				$result = $this->db->Execute('SELECT mbox FROM '.$this->tablenames['user']);
			} else {
				$result = $this->db->Execute('SELECT mbox FROM '.$this->tablenames['user'].' WHERE pate='.$this->db->qstr($whose));
			}
			while(!$result->EOF) {
				if(!in_array($result->fields['mbox'], $this->cfg['user_ignore']))
					$selectable_paten[] = $result->fields['mbox'];
				$result->MoveNext();
			}
			$selectable_paten[] = $whose;
			$selectable_paten[] = $this->authenticated_user->mbox;

			// Array_unique() will do alphabetical sorting.
			$_SESSION['paten'][$whose] = array_unique($selectable_paten);
		}

		return $_SESSION['paten'][$whose];
	}

	/*
	 * Eliminates every mailbox name from $desired_mboxes which is no descendant
	 * of $who. If the authenticated user is superuser, no filtering is done
	 * except elimination imposed by $this->cfg['user_ignore'].
	 */
	private function mailbox_filter_manipulable($who, $desired_mboxes) {
		$allowed = array();

		// Does the authenticated user have the right to do that?
		if($this->authenticated_user->a_super >= 1) {
			$allowed = array_diff($desired_mboxes, $this->cfg['user_ignore']);
		} else {
			foreach($desired_mboxes as $mbox) {
				if(!in_array($mbox, $this->cfg['user_ignore']) && $this->user_is_descendant($mbox, $who)) {
					$allowed[] = $mbox;
				}
			}
		}

		return $allowed;
	}

	/*
	 * $props is typically $_POST, as this function selects all the necessary fields
	 * itself.
	 */
	public function mailbox_create($mboxname, $props) {
		$rollback	= array();

		// Check inputs for sanity and consistency.
		if(!$this->authenticated_user->a_admin_user > 0) {
			$this->ErrorHandler->add_error(txt('16'));
			return false;
		}
		if(in_array($mboxname, $this->cfg['user_ignore'])) {
			$this->ErrorHandler->add_error(sprintf(txt('130'), txt('83')));
			return false;
		}
		if(!$this->validator->validate($props, array('mbox','person','pate','canonical','domains','max_alias','max_regexp','a_admin_domains','a_admin_user','a_super','quota'))) {
			return false;
		}

		// check contingents (only if non-superuser)
		if($this->authenticated_user->a_super == 0) {
			// As the current user's contingents will be decreased we have to use his values.
			if($props['max_alias'] > ($this->current_user->max_alias - $this->user_get_used_alias($this->current_user->mbox))
			   || $props['max_regexp'] > ($this->current_user->max_regexp - $this->user_get_used_regexp($this->current_user->mbox))) {
				$this->ErrorHandler->add_error(txt('66'));
				return false;
			}
			$quota	= $this->imap->get_users_quota($this->current_user->mbox);
			if($quota->is_set && $props['quota']*1024 > $quota->free) {
				$this->ErrorHandler->add_error(txt('65'));
				return false;
			}
		}

		// first create the default-from (canonical) (must not already exist!)
		if($this->cfg['create_canonical']) {
			$this->db->Execute('INSERT INTO '.$this->tablenames['virtual'].' (address, dest, owner) VALUES (?, ?, ?)',
					array($props['canonical'], $mboxname, $mboxname));
			if($this->db->Affected_Rows() < 1) {
				$this->ErrorHandler->add_error(txt('133'));
				return false;
			}
			$rollback[] = '$this->db->Execute(\'DELETE FROM '.$this->tablenames['virtual'].' WHERE address='.addslashes($this->db->qstr($props['canonical'])).' AND owner='.addslashes($this->db->qstr($mboxname)).'\')';
		}

		// on success write the new user to database
		$this->db->Execute('INSERT INTO '.$this->tablenames['user'].' (mbox, person, pate, canonical, domains, max_alias, max_regexp, created, a_admin_domains, a_admin_user, a_super)'
				.' VALUES (?,?,?,?,?,?,?,?,?,?,?)',
				array($props['mbox'], $props['person'], $props['pate'], $props['canonical'], $props['domains'], $props['max_alias'], $props['max_regexp'], time(), $props['a_admin_domains'], $props['a_admin_user'], $props['a_super'])
				);
		if($this->db->Affected_Rows() < 1) {
			$this->ErrorHandler->add_error(txt('92'));
			// Rollback
			$this->rollback($rollback);
			return false;
		}
		$rollback[] = '$this->db->Execute(\'DELETE FROM '.$this->tablenames['user'].' WHERE mbox='.addslashes($this->db->qstr($mboxname)).'\')';

		$tmpu = new User($props['mbox']);
		$pw = $tmpu->password->set_random($this->cfg['passwd']['min_length'], $this->cfg['passwd']['max_length']);

		// Decrease current users's contingents...
		if($this->authenticated_user->a_super == 0) {
			$rollback[] = '$this->db->Execute(\'UPDATE '.$this->tablenames['user'].' SET max_alias='.$this->current_user->max_alias.', max_regexp='.$this->current_user->max_regexp.' WHERE mbox='.addslashes($this->db->qstr($this->current_user->mbox)).'\')';
			$this->db->Execute('UPDATE '.$this->tablenames['user']
				.' SET max_alias='.($this->current_user->max_alias-intval($props['max_alias'])).', max_regexp='.($this->current_user->max_regexp-intval($props['max_regexp']))
				.' WHERE mbox='.$this->db->qstr($this->current_user->mbox));
		}
		// ... and then create the user on the server.
		$result = $this->imap->createmb($this->imap->format_user($mboxname));
		if(!$result) {
			$this->ErrorHandler->add_error($this->imap->error_msg);
			// Rollback
			$this->rollback($rollback);
			return false;
		} else {
			if(isset($this->cfg['folders']['create_default']) && is_array($this->cfg['folders']['create_default'])) {
				foreach($this->cfg['folders']['create_default'] as $new_folder) {
					$this->imap->createmb($this->imap->format_user($mboxname, $new_folder));
				}
			}
		}
		$rollback[] = '$this->imap->deletemb($this->imap->format_user(\''.$mboxname.'\'))';

		// Decrease the creator's quota...
		$cur_usr_quota	= $this->imap->getquota($this->imap->format_user($this->current_user->mbox));
		if($this->authenticated_user->a_super == 0 && $cur_usr_quota->is_set) {
			$result = $this->imap->setquota($this->imap->format_user($this->current_user->mbox), $cur_usr_quota->max - $props['quota']*1024);
			if(!$result) {
				$this->ErrorHandler->add_error($this->imap->error_msg);
				// Rollback
				$this->rollback($rollback);
				return false;
			}
			$rollback[] = '$this->imap->setquota($this->imap->format_user($this->current_user->mbox), '.$cur_usr_quota->max .'))';
			$this->ErrorHandler->add_info(sprintf(txt('69'), floor(($cur_usr_quota->max - $props['quota']*1024)/1024) ));
		} else {
			$this->ErrorHandler->add_info(txt('71'));
		}

		// ... and set the new user's quota.
		if(is_numeric($props['quota'])) {
			$result = $this->imap->setquota($this->imap->format_user($mboxname), $props['quota']*1024);
			if(!$result) {
				$this->ErrorHandler->add_error($this->imap->error_msg);
				// Rollback
				$this->rollback($rollback);
				return false;
			}
		}
		$this->ErrorHandler->add_info(sprintf(txt('72'), $mboxname, $props['person'], $pw));
		if(isset($_SESSION['paten'][$props['pate']])) {
			$_SESSION['paten'][$props['pate']][] = $mboxname;
		}

		return true;
	}

	/*
	 * $props can be $_POST, as every sutable field from $change is used.
	 */
	public function mailbox_change($mboxnames, $change, $props) {
		// Ensure sanity of inputs and check requirements.
		if(!$this->authenticated_user->a_admin_user > 0) {
			$this->ErrorHandler->add_error(txt('16'));
			return false;
		}
		if(!$this->validator->validate($props, $change)) {
			return false;
		}
		$mboxnames = $this->mailbox_filter_manipulable($this->authenticated_user->mbox, $mboxnames);
		if(!count($mboxnames) > 0) {
			return false;
		}

		// Create an array holding every property we have to change.
		$to_change	= array();
		foreach(array('person', 'canonical', 'pate', 'domains', 'a_admin_domains', 'a_admin_user', 'a_super')
			as $property) {
			if(in_array($property, $change)) {
				if(is_numeric($props[$property])) {
					$to_change[]	= $property.' = '.$props[$property];
				} else {
					$to_change[]	= $property.'='.$this->db->qstr($props[$property]);
				}
			}
		}

		// Execute the change operation regarding properties in DB.
		if(count($to_change) > 0) {
			$this->db->Execute('UPDATE '.$this->tablenames['user']
				.' SET '.implode(',', $to_change)
				.' WHERE '.db_find_in_set($this->db, 'mbox', $mboxnames));
		}

		// Manipulate contingents (except quota).
		foreach(array('max_alias', 'max_regexp') as $what) {
			if(in_array($what, $change)) {
				$seek_table = $what == 'max_alias'
						? $this->tablenames['virtual']
						: $this->tablenames['virtual_regexp'];
				$to_be_processed = $mboxnames;
				// Select users which use more aliases than allowed in future.
				$result = $this->db->Execute('SELECT COUNT(*) AS consum, owner, person'
						.' FROM '.$seek_table.','.$this->tablenames['user']
						.' WHERE '.db_find_in_set($this->db, 'owner', $mboxnames).' AND owner=mbox'
						.' GROUP BY owner'
						.' HAVING consum > '.$props[$what]);
				if(!$result === false) {
					// We have to skip them.
					$have_skipped = array();
					while(!$result->EOF) {
						$row	= $result->fields;
						$have_skipped[] = $row['owner'];
						if($this->cfg['mboxview_pers']) {
							$tmp[] = '<a href="'.mkSelfRef(array('cuser' => $row['owner'])).'" title="'.$row['owner'].'">'.$row['person'].' ('.$row['consum'].')</a>';
						} else {
							$tmp[] = '<a href="'.mkSelfRef(array('cuser' => $row['owner'])).'" title="'.$row['person'].'">'.$row['owner'].' ('.$row['consum'].')</a>';
						}
						$result->MoveNext();
					}
					if(count($have_skipped) > 0) {
						$this->ErrorHandler->add_error(sprintf(txt('131'),
									$props[$what], $what == 'max_alias' ? txt('88') : txt('89'),
									implode(', ', $tmp)));
						$to_be_processed = array_diff($to_be_processed, $have_skipped);
					}
				}
				if(count($to_be_processed) > 0) {
					// We don't need further checks if a superuser is logged in.
					if($this->authenticated_user->a_super > 0) {
					$this->db->Execute('UPDATE '.$this->tablenames['user']
						.' SET '.$what.'='.$props[$what]
						.' WHERE '.db_find_in_set($this->db, 'mbox', $to_be_processed));
					} else {
						// Now, calculate whether the current user has enough free contingents.
						$has_to_be_free = $this->db->GetOne('SELECT SUM('.$props[$what].'-'.$what.')'
								.' FROM '.$this->tablenames['user']
								.' WHERE '.db_find_in_set($this->db, 'mbox', $to_be_processed));
						if($has_to_be_free <= $this->user_get_used_alias($this->current_user->mbox)) {
							// If so, set new contingents on the users...
							$this->db->Execute('UPDATE '.$this->tablenames['user']
							.' SET '.$what.'='.$props[$what]
							.' WHERE '.db_find_in_set($this->db, 'mbox', $to_be_processed));
							// ... and add/remove the difference from the current user.
							$this->db->Execute('UPDATE '.$this->tablenames['user']
							.' SET '.$what.'='.$what.'-'.$has_to_be_free
							.' WHERE mbox='.$this->db->qstr($this->current_user->mbox));
						} else {
							// Else, we have to show an error message.
							$this->ErrorHandler->add_error(txt('66'));
						}
					}
				}
			}
		}

		// Change Quota.
		if(in_array('quota', $change)) {
			$add_quota = 0;
			if($this->authenticated_user->a_super == 0) {
				foreach($mboxnames as $user) {
					if($user != '') {
						$quota	= $this->imap->get_users_quota($user);
						if($quota->is_set)
							$add_quota += intval($props['quota'])*1024 - $quota->max;
					}
				}
				$quota	= $this->imap->get_users_quota($this->current_user->mbox);
				if($add_quota != 0 && $quota->is_set) {
					$this->imap->setquota($this->imap->format_user($this->current_user->mbox), $quota->max - $add_quota);
					$this->ErrorHandler->add_info(sprintf(txt('78'), floor(($quota->max - $add_quota)/1024) ));
				}
			}
			reset($mboxnames);
			foreach($mboxnames as $user) {
				if($user != '') {
					$result = $this->imap->setquota($this->imap->format_user($user), intval($props['quota'])*1024);
					if(!$result) {
						$this->ErrorHandler->add_error($this->imap->error_msg);
					}
				}
			}
		}

		// Renaming of (single) user.
		if(in_array('mbox', $change)) {
			if($this->imap->renamemb($this->imap->format_user($mboxnames['0']), $this->imap->format_user($props['mbox']))) {
				$this->db->Execute('UPDATE '.$this->tablenames['user'].' SET mbox='.$this->db->qstr($props['mbox']).' WHERE mbox='.$this->db->qstr($mboxnames['0']));
				$this->db->Execute('UPDATE '.$this->tablenames['domains'].' SET owner='.$this->db->qstr($props['mbox']).' WHERE owner='.$this->db->qstr($mboxnames['0']));
				$this->db->Execute('UPDATE '.$this->tablenames['domains'].' SET a_admin = REPLACE(a_admin, '.$this->db->qstr($mboxnames['0']).', '.$this->db->qstr($props['mbox']).') WHERE a_admin LIKE '.$this->db->qstr('%'.$mboxnames['0'].'%'));
				$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET dest=REPLACE(dest, '.$this->db->qstr($mboxnames['0']).', '.$this->db->qstr($props['mbox']).'), neu = 1 WHERE dest REGEXP '.$this->db->qstr($mboxnames['0'].'[^@]{1,}').' OR dest LIKE '.$this->db->qstr('%'.$mboxnames['0']));
				$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET owner='.$this->db->qstr($props['mbox']).' WHERE owner='.$this->db->qstr($mboxnames['0']));
				$this->db->Execute('UPDATE '.$this->tablenames['virtual_regexp'].' SET dest=REPLACE(dest, '.$this->db->qstr($mboxnames['0']).', '.$this->db->qstr($props['mbox']).'), neu = 1 WHERE dest REGEXP '.$this->db->qstr($mboxnames['0'].'[^@]{1,}').' OR dest LIKE '.$this->db->qstr('%'.$mboxnames['0']));
				$this->db->Execute('UPDATE '.$this->tablenames['virtual_regexp'].' SET owner='.$this->db->qstr($props['mbox']).' WHERE owner='.$this->db->qstr($mboxnames['0']));
			} else {
				$this->ErrorHandler->add_error($this->imap->error_msg.'<br />'.txt('94'));
			}
		}

		if(isset($_SESSION['paten']) && in_array(array('mbox', 'pate'), $change)) {
			unset($_SESSION['paten']);	// again: inefficient, but maybe we come up with something more elegant
		}

		return true;
	}

	/*
	 * If ressources are freed, the current user will get them.
	 */
	public function mailbox_delete($mboxnames) {
		$mboxnames = $this->mailbox_filter_manipulable($this->authenticated_user->mbox, $mboxnames);
		if(count($mboxnames) == 0) {
			return false;
		}

		// Delete the given mailboxes from server.
		$add_quota = 0;			// how many space was actually freed?
		$toadd = 0;
		$processed = array();		// which users did we delete successfully?
		foreach($mboxnames as $user) {
			if($user != '') {
				// We have to sum up all the space which gets freed in case we later want increase the deleter's quota.
				$quota	= $this->imap->get_users_quota($user);
				if($this->authenticated_user->a_super == 0
				   && $quota->is_set) {
					$toadd = $quota->max;
				}
				$result = $this->imap->deletemb($this->imap->format_user($user));
				if(!$result) {		// failure
					$this->ErrorHandler->add_error($this->imap->error_msg);
				} else {		// success
					$add_quota += $toadd;
					$processed[] = $user;
				}
			}
		}

		// We need not proceed in case no users were deleted.
		if(count($processed) == 0) {
			return false;
		}

		// Now we have to increase the current user's quota.
		$quota	= $this->imap->get_users_quota($this->current_user->mbox);
		if($this->authenticated_user->a_super == 0
		   && $add_quota > 0
		   && $quota->is_set) {
			$this->imap->setquota($this->imap->format_user($this->current_user->mbox), $quota->max + $add_quota);
			$this->ErrorHandler->add_info(sprintf(txt('76'), floor(($quota->max + $add_quota)/1024) ));
		}

		// Calculate how many contingents get freed if we delete the users.
		if($this->authenticated_user->a_super == 0) {
			$result = $this->db->GetRow('SELECT SUM(max_alias) AS nr_alias, SUM(max_regexp) AS nr_regexp'
					.' FROM '.$this->tablenames['user']
					.' WHERE '.db_find_in_set($this->db, 'mbox', $processed));
			if(!$result === false) {
				$will_be_free = $result;
			}
		}

		// virtual
		$this->db->Execute('DELETE FROM '.$this->tablenames['virtual'].' WHERE '.db_find_in_set($this->db, 'owner', $processed));
		$this->db->Execute('UPDATE '.$this->tablenames['virtual'].' SET active=0, neu=1 WHERE '.db_find_in_set($this->db, 'dest', $processed));
		// virtual.regexp
		$this->db->Execute('DELETE FROM '.$this->tablenames['virtual_regexp'].' WHERE '.db_find_in_set($this->db, 'owner', $processed));
		$this->db->Execute('UPDATE '.$this->tablenames['virtual_regexp'].' SET active=0, neu=1 WHERE '.db_find_in_set($this->db, 'dest', $processed));
		// domain (if the one to be deleted owns domains, the deletor will inherit them)
		$this->db->Execute('UPDATE '.$this->tablenames['domains'].' SET owner='.$this->db->qstr($this->current_user->mbox).' WHERE '.db_find_in_set($this->db, 'owner', $processed));
		// user
		$this->db->Execute('DELETE FROM '.$this->tablenames['user'].' WHERE '.db_find_in_set($this->db, 'mbox', $processed));
		if($this->authenticated_user->a_super == 0 && isset($will_be_free['nr_alias'])) {
			$this->db->Execute('UPDATE '.$this->tablenames['user']
			.' SET max_alias='.($this->current_user->max_alias+$will_be_free['nr_alias']).', max_regexp='.($this->current_user->max_regexp+$will_be_free['nr_regexp'])
			.' WHERE mbox='.$this->db->qstr($this->current_user->mbox));
		}
		// patenkinder (will be inherited by the one deleting)
		$this->db->Execute('UPDATE '.$this->tablenames['user']
			.' SET pate='.$this->db->qstr($this->current_user->mbox)
			.' WHERE '.db_find_in_set($this->db, 'pate', $processed));

		$this->ErrorHandler->add_info(sprintf(txt('75'), implode(',', $processed)));
		if(isset($_SESSION['paten'])) unset($_SESSION['paten']); // inefficient, but maybe we come up with something more elegant

		return true;
	}

	/*
	 * active <-> inactive
	 */
	public function mailbox_toggle_active($mboxnames) {
		$tobechanged = $this->mailbox_filter_manipulable($this->current_user->mbox, $mboxnames);
		if(count($tobechanged) > 0) {
			$this->db->Execute('UPDATE '.$this->tablenames['user']
					.' SET active = NOT active'
					.' WHERE '.db_find_in_set($this->db, 'mbox', $tobechanged));
			if($this->db->Affected_Rows() < 1) {
				if($this->db->ErrorNo() != 0) {
					$this->ErrorHandler->add_error($this->db->ErrorMsg());
				}
			} else {
				return true;
			}
		}
		return false;
	}

}
?>