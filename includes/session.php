<?php /* $Id$ */
/**
 * Session Handling Functions
 * @global CAppUI $AppUI
 */

/** @var CAppUI $AppUI */
global $AppUI;
/*
* Please note that these functions assume that the database
* is accessible and that a table called 'sessions' (with a prefix
* if necessary) exists.  It also assumes MySQL date and time
* functions, which may make it less than easy to port to
* other databases.  You may need to use less efficient techniques
* to make it more generic.
*
* NOTE: index.php and fileviewer.php MUST call dPsessionStart
* instead of trying to set their own sessions.
*/

if (!defined('DP_BASE_DIR')) {
	die('You should not access this file directly.');
}

require_once DP_BASE_DIR . '/includes/main_functions.php';
require_once DP_BASE_DIR . '/includes/db_adodb.php';
require_once DP_BASE_DIR . '/includes/db_connect.php';
require_once DP_BASE_DIR . '/classes/query.class.php';
require_once DP_BASE_DIR . '/classes/ui.class.php';
require_once DP_BASE_DIR . '/classes/event_queue.class.php';

function dPsessionOpen($save_path, $session_name) {
	return true;
}

function dPsessionClose() {
	return true;
}

function dPsessionRead($id) {
	$q = new DBQuery;
	$q->addTable('sessions');
	$q->addQuery('session_data');
	$q->addQuery('UNIX_TIMESTAMP() - UNIX_TIMESTAMP(session_created) as session_lifespan');
	$q->addQuery('UNIX_TIMESTAMP() - UNIX_TIMESTAMP(session_updated) as session_idle');
	$q->addWhere("session_id = '$id'");
	$qid =& $q->exec();
	if (! $qid || $qid->EOF) {
		dprint(__FILE__, __LINE__, 11, "Failed to retrieve session $id");
		$data =  "";
	} else {
		$max = dPsessionConvertTime('max_lifetime');
		$idle = dPsessionConvertTime('idle_time');
		dprint(__FILE__, __LINE__, 11, 
		       ("Found session $id, max=$max/" . $qid->fields['session_lifespan'] 
		        . ", idle=$idle/" . $qid->fields['session_idle']));
		// If the idle time or the max lifetime is exceeded, trash the
		// session.
		if ($max < $qid->fields['session_lifespan'] || $idle < $qid->fields['session_idle']) {
			dprint(__FILE__, __LINE__, 11, "session $id expired");
			dPsessionDestroy($id);
			$data = '';
		} else {
			$data = $qid->fields['session_data'];

			// Debug: log retrieved session data length and presence of AppUI
			if (defined('DP_BASE_DIR')) {
				$info = array(
					'time' => date('c'),
					'read_session_id' => $id,
					'data_length' => strlen($data),
					'contains_AppUI' => (mb_strpos($data, 'AppUI') !== false),
				);
				@file_put_contents(DP_BASE_DIR . '/tmp/session_debug.log', json_encode($info) . "\n", FILE_APPEND);
			}
		}
	}
	$q->clear();
	return $data;
}

function dPsessionWrite($id, $data) {
    global $AppUI;
    /** @var CAppUI $AppUI */
    
	$q = new DBQuery;
	$q->addQuery('count(*) as row_count');
	$q->addTable('sessions');
	$q->addWhere("session_id = '$id'");
	
	if ($qid =& $q->exec() && (@$qid->fields['row_count'] > 0 || @$qid->fields[0] > 0)) {
		dprint(__FILE__, __LINE__, 11, "Updating session $id");
		$q->query = null;
		$q->addUpdate('session_data', $data);
		if (isset($AppUI)) {
			$q->addUpdate('session_user', (int)$AppUI->last_insert_id);
		}
	} else {
		dprint(__FILE__, __LINE__, 11, "Creating new session $id");
		$q->query = null;
		$q->where = null;
		$q->addInsert('session_id', $id);
		$q->addInsert('session_data', $data);
		$q->addInsert('session_created', date('Y-m-d H:i:s'));
	}
	$q->exec();
	$q->clear();
	return true;
}

function dPsessionDestroy($id, $user_access_log_id=0) {
 	global $AppUI;
 	/** @var CAppUI $AppUI */
    
	$q = new DBQuery;
    $q->addTable('sessions'); // Alias not required
    $q->addQuery('session_user');
    $q->addWhere("session_id='". $id ."'"); //Using double quotes for readability
    $sql2 = $q->prepare(true);

	dprint(__FILE__, __LINE__, 11, "Killing session $id");
	$q->addTable('user_access_log');
	$q->addUpdate('date_time_out', date('Y-m-d H:i:s'));
	$q->addWhere('user_access_log_id = ' 
	             . (($user_access_log_id) ? $user_access_log_id
	                : '('.$sql2.')'));
	$q->exec();
	$q->clear();
	
	$q->setDelete('sessions');
	$q->addWhere("session_id = '$id'");
	$q->exec();
	$q->clear();
    
	return true;
}

function dPsessionGC($maxlifetime) {
	global $AppUI;
	/** @var CAppUI $AppUI */
	
	dprint(__FILE__, __LINE__, 11, 'Session Garbage collection running');
	$now = time();
	$max = dPsessionConvertTime('max_lifetime');
	$idle = dPsessionConvertTime('idle_time');
	// First pass is to kill any users that are logged in at the time of the session.
	$where = ('UNIX_TIMESTAMP() - UNIX_TIMESTAMP(session_updated) > ' . $idle 
	          . ' OR UNIX_TIMESTAMP() - UNIX_TIMESTAMP(session_created) > ' . $max);
	$q = new DBQuery;
    $q->addTable('sessions');
    $q->addQuery('session_user');
    $q->addWhere($where);
    $sql2 = $q->prepare(true);

	$q->addTable('user_access_log');
	$q->addUpdate('date_time_out', date('Y-m-d H:i:s'));
	$q->addWhere('user_access_log_id IN ('. $sql2 .')');
	$q->exec();
	$q->clear();
	
	// Now we simply delete the expired sessions.
	$q->setDelete('sessions');
	$q->addWhere($where);
	$q->exec();
	$q->clear();
	if (dPgetConfig('session_gc_scan_queue')) {
		// We need to scan the event queue.  If $AppUI isn't created yet
		// And it isn't likely that it will be, we create it and run the
		// queue scanner.
		if (! isset($AppUI)) {
			$AppUI = new CAppUI;
			/** @var CAppUI $AppUI */
			$queue = EventQueue::getInstance();
			$queue->scan();
		}
	}
	return true;
}

function dPsessionConvertTime($key) {
	$key = 'session_' . $key;
	
	// If the value isn't set, then default to 1 day.
	if (dPgetConfig($key) == null || dPgetConfig($key) == null)
		return 86400;
	
	$numpart = (int) dPgetConfig($key);
	$modifier = mb_substr(dPgetConfig($key), -1);
	if (! is_numeric($modifier)) {
		switch ($modifier) {
			case 'h':
				$numpart *= 3600;
				break;
			case 'd':
				$numpart *= 86400;
				break;
			case 'm':
				$numpart *= (86400 * 30);
				break;
			case 'y':
				$numpart *= (86400 * 365);
				break;
		}
	}
	return $numpart;
}

function dpSessionStart($start_vars = 'AppUI') {
	session_name('dotproject');
	if (ini_get('session.auto_start') > 0) {
		session_write_close();
	}
	if (dPgetConfig('session_handling') == 'app') {
    // Seems user session handler is not a real thing in php 7
    if (version_compare(phpversion(), '7.0.0', '<')) {
      ini_set('session.save_handler', 'user');
    }
	// PHP 5.2 workaround
    if (version_compare(phpversion(), '5.0.0', '>=')) {
        register_shutdown_function('session_write_close');
    } 
		session_set_save_handler('dPsessionOpen', 'dPsessionClose', 'dPsessionRead', 
		                         'dPsessionWrite', 'dPsessionDestroy', 'dPsessionGC');
		$max_time = dPsessionConvertTime('max_lifetime');
	} else {
		$max_time = 0; // Browser session only.
	}
	// Try and get the correct path to the base URL.
	preg_match('_^(https?://)([^/:]+)(:[0-9]+)?(/.*)?$_i', dPgetConfig('base_url'), $url_parts);
	$cookie_dir = isset($url_parts[4]) ? $url_parts[4] : '/';
	if (mb_substr($cookie_dir, 0, 1) != '/') {
		$cookie_dir = '/' . $cookie_dir;
	}
	if (mb_substr($cookie_dir, -1) != '/') {
		$cookie_dir .= '/';
	}
	$domain = $url_parts[2];
	$secure = ($url_parts[1] == 'https://');

	// Do not set a Domain attribute for localhost or bare IPs since some
	// browsers reject cookies with Domain=localhost or Domain=127.0.0.1.
	// Use a host-only cookie by passing an empty domain in those cases.
	$cookie_domain = $domain;
	if ($cookie_domain === 'localhost' || filter_var($cookie_domain, FILTER_VALIDATE_IP)) {
		$cookie_domain = '';
	}

	session_set_cookie_params($max_time, $cookie_dir, $cookie_domain, $secure, true);
	
	if (is_array($start_vars)) {
		foreach ($start_vars as $var) {
			$_SESSION[$var] =  $GLOBALS[$var];
		}
	} else if (!(empty($start_vars))) {
		$_SESSION[$start_vars] =  $GLOBALS[$start_vars];
	}
	
	session_start();
}

/**
 * Clear the session cookie in the browser.
 */
function dPclearSessionCookie()
{
	// derive cookie params the same way dpSessionStart does
	preg_match('_^(https?://)([^/:]+)(:[0-9]+)?(/.*)?$_i', dPgetConfig('base_url'), $url_parts);
	$cookie_dir = isset($url_parts[4]) ? $url_parts[4] : '/';
	if (mb_substr($cookie_dir, 0, 1) != '/') {
		$cookie_dir = '/' . $cookie_dir;
	}
	if (mb_substr($cookie_dir, -1) != '/') {
		$cookie_dir .= '/';
	}
	$domain = $url_parts[2];
	// host-only cookie for localhost/IP
	if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
		$domain = '';
	}
	// expire the cookie
	if (PHP_VERSION_ID < 70300) {
		setcookie(session_name(), '', time() - 42000, $cookie_dir, $domain);
	} else {
		// PHP 7.3+ supports 'samesite' via options array in setcookie
		setcookie(session_name(), '', [
			'expires' => time() - 42000,
			'path' => $cookie_dir,
			'domain' => $domain,
			'secure' => (isset($url_parts[1]) && $url_parts[1] == 'https://'),
			'httponly' => true,
			'samesite' => 'Lax'
		]);
	}
}

