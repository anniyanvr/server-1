<?php
/**
 * Class kBeaconCacheLayerActions
 *
 * Package and location is not indicated
 * Should not include any kaltura dependency in this class - to enable it to run in cache only mode
 */


require_once (dirname(__FILE__) . '/kBeacon.php');
require_once (dirname(__FILE__) . '/../../../../plugins/beacon/lib/model/enums/BeaconIndexType.php');
require_once (dirname(__FILE__) . '/../../../../plugins/beacon/lib/model/kBeaconSearchQueryManger.php');

require_once (dirname(__FILE__) . '/../../../../plugins/queue/lib/QueueProvider.php');
require_once (dirname(__FILE__) . '/../../../../plugins/queue/providers/rabbit_mq/lib/RabbitMQProvider.php');
require_once (dirname(__FILE__) . '/../../../../plugins/queue/providers/rabbit_mq/lib/MultiCentersRabbitMQProvider.php');

require_once (dirname(__FILE__) . '/../../../../alpha/apps/kaltura/lib/exceptions/kCoreException.php');
require_once (dirname(__FILE__) . '/../../../../plugins/search/providers/elastic_search/client/elasticClient.php');
require_once (dirname(__FILE__) . '/../../../../plugins/search/providers/elastic_search/lib/exceptions/kESearchException.php');


class kBeaconCacheLayerActions
{
	const PARAM_EVENT_TYPE = "beacon:eventType";
	const PARAM_OBJECT_ID = "beacon:objectId";
	const PARAM_RELATED_OBJECT_TYPE = "beacon:relatedObjectType";
	const PARAM_PRIVATE_DATA = "beacon:privateData";
	const PARAM_RAW_DATA = "beacon:rawData";
	const PARAM_SHOULD_LOG = "beacon:shouldLog";
	const PARAM_PARTNER_ID = "___cache___partnerId";
	
	public static function validateInputExists($params, $paramKey)
	{
		return !array_key_exists($paramKey, $params) || $params[$paramKey] == '';
	}
	
	public static function add($params)
	{
		if (!is_null($params) &&
			self::validateInputExists($params, kBeaconCacheLayerActions::PARAM_EVENT_TYPE) ||
			self::validateInputExists($params, kBeaconCacheLayerActions::PARAM_OBJECT_ID) ||
			self::validateInputExists($params, kBeaconCacheLayerActions::PARAM_RELATED_OBJECT_TYPE) ||
			self::validateInputExists($params, kBeaconCacheLayerActions::PARAM_PARTNER_ID)
		)
			return false;
		
		$beacon = new kBeacon($params[kBeaconCacheLayerActions::PARAM_PARTNER_ID]);
		$beacon->setObjectId($params[kBeaconCacheLayerActions::PARAM_OBJECT_ID]);
		$beacon->setEventType($params[kBeaconCacheLayerActions::PARAM_EVENT_TYPE]);
		$beacon->setRelatedObjectType($params[kBeaconCacheLayerActions::PARAM_RELATED_OBJECT_TYPE]);
		
		if(isset($params[kBeaconCacheLayerActions::PARAM_PRIVATE_DATA]))
			$beacon->setPrivateData($params[kBeaconCacheLayerActions::PARAM_PRIVATE_DATA]);
		
		if(isset($params[kBeaconCacheLayerActions::PARAM_RAW_DATA]))
			$beacon->setPrivateData($params[kBeaconCacheLayerActions::PARAM_RAW_DATA]);
		
		$shouldLog = false;
		if(isset($params[kBeaconCacheLayerActions::PARAM_SHOULD_LOG]))
			$shouldLog = true;
		
		$queueProvider = self::loadQueueProvider();
		if(!$queueProvider)
			return false;
		
		return $beacon->index($shouldLog, $queueProvider);
	}
	
	public static function loadQueueProvider()
	{
		$constructorArgs = array();
		$constructorArgs['exchangeName'] = kBeacon::BEACONS_EXCHANGE_NAME;
		if(!kConf::hasMap('rabbit_mq'))
		{
			return null;
		}
		
		$rabbitConfig = kConf::getMap('rabbit_mq');
		if(isset($rabbitConfig['multiple_dcs']) && $rabbitConfig['multiple_dcs'])
		{
			return new MultiCentersRabbitMQProvider($rabbitConfig, $constructorArgs);
		}
		
		return new RabbitMQProvider($rabbitConfig, $constructorArgs);
	}
}