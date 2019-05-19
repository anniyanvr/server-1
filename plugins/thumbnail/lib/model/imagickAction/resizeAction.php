<?php
/**
 * @package plugins.thumbnail
 * @subpackage model.imagickAction
 */

class resizeAction extends imagickAction
{
	protected $newWidth;
	protected $newHeight;
	protected $currentWidth;
	protected $currentHeight;
	protected $bestFit;
	protected $filterType;
	protected $blur;
	protected $shouldUseResize;
	protected $compositeFit;
	protected $compositeObject;

	const MAX_IMAGE_SIZE = 10000;
	const BEST_FIT_MIN = 1;
	CONST MIN_DIMENSION = 0;

	protected $parameterAlias = array(
		"w" => kThumbnailParameterName::WIDTH,
		"h" => kThumbnailParameterName::HEIGHT,
		"ft" => kThumbnailParameterName::FILTER_TYPE,
		"filtertype" => kThumbnailParameterName::FILTER_TYPE,
		"b" => kThumbnailParameterName::BLUR,
		"bf" => kThumbnailParameterName::BEST_FIT,
		"bestfit" => kThumbnailParameterName::BEST_FIT,
		"cf" => kThumbnailParameterName::COMPOSITE_FIT,
		"compositefit" => kThumbnailParameterName::COMPOSITE_FIT,
	);

	protected function extractActionParameters()
	{
		$this->currentWidth = $this->image->getImageWidth();
		$this->currentHeight = $this->image->getImageHeight();
		$this->newWidth = $this->getIntActionParameter(kThumbnailParameterName::WIDTH);
		$this->newHeight = $this->getIntActionParameter(kThumbnailParameterName::HEIGHT);
		$this->filterType = $this->getActionParameter(kThumbnailParameterName::FILTER_TYPE, Imagick::FILTER_LANCZOS);
		$this->blur = $this->getFloatActionParameter(kThumbnailParameterName::BLUR, 1);
		$this->bestFit = $this->getBoolActionParameter(kThumbnailParameterName::BEST_FIT);
		$this->compositeFit = $this->getBoolActionParameter(kThumbnailParameterName::COMPOSITE_FIT);
		$this->compositeObject = $this->getActionParameter(kThumbnailParameterName::COMPOSITE_OBJECT);
		$this->shouldUseResize = true;
	}

	function validateInput()
	{
		if($this->compositeFit)
		{
			if(!$this->compositeObject)
			{
				throw new KalturaAPIException(KalturaThumbnailErrors::BAD_QUERY, 'Missing composite object');
			}
		}
		else
		{
			$this->validateDimensions();
		}
	}

	protected function validateDimensions()
	{
		if($this->bestFit && $this->newWidth < self::BEST_FIT_MIN)
		{
			throw new KalturaAPIException(KalturaThumbnailErrors::BAD_QUERY, 'If bestfit is supplied parameter width must be positive');
		}

		if($this->bestFit && $this->newHeight < self::BEST_FIT_MIN)
		{
			throw new KalturaAPIException(KalturaThumbnailErrors::BAD_QUERY, ' If bestfit is supplied parameter height must be positive');
		}

		if(!is_numeric($this->newWidth) || $this->newWidth < self::MIN_DIMENSION || $this->newWidth > self::MAX_IMAGE_SIZE)
		{
			throw new KalturaAPIException(KalturaThumbnailErrors::BAD_QUERY, 'width must be between 0 and 10000');
		}

		if(!is_numeric($this->newHeight) || $this->newHeight < self::MIN_DIMENSION || $this->newHeight > self::MAX_IMAGE_SIZE)
		{
			throw new KalturaAPIException(KalturaThumbnailErrors::BAD_QUERY, 'height must be between 0 and 10000');
		}
	}

	protected function doAction()
	{
		if($this->compositeFit)
		{
			$this->newHeight = $this->compositeObject->getImageHeight();
			$this->newWidth = $this->compositeObject->getImageWidth();
		}

		if($this->newHeight > $this->currentHeight && $this->newWidth > $this->currentWidth)
		{
			$this->shouldUseResize = false;
		}

		if($this->shouldUseResize)
		{
			$this->image->resizeImage($this->newWidth, $this->newHeight, $this->filterType, $this->blur, $this->bestFit);
		}
		else
		{
			$this->image->scaleImage($this->newWidth, $this->newHeight, $this->bestFit);
		}

		return $this->image;
	}
}