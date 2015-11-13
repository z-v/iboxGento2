<?php
/**
 * @category	Fishpig
 * @package		Fishpig_Bolt
 * @license		http://fishpig.co.uk/license.txt
 * @author		Ben Tideswell <help@fishpig.co.uk>
 */
	
class Fishpig_Bolt_App
{
	/**
	 * Values for the bolt/advanced/disable_if configuration option
	 *
	 * @const int
	 */
	const CONDITION_CACHE_FIRST_REQUEST = 1;
	const CONDITION_CACHE_IF_CUSTOMER = 2;
	const CONDITION_CACHE_IF_CART = 3;

	/**
	 * Values for the bolt/advanced/excluded_uris configuration option
	 *
	 * @const int
	 */
	const CONDITION_URI_TYPE_ROUTE = 1;
	const CONDITION_URI_TYPE_STRING = 2;
	const CONDITION_URI_TYPE_REGEX = 3;
	
	/**
	 * Hole punch object
	 *
	 * @var Fishpig_Bolt_HolePunch
	 */
	static protected $_holePunch =  null;
	
	/**
	 * Cache adapter object
	 *
	 * @var Fishpig_Bolt_Cache_Type_Abstract
	 */
	static protected $_adapter = null;
	
	/**
	 * Session handler object
	 *
	 * @var Fishpig_Bolt_Session_Abstract
	 */
	static protected $_session = null;
	
	/**
	 * Cache for config file
	 *
	 * @var array
	 */
	static protected $_config = null;

	/**
	 * Flag that determines whether the store is being changed
	 *
	 * @var bool
	 */
	static protected $_isChangingStore = false;
	
	 /**
	  * Determine whether we're in the Admin
	  *
	  * @var bool
	  */
	static protected $_isAdmin = null;
	
	/**
	 * Cache for the processed request uri
	 *
	 * @string
	 */
	static protected $_cacheKey = null;
	
	/**
	 * An array of excluded URIs
	 *
	 * @var array
	 */
	static protected $_excludedUris = array(
		'api' => array('type' => self::CONDITION_URI_TYPE_REGEX, 'value' => '/^api\/.*$/'),
		'checkout' => array('type' => self::CONDITION_URI_TYPE_REGEX, 'value' => '/^checkout\/.*$/'),
		'customer' => array('type' => self::CONDITION_URI_TYPE_REGEX, 'value' => '/^customer\/.*$/'),
		'paypal' => array('type' => self::CONDITION_URI_TYPE_REGEX, 'value' => '/^paypal\/.*$/'),
		'downloadable' => array('type' => self::CONDITION_URI_TYPE_REGEX, 'value' => '/^downloadable\/.*$/'),
		'directory' => array('type' => self::CONDITION_URI_TYPE_REGEX, 'value' => '/^directory\/currency\/switch.*$/'),
	);

	/**
	 * System parameters that can be ignored
	 * This is merged with the custom list from the config
	 *
	 * @var array
	 */
	static protected $_excludedParameters = array(
		'___store',
		'___from_store',
		'isAjax',
		/* GA */
		'utm_source',
		'utm_medium',
		'utm_term',
		'utm_content',
		'utm_campaign',
		/* End of GA */
	);

	/**
	 * Cookies used to generate the cache key
	 *
	 * @var array
	 */
	static protected $_cookiesForCacheKey = array(
		'currency',
	);
	
	/**
	 * Disallowed routes
	 *
	 * @var array
	 */
	static protected $_disallowedRoutes = array(
	 	'api/*',
	 	'catalog/product/compare',
	 	'checkout/*',
	 	'customer/*',
	 	'paypal/*',
	);
	
	/**
	 * A cache of the request URI
	 *
	 * @var string
	 */
	static protected $_requestUri = null;

	/**
	 * Run the cache code
	 * This will try to match the current request against a cache record
	 *
	 * @return $this
	 */
	static public function run()
	{
		if (!self::canTryBolt()) {
			return;
		}
		
		if (!self::getConfig() || self::isAdmin() || !self::getSession()) {
			return;
		}
		
		if (!self::_validateConfig()) {
			return self::_beforePassToMagento();
		}

		if (self::getCacheKey() === false) {
			return self::_beforePassToMagento();
		}

		if (!self::canLoadCachedRequest()) {
			return self::_beforePassToMagento();
		}

		if (($cacheAdapter = self::getCache()) === false) {
			return self::_beforePassToMagento();
		}

		if (($html = $cacheAdapter::load(self::getCacheKey())) === false) {
			return self::_beforePassToMagento();
		}
		
		$holePunch = self::getHolePunch();

		$areHolesPunched = $holePunch::punchHoles($html);
		
		if (self::updateFormKey($html)) {
			self::sendResponse($html, $areHolesPunched);
		}
		else {
			return self::_beforePassToMagento();
		}
	}

	/**
	 * Validate the request early
	 *
	 * @return bool
	 */
	static public function canTryBolt()
	{
		if (!defined('FISHPIG_BOLT') || self::isHttpPostRequest() || self::isDebug()) {
			return false;
		}
		
		if (isset($_SERVER['REQUEST_URI'])) {
			return self::_isUrlAllowed(self::$_excludedUris, self::cleanRequestUri($_SERVER['REQUEST_URI']));
		}
		
		return true;
	}

	/**
	 * Runs when Bolt cannot run before passing the request to Magento
	 *
	 * @return $this
	 */
	static protected function _beforePassToMagento()
	{
		if ($sessionAdapter = self::getSession()) {
			$sessionAdapter::resetGeneratedFormKey();
		}
	}

	/**
	 * Cache $html against the current request
	 *
	 * @param string $html
	 * @return void
	 */
	static public function cachePage(Mage_Core_Model_App $app)
	{
		if (!defined('FISHPIG_BOLT')) {
			return false;
		}
		
		if (isset($_GET['isAjax'])) {
			return false;
		}
		
		if (($cacheAdapter = self::getCache()) === false) {
			return false;
		}

		$request = Mage::app()->getRequest();
		$response = Mage::app()->getResponse();

		/**
		 * Check that the response type is a cacheable type
		 */
		$isTextHtml = false;

		foreach((array)$response->getHeaders() as $header) {
			if (strtolower($header['name']) === 'content-type') {
				$isTextHtml = strpos($header['value'], 'text/html') !== false;
			}
		}

		if (!$isTextHtml || Mage::app()->getRequest()->getActionName() === 'noRoute' || $response->getHttpResponseCode() !== 200) {
			return false;
		}
			
		/**
		 * Ensure that the current route and module are allowed
		 */
		$allowedModules = explode(',',self::getConfig('advanced/allowed_modules'));

		 if (!in_array($request->getModuleName(), $allowedModules)) {
		 	return false;
		 }
 
		 $currentRoute = array(
		 	$request->getRequestedRouteName(),
		 	$request->getRequestedControllerName(),
		 	$request->getRequestedActionName(),
		 );
			 
		 foreach(self::$_disallowedRoutes as $disallowedRoute) {
		 	$disallowedRoute = explode('/', $disallowedRoute);
		 	$match = true;
		 	
		 	foreach($currentRoute as $currentRouteItem) {
		 		if ($disallowedRoute) {
				 	$disallowedRouteItem = array_shift($disallowedRoute);
				 	
				 	if (!in_array($disallowedRouteItem, array('*', $currentRouteItem))) {
				 		$match = false;
					 	break;
				 	}
				 }
		 	}
		 	
		 	if ($match) {
		 		return false;
		 	}
		 }

		/**
		 * Final checks before caching
		 */
		if (self::getCacheKey() === false || self::hasCartItems() || self::isCustomerLoggedIn() || self::hasMessages()) {
			return false;
		}

		return $cacheAdapter::save(self::getCacheKey(), $response->getBody(), (int)self::getConfig('settings/lifetime'));
	}
	
	/**
	 * Determine whether it's possible to load a cache request
	 *
	 * @return bool
	 */
	static public function canLoadCachedRequest()
	{
		$holePunch = self::getHolePunch();

		return ($holePunch::isEnabled() ||  (!self::hasCartItems() && !self::isCustomerLoggedIn()))
			&& !self::hasMessages()
			&& !self::$_isChangingStore
			&& (self::canCacheFirstRequest() || count($_COOKIE) > 0)
			&& (!self::hasCartItems() || !self::isDisabledWithCartItems())
			&& (!self::isCustomerLoggedIn() || !self::isDisabledWhenCustomer());
	}
	
	/**
	 * Delete a cache record
	 * Parameter order seems odd but this reflects the way the cache key is built
	 *
	 * @param int $storeId
	 * @param string $useragent
	 * @param string $protocol
	 * @param string $uri
	 * @param bool $subpages = true
	 * @return $this
	 */
	static public function delete($storeId, $useragent, $protocol, $uri, $subpages = true)
	{
		if (($cacheKey = self::generateCacheKey($storeId, $useragent, $protocol, $uri)) !== false) {
			$cacheAdapter = self::getCache();

			$cacheAdapter::delete($cacheKey, $subpages);
		}
	}
	
	/**
	 * Send a Flush message to the cache adapter
	 *
	 * @return $this
	 */
	static public function flush()
	{
		if ($cacheAdapter = self::getCache()) {
			$cacheAdapter::flush();
		}
	}

	/**
	 * Initialise the request URI
	 *
	 * @return string|false
	 */
	static public function getCacheKey()
	{
		if (self::$_cacheKey === NULL) {
			self::$_cacheKey = self::generateCacheKey(
				(int)self::getConfig('store_id'), 
				self::getUserAgentGroup(),
				self::getRequestProtocol(),
				$_SERVER['REQUEST_URI']
			);
		}
		
		return self::$_cacheKey;
	}
	
	/**
	 * Generate a cache key based on inputs
	 *
	 * @param int $storeId
	 * @param string $useragent
	 * @param string $protocol
	 * @param string $uri
	 * @return string
	 */
	static public function generateCacheKey($storeId, $useragent, $protocol, $uri)
	{
		$key = array(
			'store_id' => $storeId,
			'useragent' => $useragent ? $useragent : 'ua_default',
			'protocol' => $protocol,
		);

		$uri = self::cleanRequestUri($uri);

		$queryString = '';

		if (strpos($uri, '?') !== false) {
			$queryString = substr($uri, strpos($uri, '?')+1);
			$uri = substr($uri, 0, strpos($uri, '?'));
		}

		if ($excludedUris = self::_getExcludedUris()) {
			if (!self::_isUrlAllowed($excludedUris, $uri)) {
				return false;
			}
		}

		$key['uri'] = trim($uri, '/');
		$params = array();
		
		// Process query string
		if ($queryString) {
			parse_str($queryString, $query);

			if (isset($query['___store']) && $query['___store'] !==self::getConfig('store_code')) {
				return false;
			}

			// Sort keys so that the order doesn't create a new cache entry
			ksort($query);
			
			$excludeParams = self::_getExcludedParameters();

			foreach($excludeParams as $param) {
				if (($param = trim($param)) !== '') {
					if (isset($query[$param])) {
						unset($query[$param]);
					}
				}
			}
			
			if (count($query) > 0) {
				$params = array_merge($params, $query);
			}
		}

		$cookiesForCacheKey = self::_getCookiesForCacheKey();

		foreach($cookiesForCacheKey as $cookie) {
			if (!trim($cookie)) {
				continue;
			}

			if (isset($_COOKIE[$cookie])) {
				$params['_fpck_' . $cookie] = $_COOKIE[$cookie];
			}
		}
		
		if (count($params) > 0) {
			$key['params'] = '?' . http_build_query($params);
		}

		return rtrim(implode(DIRECTORY_SEPARATOR, $key), DIRECTORY_SEPARATOR);
	}
	
	static public function cleanRequestUri($uri)
	{
		$uri = trim($uri, '/');

		// Trim the filename from the URI
		if (strpos($uri, FISHPIG_BOLT_FILE) === 0) {
			$uri = ltrim(substr($uri, strlen(FISHPIG_BOLT_FILE)), '/');
		}
		
		// Trim the filename from the URI
		if (strpos($uri, 'index.php') === 0) {
			$uri = ltrim(substr($uri, strlen('index.php')), '/');
		}

		return $uri;
	}
	
	/**
	 * Match a URL against array
	 *
	 * @param array $urisToMatch
	 * @param string $uri
	 * @return bool
	 */
	static protected function _isUrlAllowed(array $urisToMatch, $uri)
	{
		foreach($urisToMatch as $key => $uriToMatch) {
			$isKeyString = !is_numeric($key);
			
			if ($isKeyString || (int)$uriToMatch['type'] === self::CONDITION_URI_TYPE_STRING) {
				$value = $isKeyString ? $key : $uriToMatch['value'];

				if (trim($uri, '/') === trim($value, '/')) {
					return false;
				}
			}

			if ((int)$uriToMatch['type'] === self::CONDITION_URI_TYPE_REGEX) {
				if (@preg_match($uriToMatch['value'], $uri)) {
					return false;
				}
			}
		}
		
		return true;
	}

	/**
	 * Get the config file
	 * If $key is set, look for that element of the config data
	 *
	 * @param string $key = null
	 * @return mixed
	 */
	static public function getConfig($key = null)
	{
		if (self::$_config === NULL) {
			self::$_config = array();

			if (($config =self::getFile(self::getDir('app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'bolt.config'))) !== false) {
				self::$_config = (array)unserialize($config);
			}
			else {
				return false;
			}
		}

		return $key === NULL
			? self::$_config
			:self::getArrayValue(self::$_config, $key);
	}

	/**
	 * Validate the configuration data
	 *
	 * @return bool
	 */
	static protected function _validateConfig()
	{
		$sessionAdapter = self::getSession();
		
		if (isset($_GET['___store'])) {
			self::$_isChangingStore = true;	
		}

		if (isset($_GET['___store']) && ($store =self::getStoreByCode($_GET['___store'])) !== false) {
			self::$_isChangingStore = $_GET['___store'] !== $sessionAdapter::getData('core/store_code');
			// Changing store via the query string
		}
		else if (is_array(($store =self::getStoreByCode($sessionAdapter::getData('core/store_code'))))) {
			// Loaded store via the session
		}
		else {
			// Load through environment variables or just load default
			$code = isset($_SERVER['MAGE_RUN_CODE']) ? $_SERVER['MAGE_RUN_CODE'] : '';
			$type = isset($_SERVER['MAGE_RUN_TYPE']) ? $_SERVER['MAGE_RUN_TYPE'] : 'store';

			if (!$code &&self::getConfig('websites/' .self::getConfig('default_website_id'))) {
				$code =self::getConfig('default_website_code');
				$type = 'website';
			}
			
			$store = false;
			
			if ($type === 'store') {
				$store =self::getStoreByCode($code);
			}
			else if ($type === 'website') {
				$store =self::getStoreByWebsiteCode($code);
			}
		}
			
		if (!is_array($store)) {
			return false;
		}
		
		unset(self::$_config['websites']);

		self::$_config = array_merge(self::$_config, (array)$store);

		if ((int)self::getConfig('use_cache') !== 1) {
			return false;
		}
		else if ((int)self::getConfig('settings/enabled') !== 1) {
			return false;
		}

		return true;		
	}

	/**
	 * Retrieve a store by a website code
	 *
	 * @param string $websiteCode
	 * @return array|false
	 */
	static public function getStoreByWebsiteCode($websiteCode)
	{
		foreach(self::$_config['websites'] as $websiteId => $website) {
			if ($website['code'] === $websiteCode) {
				if (isset($website['groups'][$website['default_group_id']])) {
					$group = $website['groups'][$website['default_group_id']];
					
					if (isset($group['stores'][$group['default_store_id']])) {
						return $group['stores'][$group['default_store_id']];
					}
				}
				
				break;
			}
		}
		
		return false;		
	}

	/**
	 * Retrieve a store for $code
	 *
	 * @param string $code
	 * @return array|false
	 */
	static public function getStoreByCode($code)
	{
		foreach(self::$_config['websites'] as $websiteId => $website) {
			foreach($website['groups'] as $group) {
				foreach($group['stores'] as $store) {
					if ($store['store_code'] === $code) {
						return $store;
					}
				}
			}
		}
		
		return false;
	}
	
	/**
	 * Determine whether we're in debug mode
	 *
	 * @return bool
	 */		
	static public function isDebug()
	{
		return FISHPIG_BOLT_DEBUG_IP_HASH 
			&& FISHPIG_BOLT_DEBUG_IP_HASH !== md5($_SERVER['REMOTE_ADDR']);
	}

	/**
	 * Determine whether the current request is a HTTP Post request
	 *
	 * @return bool
	 */
	static public function isHttpPostRequest()
	{
		return strtolower($_SERVER['REQUEST_METHOD']) === 'post';
	}

	/**
	 * Determine whether the request is for the Admin
	 *
	 * @return bool
	 */
	static public function isAdmin()
	{
		if (self::$_isAdmin !== NULL) {
			return self::$_isAdmin;
		}

		$frontName =self::getConfig('admin_frontName');
		
		$fileName = $_SERVER['SCRIPT_FILENAME'];
		$requestUri = $_SERVER['REQUEST_URI'];
		
		self::$_isAdmin = preg_match('/^(' . basename($fileName) . '\/' . $frontName . '(\/|\z)|' . $frontName . '(\/|\z))/', trim($requestUri, '/'));
		
		return self::$_isAdmin;
	}

	/**
	 * Update the form key in the html
	 *
	 * @param string $html
	 * @return void
	 */
	static public function updateFormKey(&$html)
	{
		if (strpos($html, 'form_key') === false) {
			return true;
		}

		$sessionAdapter = self::getSession();

		if (!($newFormKey = $sessionAdapter::getFormKey())) {
			return false;
		}

		$existingFormKey = null;

		if (preg_match('/\/form_key\/([a-zA-Z0-9]+)[\/"]{1}/', $html, $match)) {
			$existingFormKey = $match[1];
		}
		else if (preg_match('/name="form_key"[^>]+value="(.*)"/U', $html, $match)) {
			$existingFormKey = $match[1];
		}
		else if (preg_match('/value="(.*)"[^>]+name="form_key"/U', $html, $match)) {
			$existingFormKey = $match[1];
		}
		else {
			return false;
		}
		
		$html = str_replace($existingFormKey, $newFormKey, $html);
			
		return true;
	}

	/**
	 * Send a response to the browser
	 *
	 * @param string $html
	 * @return void
	 */
	static public function sendResponse($html, $areHolesPunched = false)
	{
		header('Content-Type: text/html; charset=utf-8');
		header("Pragma: no-cache");
		header("Cache-Control: no-cache, must-revalidate, no-store, post-check=0, pre-check=0");

		echo str_replace('</body>', '<!--' . ($areHolesPunched ? 'BH' : 'B') . '--></body>', $html);
		exit;
	}

	/**
	 * Get the hole punch object
	 *
	 * @return Fishpig_Bolt_HolePunch
	 */
	static public function getHolepunch()
	{
		if (self::$_holePunch === NULL) {
			require(self::getDir('lib' . DIRECTORY_SEPARATOR . 'Fishpig' . DIRECTORY_SEPARATOR . 'Bolt' . DIRECTORY_SEPARATOR . 'HolePunch.php'));
			
			self::$_holePunch = 'Fishpig_Bolt_HolePunch';
		}
		
		return self::$_holePunch;
	}

	/**
	 * Get the cache adapter object
	 *
	 * @return Fishpig_Bolt_Cache_Type_Abstract
	 */
	static public function getCache()
	{
		if (self::$_adapter === NULL) {
			$class = 'Fishpig_Bolt_Cache_' . (self::getConfig('cache_type') === 'memcache' ? 'Memcached' : 'File');

			require(self::getDir('lib' . DIRECTORY_SEPARATOR . 'Fishpig' . DIRECTORY_SEPARATOR . 'Bolt' . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR . 'Abstract.php'));
			require(self::getDir('lib' . DIRECTORY_SEPARATOR . str_replace('_', DIRECTORY_SEPARATOR, $class) . '.php'));

			self::$_adapter = $class;
		}
		
		return self::$_adapter;
	}
	
	/**
	 * Determine whether the customer has items in their cart
	 *
	 * @return bool
	 * @todo save cart item count in session via Magento
	 */
	static public function hasCartItems()
	{
		$sessionAdapter = self::getSession();

		return (int)$sessionAdapter::getData('core/cart_item_count') > 0;
	}
	
	/**
	 * Determine whether the customer is logged in
	 *
	 * @return bool
	 */
	static public function isCustomerLoggedIn()
	{
		$sessionAdapter = self::getSession();

		return $sessionAdapter::getData('customer_base/id') || $sessionAdapter::getData('core/is_logged_in');
	}
	
	/**
	 * Determine whether there are any messages
	 *
	 * @return bool
	 */
	static public function hasMessages()
	{
		$sessionAdapter = self::getSession();

		return $sessionAdapter::getData('core/session_messages_exists');
	}
	
	/**
	 * Determine whether the user agent is in the disallow regex pattern
	 * If so, caching should not occur
	 *
	 * @param string $default = 'ua_default'
	 * @return string
	 */
	static public function getUserAgentGroup()
	{
		if ((int)self::getConfig('conditions/mobile_detect') === 0) {
			return 'ua_default';
		}

		if ((@include self::getDir('lib' . DIRECTORY_SEPARATOR . 'Fishpig' . DIRECTORY_SEPARATOR . 'Bolt' . DIRECTORY_SEPARATOR . 'Detect.php')) === true) {
			$detect = new Mobile_Detect();
			
			if ($detect->isMobile()) {
				return 'ua_mobile';
			}
			else if ($detect->isTablet()) {
				return 'ua_tablet';
			}
		}

		return 'ua_default';
	}
	
	/**
	 * Get an array of URL parameters to be excluded
	 *
	 * @return array
	 */
	static protected function _getExcludedParameters()
	{
		$excludedParameters = self::$_excludedParameters;
		$customExcludedParameters = self::_unserialize(self::getConfig('advanced/excluded_parameters'));
		
		foreach($customExcludedParameters as $customExcludedParameter) {
			$value = array_shift($customExcludedParameter);
			
			if ($value) {
				$excludedParameters[] = $value;
			}
		}

		return array_unique($excludedParameters);
	}
	
	/**
	 * Get an array of the cookies to be used when generating the cache key
	 *
	 * @return array
	 */
	static protected function _getCookiesForCacheKey()
	{
		$cookies = self::$_cookiesForCacheKey;
		$customCookies = self::_unserialize(self::getConfig('advanced/cookies'));

		foreach($customCookies as $customCookie) {
			$value = array_shift($customCookie);
			
			if ($value) {
				$cookies[] = $value;
			}
		}
		
		return array_unique($cookies);
	}
	
	/**
	 * Get an array of URI's to be excluded
	 *
	 * @return array
	 */
	static protected function _getExcludedUris()
	{
		$excludedUris = self::$_excludedUris;
		$excludedUris = array();
		$customExcludedUris = self::_unserialize(self::getConfig('advanced/excluded_uris'));
		
		foreach($customExcludedUris as $customExcludedUri) {
			$value = array_shift($customExcludedUri);
			$type = array_shift($customExcludedUri);
			
			if ($value && $value) {
				$excludedUris[] = array(
					'type' => $type,
					'value' => $value,
				);
			}
		}

		return $excludedUris;
	}

	/**
	 * Determine whether to cache the first request for each customer
	 *
	 * @return bool
	 */
	static public function canCacheFirstRequest()
	{
		return !self::_isDisabledIfActive(self::CONDITION_CACHE_FIRST_REQUEST);
	}

	/**
	 * Determine whether to cache when items in cart
	 *
	 * @return bool
	 */
	static public function isDisabledWithCartItems()
	{
		return self::_isDisabledIfActive(self::CONDITION_CACHE_IF_CART);
	}

	/**
	 * Determine whether to cache when customer is logged in
	 *
	 * @return bool
	 */
	static public function isDisabledWhenCustomer()
	{
		return self::_isDisabledIfActive(self::CONDITION_CACHE_IF_CUSTOMER);
	}
	
	/**
	 * Check for a bolt/advanced/disable_if configuration option
	 *
	 * @param int $type
	 * @return bool
	 */
	static protected function _isDisabledIfActive($type)
	{
		return in_array($type, explode(',', self::getConfig('settings/disable_if')));
	}
	
	/**
	 * Unserialize a string
	 *
	 * @param string $value
	 * @return array
	 */
	static protected function _unserialize($value)
	{
		return $value ? @unserialize($value) : array();
	}
	
	/**
	 * Handle any exceptions that occur
	 *
	 * @param Exception $e
	 * @return void
	 */
	static public function handleException(Exception $e)
	{
		if (self::isDebug()) {
			echo sprintf('<h1>%s</h1><pre>%s</pre>', $e->getMessage(), $e->getTraceAsString());
			exit;
		}
	}
	
	/**
	 * Retrieve a value for a multi-dimensional array
	 *
	 * @param array $arr
	 * @param string $key
	 * @return mixed
	 */
	static public function getArrayValue(&$arr, $key)
	{
		if ($key === NULL) {
			return $arr;
		}
		
		$key = trim($key, '/');
		
		if (strpos($key, '/') !== false) {
			$keys = explode('/', $key);
			
			$buffer = $arr;
			
			foreach($keys as $key) {
				if (isset($buffer[$key])) {
					$buffer = $buffer[$key];
				}
				else {
					$buffer = null;
					break;
				}
			}
			
			return $buffer;
		}

		return isset($arr[$key]) ? $arr[$key] : null;
	}

	/**
	 * Get the correct directory
	 *
	 * @param string $dir
	 * @return string
	 */
	static public function getDir($dir)
	{
		return FISHPIG_BOLT_DIR . DIRECTORY_SEPARATOR . $dir;
	}
	
	/**
	 * Retrieve a file local to the Magento install
	 *
	 * @param string $file
	 * @return string|false
	 */
	static public function getFile($file)
	{
		return is_readable($file) ? @file_get_contents($file) : false;
	}
	
	/**
	 * Get the session handler
	 *
	 * @return Fishpig_Bolt_Session_Abstract
	 */
	static public function getSession()
	{
		if (self::$_session !== NULL) {
			return self::$_session;
		}

		self::$_session = false;
		
		if (ini_get('suhosin.session.encrypt')) {
			return false;
		}
		
		if (self::getConfig('session_save') !== 'files') {
			return false;
		}

		$class = 'Fishpig_Bolt_Session_Files';

		require(self::getDir('lib' . DIRECTORY_SEPARATOR . 'Fishpig' . DIRECTORY_SEPARATOR . 'Bolt' . DIRECTORY_SEPARATOR . 'Session' . DIRECTORY_SEPARATOR . 'Abstract.php'));
		require(self::getDir('lib' . DIRECTORY_SEPARATOR . 'Fishpig' . DIRECTORY_SEPARATOR . 'Bolt' . DIRECTORY_SEPARATOR . 'Session' . DIRECTORY_SEPARATOR . 'Files.php'));
		
		self::$_session = $class;

		$class::init();
		
		return self::$_session;
	}
	
	/**
	  * Get the request protocol
	  *
	  * @return string
	  */
	static public function getRequestProtocol()
	{
		return !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on' ? 'https' : 'http';
	}
	
	/**
	 * Log a message to the debug file
	 *
	 * @param string $msg
	 * @return void
	 */
	static public function log($msg)
	{
		$file = FISHPIG_BOLT_DIR . DSX . 'var' . DSX . 'log' . DSX . 'bolt.log';

		file_put_contents($file, (is_file($file) ? file_get_contents($file) . "\n" : '') . $msg);		
	}
}
