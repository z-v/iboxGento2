<?php
/**
 * @category	Fishpig
 * @package		Fishpig_Bolt
 * @license		http://fishpig.co.uk/license.txt
 * @author		Ben Tideswell <help@fishpig.co.uk>
 */
 
/**
 * Hole punch class
 *
 */
class Fishpig_Bolt_HolePunch
{
	/**
	 * Block apply options
	 *
	 * @const int
	 */
    const APPLY_ALWAYS = 1;
    const APPLY_WITH_CART = 2;
    const APPLY_WITH_CUSTOMER = 3;
    const APPLY_WITH_CART_OR_CUSTOMER = 4;
	
	/**
	 * Array of hole punched blocks
	 * Saved here during exception
	 *
	 * @var static false|array
	 */    
    static protected $_exceptionTransport = false;

	/**
	 * Cache variable to store hole data
	 * Is false if cannot hole punch
	 *
	 * @null|false|array
	 */
	static protected $_validHoles = null;
	
	/**
	 * Default hole punch blocks
	 * This is initialised in self::__construct
	 * 
	 * @param array
	 */
	static protected $_defaultBlocks = array(
		'header' => self::APPLY_WITH_CART_OR_CUSTOMER,
	);
	
	/**
	 * The cache key for the hole punched request
	 *
	 * @var string
	 */
	static protected $_cacheKey = null;
	
	/**
	 * Error reporting level before throwing hole punch exception
	 *
	 * @var int
	 */
	static protected $_errorReportingLevel = null;
	
	/**
	 * Punch holes in $html
	 *
	 * @param string $html
	 * @return void
	 */
	static public function punchHoles(&$html)
	{
		if (self::getValidHoles() === false) {
			return false;
		}

		define('FISHPIG_BOLT_PUNCHING_HOLES', true);

		if (!defined('MAGENTO_ROOT')) {
			define('MAGENTO_ROOT', getcwd());
			require(FISHPIG_BOLT_DIR . DSX . 'app' . DSX . 'Mage.php');
		}

		$cacheAdapter = Fishpig_Bolt_App::getCache();
		$cacheEnabled = (int)Fishpig_Bolt_App::getConfig('holepunch/cache') === 1;
		$cacheKey = self::_getCacheKey();
		$holes = false;

		if ($cacheEnabled && ($holes = $cacheAdapter::load($cacheKey)) !== false) {
			$holes = unserialize($holes);
		}
		else {
			try {
				/**
				 * Hole punch isn't cached so we have to run Magento to generate the HP content
				 * This will throw an exception containing the blocks in JSON format
				 */
				Mage::app(Fishpig_Bolt_App::getConfig('store_code'))->getFrontController()->dispatch();
			}
			catch (Exception $e) {
				error_reporting(self::$_errorReportingLevel);

				$holes = (array)self::$_exceptionTransport;

				if ($cacheEnabled) {
					$cacheAdapter::save($cacheKey, serialize($holes), 21600);
				}
			}
		}
		
		if (is_array($holes)) {
			foreach((array)$holes as $alias => $data) {
				if (preg_match_all('/<\!--BOLT-' . $alias . '-->.*<\!--\/BOLT-' . $alias . '-->/Uis', $html, $matches)) {	
					$html = str_replace($matches[0], str_replace('?___SID=U', '', $data), $html);
				}
			}
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Get the cache key for the holepunch data
	 *
	 * @return string
	 */
	static protected function _getCacheKey()
	{
		if (!is_null(self::$_cacheKey)) {
			return self::$_cacheKey;
		}
		
		$sessionAdapter = Fishpig_Bolt_App::getSession();

		self::$_cacheKey = Fishpig_Bolt_App::generateCacheKey(
			(int)Fishpig_Bolt_App::getConfig('store_id'), 
			Fishpig_Bolt_App::getUserAgentGroup(),
			Fishpig_Bolt_App::getRequestProtocol(),
			implode(DSX, array(
				'base' => 'holepunch',	
				'customer_id' => (int)$sessionAdapter::getData('customer_base/id'),
//				'cart_item_count' => (int)$sessionAdapter::getData('core/cart_item_count'),
				'quote_hash' => $sessionAdapter::getData('core/quote_hash'),
			))
		);
		
		return self::$_cacheKey;
	}
	
	/**
	 * Retrieve all holes valid for current request
	 *
	 * @return false|array
	 */
	static public function getValidHoles()
	{
		if (is_array(self::$_validHoles)) {
			return self::$_validHoles;
		}
		else if (self::$_validHoles === false) {
			return false;
		}
		
		self::$_validHoles = false;
		
		if (($holes = self::getHoles()) === false) {
			return false;
		}

		$isLoggedIn = Fishpig_Bolt_App::isCustomerLoggedIn();
		$hasCart = Fishpig_Bolt_App::hasCartItems();

		$holesToPunch = array();

		foreach($holes as $blockName => $punchType) {
			if ((int)$punchType === self::APPLY_ALWAYS) {
				$holesToPunch[] = $blockName;
			}	
			else if ((int)$punchType === self::APPLY_WITH_CART) {
				if ($hasCart) {
					$holesToPunch[] = $blockName;
				}
			}	
			else if ((int)$punchType === self::APPLY_WITH_CUSTOMER) {
				if ($isLoggedIn) {
					$holesToPunch[] = $blockName;
				}
			}	
			else if ((int)$punchType === self::APPLY_WITH_CART_OR_CUSTOMER) {
				if ($hasCart || $isLoggedIn) {
					$holesToPunch[] = $blockName;
				}
			}
		}

		if ($holesToPunch) {
			self::$_validHoles = $holesToPunch;
		}

		return self::$_validHoles;
	}
	
	/**
	 * Retrieve all hole data
	 *
	 * @return array|false
	 */
	static public function getHoles()
	{
		if (Fishpig_Bolt_App::getConfig('holepunch/enabled') !== '1') {
			return false;
		}
		
		$holes = self::$_defaultBlocks;
		
		if ($customHoles = Fishpig_Bolt_App::getConfig('holepunch/blocks')) {
			if (($customHoles = @unserialize($customHoles)) !== false) {
				foreach($customHoles as $customHole) {
					$holes[$customHole['name']] = $customHole['apply'];
				}
			}
		}

		return $holes ? $holes : false;
	}
	
	/**
	 * Determine whether the request is a hole punch request
	 *
	 * @return bool
	 */
	static public function isPunchingHoles()
	{
		return defined('FISHPIG_BOLT_PUNCHING_HOLES');
	}
	
	/**
	 * Determine whether the hole punch is enabled
	 *
	 * @return bool
	 */
	static public function isEnabled()
	{
		return self::getHoles() !== false;
	}
	
	/**
	 * Throw an exception containing the hole punch content
	 * This is the only way to escape the Magento execution and pass back to Bolt
	 *
	 * @param array $blocks
	 * @return void
	 */
	static public function throwHolePunch(array $blocks)
	{
		self::$_errorReportingLevel = error_reporting(0);
		
		foreach($blocks as $block => $html) {
			if (trim($html) === '') {
				unset($blocks[$block]);
			}
		}
		
		if (count($blocks) > 0) {
			self::$_exceptionTransport = $blocks;
		}

		throw new Exception('Bolt is punching holes.');
	}
}