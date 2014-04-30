<?php

class DeliveryProfileSilverLight extends DeliveryProfileVod {
	
	protected $DEFAULT_RENDERER_CLASS = 'kSilverLightManifestRenderer';
	
	public function serve()
	{
		$manifestInfo = $this->getSmoothStreamUrl();
		return $this->getRenderer(array($manifestInfo));
	}
	
	/**
	 * @return array
	 */
	protected function getSmoothStreamUrl()
	{
		if($this->params->getManifestFileSync()->getFileType() == FileSync::FILE_SYNC_FILE_TYPE_FILE)
		{
			$entry = entryPeer::retrieveByPK($this->params->getEntryId());
			$urlPrefix = myPartnerUtils::getIisHost($entry->getPartnerId(), $this->params->getMediaProtocol());
		}
		else if($this->params->getStorageId())
		{
			$storageProfile = StorageProfilePeer::retrieveByPK($this->params->getStorageId());
			$urlPrefix = $storageProfile->getDeliveryIisBaseUrl();
		}
	
		$matches = null;
		if(preg_match('/(https?:\/\/[^\/]+)(.*)/', $urlPrefix, $matches))
		{
			$urlPrefix = $matches[1];
		}
		$urlPrefix .= '/';
	
		$this->initDeliveryDynamicAttribtues($this->params->getManifestFileSync());
		$url = $this->getFileSyncUrl($this->params->getManifestFileSync(), false);
		return $this->getFlavorAssetInfo($url, $urlPrefix);
		
	}
}

