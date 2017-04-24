<?php
/**
 * @package api
 * @subpackage objects
 */
class KalturaGenericSyndicationFeed extends KalturaBaseSyndicationFeed
{
    /**
    * feed description
    * 
    * @var string
    */
    public $feedDescription;
    
	/**
	* feed landing page (i.e publisher website)
	* 
	* @var string
	*/
	public $feedLandingPage;
	
	/**
	 * entry filter
	 *
	 * @var KalturaBaseEntryFilter
	 */
	public $entryFilter;

	/**
	 * page size
	 *
	 * @var int
	 */
	public $pageSize;
        
    function __construct()
	{
		$this->type = KalturaSyndicationFeedType::KALTURA;
	}
	
	private static $mapBetweenObjects = array
	(
		"feedDescription",
		"feedLandingPage",
		"entryFilter",
		"pageSize",
	);
	
	public function getMapBetweenObjects()
	{
		return array_merge(parent::getMapBetweenObjects(), self::$mapBetweenObjects);
	}

	public function toInsertableObject($object_to_fill = null, $props_to_skip = array())
	{
		if(is_null($object_to_fill))
			$object_to_fill = new genericSyndicationFeed();
		return parent::toInsertableObject($object_to_fill, $props_to_skip); // TODO: Change the autogenerated stub
	}

}
