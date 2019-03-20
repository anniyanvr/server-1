<?php
/**
 * @package plugins.elasticSearch
 */
class ElasticSearchPlugin extends KalturaPlugin implements IKalturaEventConsumers, IKalturaPending, IKalturaServices, IKalturaObjectLoader, IKalturaExceptionHandler, IKalturaEnumerator
{
    const PLUGIN_NAME = 'elasticSearch';
    const ELASTIC_SEARCH_MANAGER = 'kElasticSearchManager';
    const ELASTIC_CORE_EXCEPTION = 'kESearchException';

    public static function getPluginName()
    {
        return self::PLUGIN_NAME;
    }

    /**
     * @return array
     */
    public static function getEventConsumers()
    {
        return array(
            self::ELASTIC_SEARCH_MANAGER,
        );
    }

    /**
     * Returns a Kaltura dependency object that defines the relationship between two plugins.
     *
     * @return array<KalturaDependency> The Kaltura dependency object
     */
    public static function dependsOn()
    {
        $searchDependency = new KalturaDependency(SearchPlugin::getPluginName());
        return array($searchDependency);
    }

    public static function getServicesMap()
    {
        $map = array(
            'ESearch' => 'ESearchService',
        );
        return $map;
    }

    /* (non-PHPdoc)
	 * @see IKalturaObjectLoader::loadObject()
	 */
    public static function loadObject($baseClass, $enumValue, array $constructorArgs = null)
    {
        if ($baseClass == 'KalturaESearchItemData' && $enumValue == KalturaESearchItemDataType::CAPTION)
            return new KalturaESearchCaptionItemData();

        if ($baseClass == 'ESearchItemData' && $enumValue == ESearchItemDataType::CAPTION)
            return new ESearchCaptionItemData();

        if ($baseClass == 'KalturaESearchItemData' && $enumValue == KalturaESearchItemDataType::METADATA)
            return new KalturaESearchMetadataItemData();

        if ($baseClass == 'ESearchItemData' && $enumValue == ESearchItemDataType::METADATA)
            return new ESearchMetadataItemData();

        if ($baseClass == 'KalturaESearchItemData' && $enumValue == KalturaESearchItemDataType::CUE_POINTS)
            return new KalturaESearchCuePointItemData();

        if ($baseClass == 'ESearchItemData' && $enumValue == ESearchItemDataType::CUE_POINTS)
            return new ESearchCuePointItemData();
        
        if ($baseClass == 'KObjectExportEngine' && $enumValue == KalturaExportObjectType::ESEARCH_MEDIA)
        {
        	return new KExportMediaEsearchEngine($constructorArgs);
        }
	
	    if($baseClass == 'KalturaJobData' && $enumValue == BatchJobType::EXPORT_CSV && (isset($constructorArgs['coreJobSubType']) &&  $constructorArgs['coreJobSubType']== self::getExportTypeCoreValue(EsearchMediaEntryExportObjectType::ESEARCH_MEDIA)))
	    {
		    return new KalturaMediaEsearchExportToCsvJobData();
	    }
	
	    if ($baseClass == 'KalturaESearchOrderByItem' && $enumValue == 'ESearchMetadataOrderByItem')
	    {
		    return new KalturaESearchMetadataOrderByItem($constructorArgs);
	    }
	
        return null;
    }

    /* (non-PHPdoc)
	* @see IKalturaObjectLoader::loadObject()
	*/
    public static function getObjectClass($baseClass, $enumValue)
    {
       return null;
    }

    public static function handleESearchException($exception)
    {
        $code = $exception->getCode();
        $data = $exception->getData();
        switch ($code)
        {
            case kESearchException::SEARCH_TYPE_NOT_ALLOWED_ON_FIELD:
                $object = new KalturaAPIException(KalturaESearchErrors::SEARCH_TYPE_NOT_ALLOWED_ON_FIELD, $data['itemType'], $data['fieldName']);
                break;
            case kESearchException::EMPTY_SEARCH_TERM_NOT_ALLOWED:
                $object = new KalturaAPIException(KalturaESearchErrors::EMPTY_SEARCH_TERM_NOT_ALLOWED, $data['fieldName'], $data['itemType']);
                break;
            case kESearchException::SEARCH_TYPE_NOT_ALLOWED_ON_UNIFIED_SEARCH:
                $object = new KalturaAPIException(KalturaESearchErrors::SEARCH_TYPE_NOT_ALLOWED_ON_UNIFIED_SEARCH, $data['itemType']);
                break;
            case kESearchException::EMPTY_SEARCH_ITEMS_NOT_ALLOWED:
                $object = new KalturaAPIException(KalturaESearchErrors::EMPTY_SEARCH_ITEMS_NOT_ALLOWED);
                break;
            case kESearchException::UNMATCHING_BRACKETS:
                $object = new KalturaAPIException(KalturaESearchErrors::UNMATCHING_BRACKETS);
                break;
            case kESearchException::MISSING_QUERY_OPERAND:
                $object = new KalturaAPIException(KalturaESearchErrors::MISSING_QUERY_OPERAND);
                break;
            case kESearchException::UNMATCHING_QUERY_OPERAND:
                $object = new KalturaAPIException(KalturaESearchErrors::UNMATCHING_QUERY_OPERAND);
                break;
            case kESearchException::CONSECUTIVE_OPERANDS_MISMATCH:
                $object = new KalturaAPIException(KalturaESearchErrors::CONSECUTIVE_OPERANDS_MISMATCH);
                break;
            case kESearchException::INVALID_FIELD_NAME:
                $object = new KalturaAPIException(KalturaESearchErrors::INVALID_FIELD_NAME, $data['fieldName']);
                break;
            case kESearchException::MISSING_MANDATORY_PARAMETERS_IN_ORDER_ITEM:
                $object = new KalturaAPIException(KalturaESearchErrors::MISSING_MANDATORY_PARAMETERS_IN_ORDER_ITEM);
                break;
            case kESearchException::MIXED_SEARCH_ITEMS_IN_NESTED_OPERATOR_NOT_ALLOWED:
                $object = new KalturaAPIException(KalturaESearchErrors::MIXED_SEARCH_ITEMS_IN_NESTED_OPERATOR_NOT_ALLOWED);
                break;
            case kESearchException::MISSING_OPERATOR_TYPE:
                $object = new KalturaAPIException(KalturaESearchErrors::MISSING_OPERATOR_TYPE);
                break;

            default:
                $object = null;
        }
        return $object;
    }

    public function getExceptionMap()
    {
        return array(
            self::ELASTIC_CORE_EXCEPTION => array('ElasticSearchPlugin', 'handleESearchException'),
        );
    }
	
	/**
	 * @return int id of dynamic enum in the DB.
	 */
	public static function getExportTypeCoreValue($valueName)
	{
		$value = self::getPluginName() . IKalturaEnumerator::PLUGIN_VALUE_DELIMITER . $valueName;
		return kPluginableEnumsManager::apiToCore('ExportObjectType', $value);
	}
	
	/**
	 * @return string external API value of dynamic enum.
	 */
	public static function getApiValue($valueName)
	{
		return self::getPluginName() . IKalturaEnumerator::PLUGIN_VALUE_DELIMITER . $valueName;
	}
	
	/**
	 * @return array<string> list of enum classes names that extend the base enum name
	 */
	public static function getEnums($baseEnumName = null)
	{
		if(is_null($baseEnumName))
			return array('EsearchMediaEntryExportObjectType');
		
		if($baseEnumName == 'ExportObjectType')
			return array('EsearchMediaEntryExportObjectType');
		
		return array();
	}
}
