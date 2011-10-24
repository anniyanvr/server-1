<?php
/**
 * @package plugins.ndnDistribution
 * @subpackage model.enum
 */ 
interface NdnDistributionField extends BaseEnum
{
	//channel fields
	/*
	const CHANNEL_TITLE						= 'CHANNEL_TITLE';
	const CHANNEL_LINK						= 'CHANNEL_LINK';
	const CHANNEL_DESCRIPTION				= 'CHANNEL_DESCRIPTION';
	const CHANNEL_LANGUAGE					= 'CHANNEL_LANGUAGE';
	const CHANNEL_COPYRIGHT					= 'CHANNEL_COPYRIGHT';	
	const CHANNEL_IMAGE_TITLE				= 'CHANNEL_IMAGE_TITLE';
	const CHANNEL_IMAGE_URL					= 'CHANNEL_IMAGE_URL';
	const CHANNEL_IMAGE_LINK				= 'CHANNEL_IMAGE_LINK';
	const CHANNEL_PUB_DATE					= 'CHANNEL_PUB_DATE';
	const CHANNEL_LAST_BUILD_DATE			= 'CHANNEL_LAST_BUILD_DATE';
	*/
	
	//item fields	
	const ITEM_GUID							= 'ITEM_GUID';
	const ITEM_TITLE						= 'ITEM_TITLE';
	const ITEM_LINK							= 'ITEM_LINK';
	const ITEM_DESCRIPTION					= 'ITEM_DESCRIPTION';
	const ITEM_MEDIA_RATING					= 'ITEM_MEDIA_RATING';
	const ITEM_MEDIA_CATEGORY				= 'ITEM_MEDIA_CATEGORY';
	const ITEM_PUB_DATE						= 'ITEM_PUB_DATE';
	const ITEM_EXPIRATION_DATE				= 'ITEM_EXPIRATION_DATE';
	const ITEM_MEDIA_KEYWORDS				= 'ITEM_MEDIA_KEYWORDS';
	const ITEM_LIVE_ORIGINAL_RELEASE_DATE	= 'ITEM_LIVE_ORIGINAL_RELEASE_DATE';
	const ITEM_MEDIA_TITLE					= 'ITEM_MEDIA_TITLE';
	const ITEM_MEDIA_DESCRIPTION			= 'ITEM_MEDIA_DESCRIPTION';
	const ITEM_MEDIA_COPYRIGHT				= 'ITEM_MEDIA_COPYRIGHT';
	//attributes
	const ITEM_THUMBNAIL_CREDIT				= 'ITEM_THUMBNAIL_CREDIT';
	const ITEM_CONTENT_LANG					= 'ITEM_CONTENT_LANG';

	
	
}