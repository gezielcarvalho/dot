<?php
if (!defined('DP_BASE_DIR')){
	die('You should not access this file directly');
}

ob_start();
	$locale = (isset($AppUI->user_locale) && strlen($AppUI->user_locale)) ? $AppUI->user_locale : '';
	$path = DP_BASE_DIR.'/locales/'. $locale .'/common.inc';
	if ($locale && file_exists($path)){
		@readfile($path);
	}
	
// language files for specific locales and specific modules (for external modules) should be 
// put in modules/[the-module]/locales/[the-locale]/[the-module].inc
// this allows for module specific translations to be distributed with the module
	
	$moduleLocalePath = DP_BASE_DIR.'/modules/'.$m.'/locales/'. $locale .'.inc';
	$fallbackModuleLocalePath = DP_BASE_DIR.'/locales/'. $locale .'/'.$m.'.inc';
	if ($locale && file_exists($moduleLocalePath)){
		@readfile($moduleLocalePath);
	} else if ($locale && file_exists($fallbackModuleLocalePath)){
		@readfile($fallbackModuleLocalePath);
	}
	
	switch ($m) {
	case 'departments':
		$p = DP_BASE_DIR.'/locales/'.$locale.'/companies.inc';
		if ($locale && file_exists($p)){
			@readfile($p);
		}
		break;
	case 'system':
		$p = DP_BASE_DIR.'/locales/'.(isset($dPconfig['host_locale'])?$dPconfig['host_locale']:'').'/styles.inc';
		if (strlen($p) && file_exists($p)){
			@readfile($p);
		}
		break;
	}
	$buf = ob_get_contents();
	if (is_string($buf) && strlen(trim($buf))) {
		eval( '$GLOBALS[\'translate\']=array('.$buf."\n'0');" );
	} else {
		$GLOBALS['translate'] = array();
	}
ob_end_clean();
?>
