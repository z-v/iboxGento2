<?php
/**
 * @category	Fishpig
 * @package		Fishpig_Bolt
 * @license		http://fishpig.co.uk/license.txt
 * @author		Ben Tideswell <help@fishpig.co.uk>
 */

class Fishpig_Bolt_Helper_Cache extends Mage_Core_Helper_Abstract
{	
	/**
	 * Refresh a URL
	 *
	 * @param string $url
	 * @param array|int $storeIds
	 * @param bool $subpages = false
	 * @return $this
	 */
	public function refreshUrl($url, $storeIds, $subpages = true)
	{
		if ($_boltApp = $this->getApp()) {
			if (!is_array($storeIds)) {
				$storeIds = array($storeIds);
			}

			foreach($storeIds as $storeId) {
				foreach($this->_getUserAgents($storeId) as $useragent) {
					foreach(array('http', 'https') as $protocol) {
						$_boltApp::delete((int)$storeId, $useragent, $protocol, $url, $subpages);
					}
				}
			}
		}
		
		return $this;
	}

	/**
	 * Refresh a product . Can also refresh related category URLs
	 * All sub pages are automatically refreshed
	 *
	 * @param Mage_Catalog_Model_Product $product
	 * @param bool $includeCategories = true
	 * @return $this
	 */
	public function refreshProduct(Mage_Catalog_Model_Product $product, $includeCategories = true)
	{
		if ($productUris = $this->_getAllProductUrls($product)) {
			foreach($productUris as $uri => $storeIdString) {
				$this->refreshUrl($uri, explode(',', $storeIdString));
			}
		}
		
		if ($includeCategories && $categoryIds = $product->getCategoryIds()) {
			$category = Mage::getModel('catalog/category');

			foreach($categoryIds as $categoryId) {
				$this->refreshCategory($category->setId($categoryId));
			}
		}

		return $this;
	}
	
	/**
	 * Refresh a category cache record
	 * All sub pages are automatically refreshed
	 *
	 * @param Mage_Catalog_Model_Category $category
	 * @return $this
	 */
	public function refreshCategory(Mage_Catalog_Model_Category $category)
	{
		if ($categoryUris = $this->_getAllCategoryUrls($category)) {
			foreach($categoryUris as $uri => $storeIdString) {
				$this->refreshUrl($uri, explode(',', $storeIdString));
			}
		}

		return $this;
	}
	
	/**
	 * Refresh a CMS page cache record
	 * All sub pages are automatically refreshed
	 *
	 * @param Mage_Cms_Model_Page $page
	 * @return $this
	 */
	public function refreshCmsPage(Mage_Cms_Model_Page $page)
	{
		if ($pageUris = $this->_getAllCmsPageUrls($page)) {
			foreach($pageUris as $uri => $storeIdString) {
				$this->refreshUrl($uri, explode(',', $storeIdString));
			}
		}

		return $this;
	}

	/**
	 * Send a flush message to the cache adapter
	 *
	 * @return $this
	 */
	public function flush()
	{
		return ($_boltApp = $this->getApp()) ? $_boltApp::flush() : false;
	}

	/**
	 * Retrieve all product URL's categorised by store for a specific product
	 *
	 * @param Mage_Catalog_Model_Product $product
	 * @return false|array
	 */
	protected function _getAllProductUrls(Mage_Catalog_Model_Product $product)
	{
		if (!($product instanceof Mage_Catalog_Model_Product) || !$product->getId()) {
			return false;
		}

		$resource = Mage::getSingleton('core/resource');
		$db = $resource->getConnection('core_read');
		
		$select = $db->select()
			->from($resource->getTableName('core/url_rewrite'), array('request_path', 'store_ids' => new Zend_Db_Expr('GROUP_CONCAT(store_id)')))
			->where('product_id=?', $product->getId())
			->where('request_path <> ?', '')
			->group('request_path');
			
		return $db->fetchPairs($select);
	}

	/**
	 * Retrieve all product URL's categorised by store for a specific product
	 *
	 * @param Mage_Catalog_Model_Product $product
	 * @return false|array
	 */
	protected function _getAllCategoryUrls(Mage_Catalog_Model_Category $category)
	{
		if (!($category instanceof Mage_Catalog_Model_Category) || !$category->getId()) {
			return false;
		}

		$resource = Mage::getSingleton('core/resource');
		$db = $resource->getConnection('core_read');
		
		$select = $db->select()
			->from($resource->getTableName('core/url_rewrite'), array('request_path', 'store_ids' => new Zend_Db_Expr('GROUP_CONCAT(store_id)')))
			->where('category_id=?', $category->getId())
			->where('request_path <> ?', '')
			->group('request_path');
			
		return $db->fetchPairs($select);
	}

	/**
	 * Retrieve all URL's for $page
	 *
	 * @param Mage_Cms_Model_Page $page
	 * @return false|array
	 */
	protected function _getAllCmsPageUrls(Mage_Cms_Model_Page $page)
	{
		if (!$page->getStores()) {
			return false;
		}

		$storeIds = $page->getStores();
			
		if (count($storeIds) === 1 && $storeIds[0] == 0) {
			$storeIds = array_keys(Mage::app()->getStores());
		}

		$storeIdString = implode(',', $storeIds);
		
		$urls = array(
			$page->getIdentifier() => $storeIdString,
		);

		foreach($storeIds as $storeId) {
			if ($page->getIdentifier() == Mage::getStoreConfig('web/default/cms_home_page', $storeId)) {
				$urls['/'] = $storeIdString;
			}
		}

		return $urls;
	}
	
	/**
	 * Get the App model
	 *
	 * @return false|Fishpig_Bolt_App
	 */
	public function getApp()
	{
		return defined('FISHPIG_BOLT') ? 'Fishpig_Bolt_App' : false;
	}
	
	/**
	 * Get an array of user agents for a store
	 *
	 * @param int $storeId
	 * @return array
	 */
	protected function _getUserAgents($storeId)
	{
		return array(
			'ua_default',
			'ua_mobile',
			'ua_tablet',
		);
	}
}
