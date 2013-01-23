<?php

require_once(dirname(__FILE__) . '/../kConf.php');
require_once(dirname(__FILE__) . '/../request/infraRequestUtils.class.php');
require_once(dirname(__FILE__) . '/kCacheManager.php');
require_once(dirname(__FILE__) . '/../request/kSessionBase.class.php');
require_once(dirname(__FILE__) . '/../request/kIpAddressUtils.php');

/**
 * @package server-infra
 * @subpackage cache
 */
class kApiCache
{
	// extra cache fields
	const ECF_REFERRER = 'referrer';
	const ECF_USER_AGENT = 'userAgent';
	const ECF_COUNTRY = 'country';
	const ECF_IP = 'ip';

	// extra cache fields conditions
	// 	the conditions will be applied on the extra fields when generating the cache key
	//	for example, when using country restriction of US allowed, we can take country==US
	//	in the cache key instead of taking the whole country (2 possible cache key values for
	//	the entry, instead of 200)
	const COND_NONE = '';
	const COND_MATCH = 'match';					// used by kCountryCondition
	const COND_REGEX = 'regex';					// used by kUserAgentCondition
	const COND_SITE_MATCH = 'siteMatch';		// used by kSiteCondition
	const COND_IP_RANGE = 'ipRange';			// used by kIpAddressCondition
	
	const EXTRA_KEYS_PREFIX = 'extra-keys-';	// apc cache key prefix

	// cache statuses
	const CACHE_STATUS_ACTIVE = 0;				// cache was not explicitly disabled
	const CACHE_STATUS_ANONYMOUS_ONLY = 1;		// conditional cache was explicitly disabled by calling DisableConditionalCache (e.g. a database query that is not handled by the query cache was issued)
	const CACHE_STATUS_DISABLED = 2;			// cache was explicitly disabled by calling DisableCache (e.g. getContentData for an entry with access control)
	
	const CONDITIONAL_CACHE_EXPIRY = 86400;		// 1 day, must not be greater than the expiry of the query cache keys

	const SUFFIX_DATA =  '.cache';
	const SUFFIX_RULES = '.rules';
	const SUFFIX_LOG = '.log';
	
	const CACHE_VERSION = '1';

	// cache modes
	const CACHE_MODE_ANONYMOUS = 1;				// anonymous caching should be performed - the cached response will not be associated with any conditions
	const CACHE_MODE_CONDITIONAL = 2;			// cache the response along with its matching conditions
		
	const EXPIRY_MARGIN = 300;

	const CACHE_DELIMITER = "\r\n\r\n";
	
	// warm cache constants
	// cache warming is used to maintain continous use of the request caching while preventing a load once the cache expires
	// during WARM_CACHE_INTERVAL before the cache expiry a single request will be allowed to get through and renew the cache
	// this request named warm cache request will block other such requests for WARM_CACHE_TTL seconds

	// header to mark the request is due to cache warming. the header holds the original request protocol http/https
	const WARM_CACHE_HEADER = "X-KALTURA-WARM-CACHE";

	// interval before cache expiry in which to try and warm the cache
	const WARM_CACHE_INTERVAL = 60;

	// time in which a warm cache request will block another request from warming the cache
	const WARM_CACHE_TTL = 10;
	
	// cache instances
	protected $_instanceId = 0;
	protected static $_activeInstances = array();		// active class instances: instanceId => instanceObject
	protected static $_nextInstanceId = 0;

	protected $_cacheStoreTypes = array();
	protected $_cacheStores = array();
	protected $_cacheRules = null;
	protected $_cacheRulesDirty = false;
	protected $_responseMetadata = null;
	protected $_cacheId = null;							// the cache id ensures that the conditions are in sync with the response buffer
	protected $_cacheModes = null;	
	protected static $_cacheWarmupInitiated = false;
	
	// cache key
	protected $_params = array();	
	protected $_cacheKey = "";					// a hash of _params used as the key for caching
	protected $_cacheKeyPrefix = '';			// the prefix of _cacheKey, the cache key is generated by concatenating this prefix with the hash of the params
	protected $_cacheKeyDirty = true;			// true if _params was changed since _cacheKey was calculated
	protected $_originalCacheKey = null;		// the value of the cache key before any extra fields were added to it

	// ks
	protected $_ks = "";
	protected $_ksObj = null;
	protected $_ksPartnerId = null;
	
	// status
	protected $_expiry = 600;
	protected $_cacheStatus = self::CACHE_STATUS_DISABLED;	// enabled after the cacher initializes
	
	// conditional cache fields
	protected $_conditionalCacheExpiry = 0;				// the expiry used for conditional caching, if 0 CONDITIONAL_CACHE_EXPIRY will be used 
	protected $_invalidationKeys = array();				// the list of query cache invalidation keys for the current request
	protected $_invalidationTime = 0;					// the last invalidation time of the invalidation keys

	// extra fields
	protected $_extraFields = array();
	protected $_referrers = array();				// a request can theoritically have more than one referrer, in case of several baseEntry.getContextData calls in a single multirequest
	protected static $_country = null;				// caches the country of the user issuing this request
	protected static $_usesHttpReferrer = false;	// enabled if the request is dependent on the http referrer field (opposed to an API parameter referrer)
	protected static $_hasExtraFields = false;		// set to true if the response depends on http headers and should not return caching headers to the user / cdn
	
	protected function __construct($cacheTypes, $params = null)
	{
		$this->_instanceId = self::$_nextInstanceId;  
		self::$_nextInstanceId++;

		$this->_cacheStoreTypes = $cacheTypes; 
		
		if ($params)
			$this->_params = $params;
		else
			$this->_params = infraRequestUtils::getRequestParams();

		if (!kConf::get('enable_cache') || 
			$this->isCacheDisabled())
		{
			self::disableCache();
			return;
		}
		
		$ks = $this->getKs();
		if ($ks === false)
		{
			self::disableCache();
			return;
		}

		// if the request triggering the cache warmup was an https request, fool the code to treat the current request as https as well 
		$warmCacheHeader = self::getRequestHeaderValue(self::WARM_CACHE_HEADER);
		if ($warmCacheHeader == "https")
			$_SERVER['HTTPS'] = "on";
	
		$this->addKsData($ks);
		$this->addInternalCacheParams();
		
		if (!$this->init())
		{
			self::disableCache();
			return;
		}
				
		$this->enableCache();
	}
	
	protected function init()			// overridable
	{
		return true;
	}

	protected function getKs()			// overridable
	{
		$ks = isset($this->_params['ks']) ? $this->_params['ks'] : '';
		unset($this->_params['ks']);
		return $ks;
	}
	
	protected function addKSData($ks)
	{
		$this->_ks = $ks;
		$this->_ksObj = kSessionBase::getKSObject($ks);
		$this->_ksPartnerId = ($this->_ksObj ? $this->_ksObj->partner_id : null);
		$this->_params["___cache___partnerId"] =  $this->_ksPartnerId;
		$this->_params["___cache___ksType"] = 	  ($this->_ksObj ? $this->_ksObj->type		 : null);
		$this->_params["___cache___userId"] =     ($this->_ksObj ? $this->_ksObj->user		 : null);
		$this->_params["___cache___privileges"] = ($this->_ksObj ? $this->_ksObj->privileges : null);
	}
	
	protected function addInternalCacheParams()
	{
		$this->_params['___cache___protocol'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? "https" : "http";
		$this->_params['___cache___host'] = @$_SERVER['HTTP_HOST'];
		$this->_params['___cache___version'] = self::CACHE_VERSION;
		$this->_params['___internal'] = intval(kIpAddressUtils::isInternalIp());
	}

	protected function isCacheDisabled()
	{
		// check the clientTag parameter for a cache start time (cache_st:<time>) directive
		if (isset($this->_params['clientTag']))
		{
			$clientTag = $this->_params['clientTag'];
			$matches = null;
			if (preg_match("/cache_st:(\\d+)/", $clientTag, $matches))
			{
				if ($matches[1] > time())
				{
					return true;
				}
			}
		}
				
		if (isset($this->_params['nocache']))
		{
			return true;
		}
		
		return false;
	}
	
	// enable / disable functions
	protected function enableCache()
	{
		self::$_activeInstances[$this->_instanceId] = $this;
		$this->_cacheStatus = self::CACHE_STATUS_ACTIVE;
	}
	
	public static function disableCache()
	{
		foreach (self::$_activeInstances as $curInstance)
		{
			$curInstance->_cacheStatus = self::CACHE_STATUS_DISABLED;
		}
		self::$_activeInstances = array();
	}

	public static function disableConditionalCache()
	{
		foreach (self::$_activeInstances as $curInstance)
		{
			// no need to check for CACHE_STATUS_DISABLED, since the instances are removed from the list when they get this status
			$curInstance->_cacheStatus = self::CACHE_STATUS_ANONYMOUS_ONLY;
		}
	}
	
	protected function removeFromActiveList()
	{
		unset(self::$_activeInstances[$this->_instanceId]);
	}
	
	public static function isCacheEnabled()
	{
		return count(self::$_activeInstances);
	}
	
	// expiry control functions
	public static function setExpiry($expiry)
	{
		foreach (self::$_activeInstances as $curInstance)
		{
			if ($curInstance->_expiry && $curInstance->_expiry < $expiry)
				continue;
			$curInstance->_expiry = $expiry;
		}
	}
	
	public static function setConditionalCacheExpiry($expiry)
	{
		foreach (self::$_activeInstances as $curInstance)
		{
			if ($curInstance->_conditionalCacheExpiry && $curInstance->_conditionalCacheExpiry < $expiry)
				continue;
			$curInstance->_conditionalCacheExpiry = $expiry;
		}
	}
	
	// conditional cache
	public static function addInvalidationKeys($invalidationKeys, $invalidationTime)
	{
		foreach (self::$_activeInstances as $curInstance)
		{
			$curInstance->_invalidationKeys = array_merge($curInstance->_invalidationKeys, $invalidationKeys);
			$curInstance->_invalidationTime = max($curInstance->_invalidationTime, $invalidationTime);
		}
	}
	
	// extra fields functions
	static public function hasExtraFields()
	{
		return self::$_hasExtraFields;
	}
	
	static public function addExtraField($extraField, $condition = self::COND_NONE, $refValue = null)
	{
		foreach (self::$_activeInstances as $curInstance)
		{
			$curInstance->addExtraFieldInternal($extraField, $condition, $refValue);
		}

		// the following code is required since there are no active cache instances in thumbnail action
		// and we need _hasExtraFields to be correct
		if ($extraField != self::ECF_REFERRER || self::$_usesHttpReferrer)
			self::$_hasExtraFields = true;
	}

	static protected function getCountry()
	{
		if (is_null(self::$_country))
		{
			require_once(dirname(__FILE__) . '/../request/kIP2Location.php');
			$ipAddress = infraRequestUtils::getRemoteAddress();
			self::$_country = kIP2Location::ipToCountry($ipAddress);
		}
		return self::$_country;
	}

	static public function getHttpReferrer()
	{
		self::$_usesHttpReferrer = true;
		return isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : '';
	}
	
	protected function getFieldValues($extraField)
	{
		switch ($extraField)
		{
		case self::ECF_REFERRER:
			$values = array();
			// a request can theoritically have more than one referrer, in case of several baseEntry.getContextData calls in a single multirequest
			foreach ($this->_referrers as $referrer)
			{
				$values[] = infraRequestUtils::parseUrlHost($referrer);
			}
			return $values;

		case self::ECF_USER_AGENT:
			if (isset($_SERVER['HTTP_USER_AGENT']))
				return array($_SERVER['HTTP_USER_AGENT']);
			break;
		
		case self::ECF_COUNTRY:
			return array(self::getCountry());

		case self::ECF_IP:
			return array(infraRequestUtils::getRemoteAddress());
		}
		
		return array();
	}
	
	protected function applyCondition($fieldValue, $condition, $refValue)
	{
		switch ($condition)
		{			
		case self::COND_MATCH:
			if (!count($refValue))
				return null;
			return in_array($fieldValue, $refValue);
			
		case self::COND_REGEX:
			if (!count($refValue))
				return null;
			foreach($refValue as $curRefValue)
			{
				if ($fieldValue === $curRefValue || 
					preg_match("/$curRefValue/i", $fieldValue))
					return true;
			}
			return false;	

		case self::COND_SITE_MATCH:
			if (!count($refValue))
				return null;
			foreach($refValue as $curRefValue)
			{
				if ($fieldValue === $curRefValue || 
					strpos($fieldValue, "." . $curRefValue) !== false)
					return true;
			}
			return false;

		case self::COND_IP_RANGE:
			if (!count($refValue))
				return null;
			foreach($refValue as $curRefValue)
			{
				if (kIpAddressUtils::isIpInRange($fieldValue, $curRefValue))
					return true;
			}
			return false;
		}
		return $fieldValue;
	}
	
	protected function getConditionKey($condition, $refValue)
	{
		switch ($condition)
		{			
		case self::COND_REGEX:
		case self::COND_MATCH:
		case self::COND_SITE_MATCH:
			return "_{$condition}_" . implode(',', $refValue);
		case self::COND_IP_RANGE:
			return "_{$condition}_" . implode(',', str_replace('/', '_', $refValue)); // ip range can contain slashes
		}
		return '';
	}
	
	protected function addExtraFieldInternal($extraField, $condition, $refValue)
	{
		$extraFieldParams = array($extraField, $condition, $refValue);
		if (in_array($extraFieldParams, $this->_extraFields))
			return;			// already added
		$this->_extraFields[] = $extraFieldParams;
		if ($extraField != self::ECF_REFERRER || self::$_usesHttpReferrer)
			self::$_hasExtraFields = true;
		
		foreach ($this->getFieldValues($extraField) as $valueIndex => $fieldValue)
		{
			$conditionResult = $this->applyCondition($fieldValue, $condition, $refValue);
			$key = "___cache___{$extraField}_{$valueIndex}" . $this->getConditionKey($condition, $refValue);
			$this->_params[$key] = $conditionResult;
		}
		
		$this->_cacheKeyDirty = true;
	}

	protected function addExtraFields()
	{
		$extraFieldsCache = kCacheManager::getCache(kCacheManager::APC);
		if (!$extraFieldsCache)
			return;
		
		$extraFields = $extraFieldsCache->get(self::EXTRA_KEYS_PREFIX . $this->_cacheKey);
		if (!$extraFields)
			return;
		
		foreach ($extraFields as $extraFieldParams)
		{
			call_user_func_array(array('kApiCache', 'addExtraField'), $extraFieldParams);
			call_user_func_array(array($this, 'addExtraFieldInternal'), $extraFieldParams);			// the current instance may have not been activated yet
		}
		
		$this->finalizeCacheKey();
	}
	
	protected function storeExtraFields()
	{
		if (!$this->_cacheKeyDirty)
			return;			// no extra fields were added to the cache

		$extraFieldsCache = kCacheManager::getCache(kCacheManager::APC);
		if (!$extraFieldsCache)
		{
			self::disableCache();
			return;
		}
		
		if ($extraFieldsCache->set(self::EXTRA_KEYS_PREFIX . $this->_originalCacheKey, $this->_extraFields, self::CONDITIONAL_CACHE_EXPIRY) === false)
		{
			self::disableCache();
			return;
		}
		
		$this->finalizeCacheKey();			// update the cache key to include the extra fields
	}
	
	protected function finalizeCacheKey()
	{
		if (!$this->_cacheKeyDirty)
			return;
		$this->_cacheKeyDirty = false;
	
		ksort($this->_params);
		$this->_cacheKey = $this->_cacheKeyPrefix . md5( http_build_query($this->_params, '', '&') );		// we have to explicitly set the separator since symfony changes it to '&amp;'
		if (is_null($this->_originalCacheKey))
			$this->_originalCacheKey = $this->_cacheKey;
	}

	// cache read functions
	protected static function getMaxInvalidationTime($invalidationKeys)
	{
		$memcache = kCacheManager::getCache(kCacheManager::MC_GLOBAL_KEYS);
		if (!$memcache)
			return null;

		$cacheResult = $memcache->multiGet($invalidationKeys);
		if ($cacheResult === false)
			return null;			// failed to get the invalidation keys
			
		if (!$cacheResult)
			return 0;				// no invalidation keys - no changes occured
			
		return max($cacheResult);
	}

	protected function validateCachingRules($isWarmupRequest)
	{
		foreach ($this->_cacheRules as $rule)
		{
			list($cacheExpiry, $expiryInterval, $conditions) = $rule;
		
			$cacheTTL = $cacheExpiry - time(); 
			if($cacheTTL <= 0)
			{
				// the cache is expired
				continue;
			}
				
			if ($conditions)
			{
				list($this->_cacheId, $invalidationKeys, $cachedInvalidationTime) = $conditions;
				$invalidationTime = self::getMaxInvalidationTime($invalidationKeys);
				if ($invalidationTime === null)		
					continue;					// failed to get the invalidation time from memcache, can't use cache
					
				if ($cachedInvalidationTime < $invalidationTime)
					continue;					// something changed since the response was cached

				if (isset($this->_cacheRules[self::CACHE_MODE_ANONYMOUS]))
				{
					// since the conditions matched, we can extend the expiry of the anonymous cache
					list($cacheExpiry, $expiryInterval, $conditions) = $this->_cacheRules[self::CACHE_MODE_ANONYMOUS];
					$cacheExpiry = time() + $expiryInterval;
					$this->_cacheRules[self::CACHE_MODE_ANONYMOUS] = array($cacheExpiry, $expiryInterval, $conditions);
					$this->_cacheRulesDirty = true;
				}
			}
			else if ($isWarmupRequest)
			{
				// if there are no conditions and this is a cache warmup request, don't use the cache
				continue;
			}
			else if ($cacheTTL < self::WARM_CACHE_INTERVAL) // 1 minute left for cache, lets warm it
			{
				self::warmCache($this->_cacheKey);	
			}
			
			return true;
		}
		
		return false;
	}
	
	protected function getCacheStoreForRead()
	{
		if ($this->_ks && (!$this->_ksObj || !$this->_ksObj->tryToValidateKS()))
			return null;					// ks not valid, do not return from cache
	
		// if the request is for warming the cache, disregard the cache and run the request
		$warmCacheHeader = self::getRequestHeaderValue(self::WARM_CACHE_HEADER);
		if ($warmCacheHeader !== false)
		{
			// make a trace in the access log of this being a warmup call
			header("X-Kaltura:cached-warmup-$warmCacheHeader,".$this->_cacheKey, false);
		}
		
		foreach ($this->_cacheStoreTypes as $cacheType)
		{
			$cacheStore = kCacheManager::getCache($cacheType);
			if (!$cacheStore)
			{
				continue;
			}
		
			$cacheRules = $cacheStore->get($this->_cacheKey . self::SUFFIX_RULES);
			if ($cacheRules)
			{
				$this->_cacheRules = unserialize($cacheRules);
				if ($this->validateCachingRules($warmCacheHeader !== false))
				{
					return $cacheStore;
				}
			}
			
			$this->_cacheRules = null;
			$this->_cacheStores[] = $cacheStore;
		}
		
		return null;
	}	

	/**
	 * This functions checks if a certain response resides in cache.
	 * In case it does, the response is returned from cache and a response header is added.
	 * There are two possibilities on which this function is called:
	 * 1)	The request is a single 'stand alone' request (maybe this request is a multi request containing several sub-requests)
	 * 2)	The request is a single request that is part of a multi request (sub-request in a multi request)
	 * 
	 * in case this function is called when handling a sub-request (single request as part of a multirequest) it
	 * is preferable to change the default $cacheHeaderName
	 * 
	 * @param $cacheHeaderName - the header name to add
	 * @param $cacheHeader - the header value to add
	 */	 
	public function checkCache($cacheHeaderName = 'X-Kaltura', $cacheHeader = 'cached-dispatcher')
	{
		if ($this->_cacheStatus == self::CACHE_STATUS_DISABLED)
			return false;
		
		$startTime = microtime(true);
		$cacheStore = $this->getCacheStoreForRead();
		if (!$cacheStore)
		{
			return false;
		}
		
		$cacheResult = $cacheStore->get($this->_cacheKey . self::SUFFIX_DATA);
		if (!$cacheResult)
		{
			$this->_cacheStores[] = $cacheStore;
			return false;
		}

		list($cacheId, $responseMetadata, $response) = explode(self::CACHE_DELIMITER, $cacheResult, 3);
		if ($this->_cacheId && $this->_cacheId != $cacheId)
		{
			$this->_cacheStores[] = $cacheStore;
			return false;
		}

		$this->_responseMetadata = $responseMetadata;
		
		if ($this->_cacheRulesDirty)
		{
			$maxExpiry = $this->getMaxExpiryFromRules();
			$cacheStore->set($this->_cacheKey . self::SUFFIX_RULES, serialize($this->_cacheRules), $maxExpiry + self::EXPIRY_MARGIN);
		}
		
		$this->saveToCacheStores($response);		
		
		// in case of multirequest, we must not condtionally cache the multirequest when a sub request comes from cache
		// for single requests, the next line has no effect
		self::disableConditionalCache();

		$processingTime = microtime(true) - $startTime;
		if (self::hasExtraFields() && $cacheHeaderName == 'X-Kaltura')
			$cacheHeader = 'cached-with-extra-fields';
		header("$cacheHeaderName:$cacheHeader,$this->_cacheKey,$processingTime", false);

		return $response;
	}
	
	// cache write functions
	protected function isAnonymous($ks)					// overridable
	{
		if(kIpAddressUtils::isInternalIp())
		{
			return false;
		}			
		return (!$ks || (!$ks->isAdmin() && ($ks->user === "0" || $ks->user === null)));
	}
	
	protected function getAnonymousCachingExpiry()		// overridable
	{
		return $this->_expiry;
	}
	
	protected function initCacheModes()
	{
		if (!is_null($this->_cacheModes))
			return;
		
		$this->_cacheModes = array();
		if ($this->_cacheStatus == self::CACHE_STATUS_DISABLED)
			return;

		if ($this->_ks && (!$this->_ksObj || !$this->_ksObj->tryToValidateKS()))
		{
			self::disableCache();
			return;
		}
		
		$isAnonymous = $this->isAnonymous($this->_ksObj);				
		if (!$isAnonymous && $this->_cacheStatus == self::CACHE_STATUS_ANONYMOUS_ONLY)
		{
			self::disableCache();
			return;
		}
		
		if ($isAnonymous)
			$this->_cacheModes[] = self::CACHE_MODE_ANONYMOUS;
		
		if ($this->_cacheStatus != self::CACHE_STATUS_ANONYMOUS_ONLY)
			$this->_cacheModes[] = self::CACHE_MODE_CONDITIONAL;
	}

	protected function calculateCacheRules()
	{
		$this->_cacheRules = array();
		foreach ($this->_cacheModes as $cacheMode)
		{
			$conditions = null;
			
			switch ($cacheMode)
			{
			case self::CACHE_MODE_CONDITIONAL:
				$conditions = array($this->_cacheId, array_unique($this->_invalidationKeys), $this->_invalidationTime);
				if ($this->_conditionalCacheExpiry)
					$expiry = $this->_conditionalCacheExpiry;
				else
					$expiry = self::CONDITIONAL_CACHE_EXPIRY;
				break;

			case self::CACHE_MODE_ANONYMOUS:
				$expiry = $this->getAnonymousCachingExpiry();
				break;
			}
			
			$this->_cacheRules[$cacheMode] = array(time() + $expiry, $expiry, $conditions);
		}
	}
	
	public function storeCache($response, $responseMetadata = "", $serializeResponse = false)
	{
		// remove $this from the list of active instances - the request is complete
		$this->removeFromActiveList();
	
		$this->initCacheModes();
		if (!$this->_cacheModes)
			return;
			
		if ($serializeResponse)
			$response = serialize($response);
			
		$this->storeExtraFields();

		// set the X-Kaltura header only if it does not exist or contains 'cache-key'
		// the header is overwritten for cache-key so that for a multirequest we'll get the key of
		// the entire request and not just the last request
		$headers = headers_list();
		$foundHeader = false;
		foreach($headers as $header)
		{
			if (strpos($header, 'X-Kaltura') === 0 && strpos($header, 'cache-key') === false)
			{
				$foundHeader = true;
				break;
			}
		}

		if (!$foundHeader)
			header("X-Kaltura: cache-key,".$this->_cacheKey);
		
		$this->_responseMetadata = $responseMetadata;
		$this->_cacheId = microtime(true) . '_' . getmypid();
		
		$this->calculateCacheRules();
		
		$this->saveToCacheStores($response);
	}

	protected function saveToCacheStores($response)
	{
		$maxExpiry = $this->getMaxExpiryFromRules();
	
		foreach ($this->_cacheStores as $curCacheStore)
		{
			//$curCacheStore->set($this->_cacheKey . self::SUFFIX_LOG, print_r($this->_params, true), $maxExpiry + self::EXPIRY_MARGIN);	
			$curCacheStore->set($this->_cacheKey . self::SUFFIX_RULES, serialize($this->_cacheRules), $maxExpiry + self::EXPIRY_MARGIN);
			$curCacheStore->set($this->_cacheKey . self::SUFFIX_DATA, implode(self::CACHE_DELIMITER, array($this->_cacheId, $this->_responseMetadata, $response)), $maxExpiry);
		}
	}
	
	// cache warmup functions
	protected static function getRequestHeaders()
	{
		if(function_exists('apache_request_headers'))
			return apache_request_headers();
		
		foreach($_SERVER as $key => $value)
		{
			if(substr($key, 0, 5) == "HTTP_")
			{
				$key = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
				$out[$key] = $value;
			}
		}
		return $out;
	}

	// warm cache by sending the current request asynchronously via a socket to localhost
	// apc is used to flag that an existing warmup request is already running. The flag has a TTL of 10 seconds, 
	// so in the case the warmup request failed another one can be ran after 10 seconds.
	// finalize IP passing (use getRemoteAddr code)
	// can the warm cache header get received via a warm request passed from the other DC?
	protected function warmCache($key)
	{
		if (self::$_cacheWarmupInitiated)
			return;
			
		self::$_cacheWarmupInitiated = true;
	
		// require apc for checking whether warmup is already in progress
		if (!function_exists('apc_fetch'))
			return;

		$key = "cache-warmup-$key";

		// abort warming if a previous warmup started less than 10 seconds ago
		if (apc_fetch($key) !== false)
			return;

		// flag we are running a warmup for the current request
		apc_store($key, true, self::WARM_CACHE_TTL);

		$uri = $_SERVER["REQUEST_URI"];

		$fp = fsockopen('127.0.0.1', 80, $errno, $errstr, 1);

		if ($fp === false)
		{
			error_log("warmCache - Couldn't open a socket [".$uri."]", 0);
			return;
		}

		$method = $_SERVER["REQUEST_METHOD"];

		$out = "$method $uri HTTP/1.1\r\n";

		$sentHeaders = self::getRequestHeaders();
		$sentHeaders["Connection"] = "Close";

		// mark request as a warm cache request in order to disable caching and pass the http/https protocol (the warmup always uses http)
		$sentHeaders[self::WARM_CACHE_HEADER] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? "https" : "http";

		// if the request wasn't proxied pass the ip on the X-FORWARDED-FOR header
		$ipHeader = infraRequestUtils::getSignedIpAddressHeader();
		if ($ipHeader)
		{
			list($headerName, $headerValue) = $ipHeader;
			$sentHeaders[$headerName] = $headerValue; 
		}

		foreach($sentHeaders as $header => $value)
		{
			$out .= "$header:$value\r\n";
		}

		$out .= "\r\n";

		if ($method == "POST")
		{
			$postParams = array();
			foreach ($_POST as $key => &$val) {
				if (is_array($val)) $val = implode(',', $val);
				{
					$postParams[] = $key.'='.urlencode($val);
				}
			}

			$out .= implode('&', $postParams);
		}

		fwrite($fp, $out);
		fclose($fp);
	}

	// utility functions
	protected function getMaxExpiryFromRules()
	{
		$maxExpiry = 0;
		$curTime = time();
		foreach ($this->_cacheRules as $cacheRule)
		{
			$expiryTime = reset($cacheRule);				
			$maxExpiry = max($maxExpiry, $expiryTime - $curTime);		// expiryTime-curTime may be negative, but it doesn't matter since maxExpiry was initialized to 0
		}
		
		return $maxExpiry;
	}
	
	protected static function getRequestHeaderValue($headerName)
	{
		$headerName = "HTTP_".str_replace("-", "_", strtoupper($headerName));

		if (!isset($_SERVER[$headerName]))
			return false;

		return $_SERVER[$headerName];
	}

	/**
	 * @return int
	 */
	public static function getTime()
	{
		self::setConditionalCacheExpiry(600);
		return time();
	}
}
