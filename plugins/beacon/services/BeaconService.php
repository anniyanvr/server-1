<?php

/**
 * Sending beacons on objects
 *
 * @service beacon
 * @package plugins.beacon
 * @subpackage api.services
 */
class BeaconService extends KalturaBaseService
{
	public function initService($serviceId, $serviceName, $actionName)
	{
		if (($actionName == 'getLast' || $actionName == 'enhanceSearch') && !kCurrentContext::$is_admin_session)
			throw new KalturaAPIException(KalturaErrors::SERVICE_FORBIDDEN, $this->serviceName . '->' . $this->actionName);
		
		parent::initService($serviceId, $serviceName, $actionName);
	}
	
	/**
	 * @action add
	 * @param KalturaBeacon $beacon
	 * @param KalturaNullableBoolean $shouldLog
	 * @return bool
	 */
	public function addAction(KalturaBeacon $beacon, $shouldLog = KalturaNullableBoolean::FALSE_VALUE)
	{
		$beaconObj = $beacon->toInsertableObject();
		$beaconObj->index($shouldLog);
		
		return true;
	}
	
	/**
	 * @action list
	 * @param KalturaBeaconFilter $filter
	 * @param KalturaFilterPager $pager
	 * @return KalturaBeaconListResponse
	 * @throws KalturaAPIException
	 */
	public function listAction(KalturaBeaconFilter $filter = null, KalturaFilterPager $pager = null)
	{
		if (!$filter)
			$filter = new KalturaBeaconFilter();
		
		if (!$pager)
			$pager = new KalturaFilterPager();
		
		return $filter->getListResponse($pager);
	}
	
	/**
	 * @action enhanceSearch
	 * @param KalturaBeaconEnhanceFilter $filter
	 * @param KalturaFilterPager $pager
	 * @return KalturaBeaconListResponse
	 * @throws KalturaAPIException
	 */
	
	public function enhanceSearchAction(KalturaBeaconEnhanceFilter $filter = null, KalturaFilterPager $pager = null)
	{
		if (!$filter)
			$filter = new KalturaBeaconEnhanceFilter();
		
		if(!$pager)
			$pager = new KalturaFilterPager();
		
		return $filter->enhanceSearch($pager);
	}

	/**
	 * @action searchScheduledResource
	 * @param KalturaBeaconSearchParams $searchParams
	 * @param KalturaPager $pager
	 * @return KalturaBeaconListResponse
	 * @throws KalturaAPIException
	 */

	public function searchScheduledResourceAction(KalturaBeaconSearchParams $searchParams, KalturaPager $pager = null)
	{
		$scheduledResourceSearch = new kScheduledResourceSearch();
		$searchMgr = new kBeaconSearchQueryManger();
		//$elasticResponse = $this->initAndSearch($scheduledResourceSearch, $searchParams, $pager);
		$elasticResponse = $searchMgr->search($this->getMockUpQuery());
		$totalCount = $searchMgr->getTotalCount($elasticResponse);
		$responseArray = $searchMgr->getHitsFromElasticResponse($elasticResponse);
		$response = new KalturaBeaconListResponse();
		$response->objects = KalturaBeaconArray::fromDbArray($responseArray);
		$response->totalCount = $totalCount;
		return $response;
	}

	private function getMockUpQuery()
	{
		$query = array();
		$query[kESearchQueryManager::BODY_KEY]["query"] = array ("match_all");
		$query["index"] = 'beacon_scheduled_resource_index_search';
		return $query;
	}

	/**
	 * @param kBaseSearch $coreSearchObject
	 * @param $searchParams
	 * @param $pager
	 * @return array
	 */
	private function initAndSearch($coreSearchObject, $searchParams, $pager)
	{
		try
		{
			list($coreSearchOperator, $objectStatusesArr, $objectId, $kPager, $coreOrder) =
				elasticSearchUtils::initSearchActionParams($searchParams, $pager);
			$elasticResults = $coreSearchObject->doSearch($coreSearchOperator, $objectStatusesArr, $objectId, $kPager,
				$coreOrder);
		}
		catch (kESearchException $e)
		{
			elasticSearchUtils::handleSearchException($e);
		}

		return $elasticResults;
	}

}