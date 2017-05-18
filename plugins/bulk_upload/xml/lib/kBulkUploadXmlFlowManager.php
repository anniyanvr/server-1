<?php

class kBulkUploadXmlFlowManager implements kBatchJobStatusEventConsumer
{
	/**
	 * @param BatchJob $dbBatchJob
	 * @return bool true if should continue to the next consumer
	 */
	public function updatedJob(BatchJob $dbBatchJob)
	{
		KalturaLog::debug("Handling finished ExtractMedia job!");
		
		$profile = myPartnerUtils::getConversionProfile2ForEntry($dbBatchJob->getEntryId());
		$mediaInfoXslt = $profile->getMediaInfoXslTransformation();
		if (!$mediaInfoXslt)
		{
			return true;
		}
		
		$mediaInfo = mediaInfoPeer::retrieveByPk($dbBatchJob->getData()->getMediaInfoId());
		$mediaInfoRawData = $mediaInfo->getRawDataXml();
		
		$transformedXml = kXml::transformXmlUsingXslt($mediaInfoRawData, $mediaInfoXslt, array("entryId" => $dbBatchJob->getEntryId()));
		$xml = new KDOMDocument();
		if(!$xml->loadXML($transformedXml))
		{
			KalturaLog::err("Could not load xml string");
			return true;
		}
		
		if(!$xml->getElementsByTagName("entryId")->item(0))
		{
			KalturaLog::err("XML structure is incorrect - must contain tag entry ID");
			return true;
		}
		
		$xml->getElementsByTagName("entryId")->item(0)->nodeValue = $dbBatchJob->getEntryId();
		$transformedXml = $xml->saveXML();
		
		//Save the file to a temporary location
		$tmpFolder = kConf::get("temp_folder");
		$fileName = $dbBatchJob->getEntryId() . '_update_' . time() . ".xml";
		$filePath = $tmpFolder . DIRECTORY_SEPARATOR. $fileName;
		$res = file_put_contents($filePath, $transformedXml);
		chmod($filePath, 0640);
		
		$jobData = new kBulkUploadXmlJobData();
		$jobData->setFileName($fileName);
		$jobData->setFilePath($filePath);
		$jobData->setBulkUploadObjectType(BulkUploadObjectType::ENTRY);
		$jobData->setObjectData(new kBulkUploadEntryData());
		$bulkUploadCoreType = BulkUploadXmlPlugin::getBulkUploadTypeCoreValue(BulkUploadXmlType::XML);
		
		kJobsManager::addBulkUploadJob($dbBatchJob->getPartner(), $jobData, $bulkUploadCoreType);
		
		return true;
	}
	
	/**
	 * @param BatchJob $dbBatchJob
	 * @return bool true if the consumer should handle the event
	 */
	public function shouldConsumeJobStatusEvent(BatchJob $dbBatchJob)
	{
		if ($dbBatchJob->getStatus() == BatchJob::BATCHJOB_STATUS_FINISHED && $dbBatchJob->getJobType() == BatchJobType::EXTRACT_MEDIA)
		{
			return true;
		}
		
		return false;
	}
	
}
