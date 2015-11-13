<?php
/**
 * @category	Fishpig
 * @package		Fishpig_Bolt
 * @license		http://fishpig.co.uk/license.txt
 * @author		Ben Tideswell <help@fishpig.co.uk>
 */

abstract class Fishpig_Bolt_Session_Abstract
{
	/**
	 * Cookie name to store temporary form key inside
	 *
	 * @param string
	 */
	 static protected $_formKeyCookieValue = false;
	
	/**
	 * Internal data array
	 *
	 * @var array
	 */
	static protected $_data = array();
	
	/**
	 * Determine whether the session type is active
	 *
	 * @var bool
	 */
	static protected $_active = false;
	
	/**
	 * Potential cookie names used to load session
	 *
	 * @var array
	 */
	static protected $_potentialCookieNames = array(
		'PHPSESSID', // Early session instantiation
		'frontend', // Proper Magento cookie
	);
		
	/**
	 * Determine whether the session adapter is active
	 *
	 * @return bool
	 */
	static public function isActive()
	{
		return self::$_active === true;
	}
	
	/**
	 * Initliase the session data
	 *
	 * @return bool
	 */
	static protected function _initSessionData($type)
	{
		if (self::$_active) {
			return self::$_active;
		}
		
		$adapterClassName = 'Fishpig_Bolt_Session_' . $type;

		if (($data = $adapterClassName::_getRawSessionData()) === false) {
			self::_generateNewFormKey();
			
			return false;
		}

		$session = array();
	    $split = preg_split('/([a-z\_]{1,}\|*)\|/', $data,-1,PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
		    
	    $len = count($split);
		    
	    for ($i = 0; $i < $len; $i++, $i++) {
		    $session[$split[$i]] = @unserialize($split[$i+1]);
	    }

		 self::$_data = $session;
		 self::$_active = true;
		 
		 return true;
	}
	
	/**
	 * Resets generated form key
	 * This stops things getting confused
	 *
	 * @return $this
	 */
	static public function resetGeneratedFormKey()
	{
		if (self::$_formKeyCookieValue) {
			if (isset($_COOKIE[FISHPIG_BOLT_FORM_KEY_COOKIE_NAME])) {
				unset($_COOKIE[FISHPIG_BOLT_FORM_KEY_COOKIE_NAME]);
			}
			
			setcookie(FISHPIG_BOLT_FORM_KEY_COOKIE_NAME, null, -1, dirname($_SERVER['PHP_SELF']), null, false, true);

			self::$_formKeyCookieValue = null;
		}
	}
	
	/**
	 * Generate a new form key and store it in the cookie
	 *
	 * @return string
	 */
	static protected function _generateNewFormKey()
	{
		if (self::$_formKeyCookieValue) {
			return self::$_formKeyCookieValue;
		}

		if (!isset($_COOKIE[FISHPIG_BOLT_FORM_KEY_COOKIE_NAME])) {
			$formKey = substr(md5(rand(1, 9999) . $_SERVER['REMOTE_ADDR']), 0, 16);

			setcookie(FISHPIG_BOLT_FORM_KEY_COOKIE_NAME, $formKey, time()+86400, dirname($_SERVER['PHP_SELF']), null, false, true);
	
			self::$_formKeyCookieValue = $formKey;
		}
		else {
			self::$_formKeyCookieValue = $_COOKIE[FISHPIG_BOLT_FORM_KEY_COOKIE_NAME];
		}

		return self::$_formKeyCookieValue;
	}

	/**
	 * Get the form key from the session
	 * If it doesn't exist, generate a new one
	 *
	 * @return string
	 */
	static public function getFormKey()
	{
		return ($formKey = self::getData('core/_form_key'))
			? $formKey
			: self::_generateNewFormKey();
	}
	
	/**
	 * Get a data value
	 *
	 * @param string $key = null
	 * @return mixed
	 */
	static public function getData($key = null)
	{
		return Fishpig_Bolt_App::getArrayValue(self::$_data, $key);
	}
}
