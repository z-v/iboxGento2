<?php
/**
 * @category	Fishpig
 * @package		Fishpig_Bolt
 * @license		http://fishpig.co.uk/license.txt
 * @author		Ben Tideswell <help@fishpig.co.uk>
 */
 
/**
 * Class handle all caching of html
 *
 */
class Fishpig_Bolt_Adapter_Memcache
{
	/**
	 * Store the Memcache adapter
	 *
	 * @var Memcache|false
	 */
	protected $_adapter = null;

	/**
	 * If set, cache prefix will be added to all cache keys
	 *
	 * @param string $_cachePrefixKey = false
	 */
	protected $_cachePrefixKey = false;
	
	/**
	 * Cache prefix value
	 *
	 * @param null|int
	 */
	protected $_cachePrefix = false;
	
	/**
	 * Initialise the object
	 *
	 * @param string $cachePrefixKey
	 */
	public function __construct(array $servers, $cachePrefixKey = null)
	{
		if (count($servers) === 0) {
			throw new Exception('No Memcache servers specified.');
		}

		if(!class_exists('Memcache', false)) {
			throw new Exception('Memcache is not installed on this server.');
		}
		
		$this->_adapter = new Memcache;;

		foreach($servers as $server) {
			$this->_adapter->addServer($server['host'], $server['port']);
		}

		if (!$this->_adapter->getVersion()) {
			throw new Exception('Unable to connect to Memcache servers.');
		}

		if ($cachePrefixKey) {
			$this->_cachePrefixKey = $cachePrefixKey;
		}		
	}

	/**
	 * Retrieve a cached page by it's ID
	 *
	 * @param string $id
	 * @return string|false
	 */
	 public function get($key)
	 {
		 return trim($this->_getAdapter()->get($this->_getKeyWithPrefix($key)));
	}

	/**
	 * Add a value to the cache
	 *
	 * @param string $key
	 * @param string $value
	 * @param int $lifetime = null
	 */
	public function set($key, $value, $lifetime = 0)
	{
		$key = $this->_getKeyWithPrefix($key);
		$flag = $this->_getCompressionFlag();
		
		if (!$this->_getAdapter()->replace($key, $value, $flag, $lifetime)) {
			$this->_getAdapter()->set($key, $value, $flag, $lifetime);	
		}
		
		return $this;
	}

	/**
	 * Flush the hole cache
	 * This is done by incrementing the prefix
	 *
	 * @return $this
	 */
	public function flush()
	{
		return $this->_incrementPrefix();
	}
	
	/**
	 * Actually delete item from server
	 *
	 * @param string $key
	 * @return $this
	 */
	public function delete($key)
	{
		$this->_getAdapter()->set($this->_getKeyWithPrefix($key), null, 0, -1);
		
		return $this;
	}
	
	/**
	 *
	 * @return Memcache
	 */
	protected function _getAdapter()
	{
		return $this->_adapter;
	}
	
	/**
	 * Get the current key prefix (if enabled)
	 *
	 * @return false|int
	 */
	protected function _getPrefix()
	{
		if ($this->_cachePrefix !== false) {
			return $this->_cachePrefix; // Cache prefix already retreived
		}
		
		if ($this->_cachePrefixKey === false) {
			return false; // Cache prefix's not used
		}

		if (!($prefix = $this->_getAdapter()->get($this->_cachePrefixKey))) {
			$this->_getAdapter()->set($this->_cachePrefixKey, 1, null, 0); // Cache prefix not set so save
		}
		
		return $this->_cachePrefix = $prefix ? $prefix : 1;
	}
	
	/**
	 * Increment the cache prefix
	 *
	 * @return $this
	 */
	protected function _incrementPrefix()
	{
		if ($this->_cachePrefixKey) {
			$this->_cachePrefix = $this->_getPrefix()+1;
			$this->_getAdapter()->increment($this->_cachePrefixKey, 1);
		}
		
		return $this;
	}
	
	/**
	 * Retrieve the key with the prefix prepended
	 *
	 * @param string $key
	 * @return string
	 */
	protected function _getKeyWithPrefix($key)
	{
		if ($this->_cachePrefixKey) {
			return 'BOLT_' . $this->_getPrefix() . '/' . $key;
		}
		
		return $key;
	}
	
	/**
	 * Get the compression flag
	 *
	 * @return int|null
	 */
	protected function _getCompressionFlag()
	{
		return $this->_isCompressionEnabled()
			? MEMCACHE_COMPRESSED
			: null;
	}
}