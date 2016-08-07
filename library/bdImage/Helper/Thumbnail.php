<?php

class bdImage_Helper_Thumbnail
{
    public static $maxAttempt = 3;
    public static $coolDownSeconds = 600;
    public static $cropTopLeft = false;

    const ERROR_DOWNLOAD_REMOTE_URI = '/download/remote/uri.error';
    const HASH_FALLBACK = 'default';

    public static function getThumbnailUri($url, $accessibleUri, $size, $mode, $hash)
    {
        $config = XenForo_Application::getConfig();
        foreach (array(
                     'maxAttempt',
                     'coolDownSeconds',
                     'cropTopLeft'
                 ) as $key) {
            // $config['bdImage_maxAttempt'] = 3;
            // $config['bdImage_coolDownSeconds'] = 600;
            // $config['bdImage_cropTopLeft'] = false;
            self::$$key = $config->get('bdImage_' . $key, self::$$key);
        }

        return self::_buildThumbnailLink($url, $accessibleUri, $size, $mode, $hash);
    }

    protected static function _buildThumbnailLink($url, $accessibleUri, $size, $mode, $hash)
    {
        $cachePath = bdImage_Integration::getCachePath($url, $size, $mode, $hash);
        $cacheFileSize = bdImage_Helper_File::getImageFileSizeIfExists($cachePath);

        $thumbnailUrl = bdImage_Integration::getCacheUrl($url, $size, $mode, $hash);
        $thumbnailUri = XenForo_Link::convertUriToAbsoluteUri($thumbnailUrl, true);
        if ($cacheFileSize > bdImage_Helper_File::THUMBNAIL_ERROR_FILE_LENGTH) {
            return sprintf('%s?%d', $thumbnailUri, $cacheFileSize);
        }

        $thumbnailError = array();
        if ($cacheFileSize > 0 && self::$maxAttempt > 1) {
            $thumbnailError = bdImage_Helper_File::getThumbnailError($cachePath);

            if (isset($thumbnailError['latestAttempt'])
                && $thumbnailError['latestAttempt'] > XenForo_Application::$time - self::$coolDownSeconds
                && $hash !== self::HASH_FALLBACK
            ) {
                $fallbackImage = self::_getFallbackImage($size, $mode);
                if ($fallbackImage !== null) {
                    self::_log('Cooling down...');
                    return self::_buildThumbnailLink($fallbackImage, $fallbackImage, $size, $mode, self::HASH_FALLBACK);
                }
            }
        }

        $imagePath = self::_downloadImageIfNeeded($accessibleUri);

        $imageType = self::_guessImageTypeByFileExtension($url, $imagePath);
        if ($imageType !== null) {
            self::_log('Guessed image type: %d', $imageType);
            $imageObj = self::_createImageObjFromFile($imagePath, $imageType);
            if (empty($imageObj)) {
                if (self::_detectImageType($imagePath, $imageType)) {
                    self::_log('Detected image type: %d', $imageType);
                    $imageObj = self::_createImageObjFromFile($imagePath, $imageType);
                }
            }
        }

        if (empty($imageObj)
            && $hash !== self::HASH_FALLBACK
        ) {
            $fallbackImage = self::_getFallbackImage($size, $mode);
            if ($fallbackImage !== null) {
                if (self::$maxAttempt > 1
                    && (!isset($thumbnailError['attemptCount'])
                        || $thumbnailError['attemptCount'] < self::$maxAttempt)
                ) {
                    self::_log('Using fallback image...');
                    bdImage_Helper_File::saveThumbnailError($cachePath, $thumbnailError);
                    return self::_buildThumbnailLink($fallbackImage, $fallbackImage, $size, $mode, self::HASH_FALLBACK);
                } else {
                    if (self::$maxAttempt > 1) {
                        self::_log('Exceeded MAX_ATTEMPT');
                    }
                    $fallbackImageType = self::_guessImageTypeByFileExtension($fallbackImage);
                    try {
                        $imageObj = self::_createImageObjFromFile($fallbackImage, $fallbackImageType);
                    } catch (Exception $e) {
                        self::_log($e->getMessage());
                    }
                }
            }
        }

        if (empty($imageObj)) {
            self::_log('Using blank image...');
            $imageObj = XenForo_Image_Abstract::createImage($size, $size);
        }

        if (is_callable(array($imageObj, 'bdImage_optimizeOutput'))) {
            call_user_func(array($imageObj, 'bdImage_optimizeOutput'), true);
        }

        switch ($mode) {
            case bdImage_Integration::MODE_STRETCH_WIDTH:
                self::_resizeStretchWidth($imageObj, $size);
                break;
            case bdImage_Integration::MODE_STRETCH_HEIGHT:
                self::_resizeStretchHeight($imageObj, $size);
                break;
            default:
                if (is_numeric($mode)) {
                    self::_cropExact($imageObj, $size, $mode);
                } else {
                    self::_cropSquare($imageObj, $size);
                }
                break;
        }

        $tempFile = tempnam(XenForo_Helper_File::getTempDir(), 'xf');

        $outputImageType = self::_guessImageTypeByFileExtension($cachePath);
        $imageObj->output($outputImageType, $tempFile);

        if (is_callable(array($imageObj, 'bdImage_cleanUp'))) {
            call_user_func(array($imageObj, 'bdImage_cleanUp'));
        }
        unset($imageObj);

        $tempFileSize = filesize($tempFile);
        XenForo_Helper_File::createDirectory(dirname($cachePath), true);
        XenForo_Helper_File::safeRename($tempFile, $cachePath);

        self::_log('Done');
        return sprintf('%s?%d', $thumbnailUri, $tempFileSize);
    }

    protected static function _resizeStretchWidth(XenForo_Image_Abstract $imageObj, $targetHeight)
    {
        $targetWidth = $targetHeight / $imageObj->getHeight() * $imageObj->getWidth();
        $imageObj->thumbnail($targetWidth, $targetHeight);
    }

    protected static function _resizeStretchHeight(XenForo_Image_Abstract $imageObj, $targetWidth)
    {
        $targetHeight = $targetWidth / $imageObj->getWidth() * $imageObj->getHeight();
        $imageObj->thumbnail($targetWidth, $targetHeight);
    }

    protected static function _cropExact(XenForo_Image_Abstract $imageObj, $targetWidth, $targetHeight)
    {
        $origRatio = $imageObj->getWidth() / $imageObj->getHeight();
        $cropRatio = $targetWidth / $targetHeight;
        if ($origRatio > $cropRatio) {
            $thumbnailHeight = $targetHeight;
            $thumbnailWidth = $thumbnailHeight * $origRatio;
        } else {
            $thumbnailWidth = $targetWidth;
            $thumbnailHeight = $thumbnailWidth / $origRatio;
        }

        if ($thumbnailWidth <= $imageObj->getWidth()
            && $thumbnailHeight <= $imageObj->getHeight()
        ) {
            $imageObj->thumbnail($thumbnailWidth, $thumbnailHeight);
            self::_cropCenter($imageObj, $targetWidth, $targetHeight);
        } else {
            if ($origRatio > $cropRatio) {
                self::_log('Requested size is larger than the image size');
                self::_cropCenter($imageObj, $imageObj->getHeight() * $cropRatio, $imageObj->getHeight());
            } else {
                self::_cropCenter($imageObj, $imageObj->getWidth(), $imageObj->getWidth() / $cropRatio);
            }
        }
    }

    protected static function _cropSquare(XenForo_Image_Abstract $imageObj, $target)
    {
        $imageObj->thumbnailFixedShorterSide($target);
        self::_cropCenter($imageObj, $target, $target);
    }

    protected static function _cropCenter(XenForo_Image_Abstract $imageObj, $cropWidth, $cropHeight)
    {
        if (self::$cropTopLeft) {
            // revert to top left cropping (old version behavior)
            $imageObj->crop(0, 0, $cropWidth, $cropHeight);
            return;
        }

        $width = $imageObj->getWidth();
        $height = $imageObj->getHeight();
        $x = floor(($width - $cropWidth) / 2);
        $y = floor(($height - $cropHeight) / 2);
        $imageObj->crop($x, $y, $cropWidth, $cropHeight);
    }

    protected static function _getFallbackImage($size, $mode)
    {
        $bestFallbackImage = null;
        $bestRatio = null;
        $fallbackImages = preg_split('#\s#', bdImage_Option::get('fallbackImages'), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($fallbackImages as $fallbackImage) {
            if (substr($fallbackImage, 0, 1) !== '/') {
                /** @var XenForo_Application $application */
                $application = XenForo_Application::getInstance();
                $fallbackImage = sprintf('%s/%s', rtrim($application->getRootDir(), '/'), $fallbackImage);
            }
            $fallbackImageSize = bdImage_ShippableHelper_ImageSize::calculate($fallbackImage);

            if (empty($fallbackImageSize['width'])
                || empty($fallbackImageSize['height'])
            ) {
                continue;
            }
            $fallbackRatio = $fallbackImageSize['width'] / $fallbackImageSize['height'];

            switch ($mode) {
                case bdImage_Integration::MODE_STRETCH_WIDTH:
                    if ($bestRatio === null
                        || $bestRatio > $fallbackRatio
                    ) {
                        $bestFallbackImage = $fallbackImage;
                        $bestRatio = $fallbackRatio;
                    }
                    break;
                case bdImage_Integration::MODE_STRETCH_HEIGHT:
                    if ($bestRatio === null
                        || $bestRatio < $fallbackRatio
                    ) {
                        $bestFallbackImage = $fallbackImage;
                        $bestRatio = $fallbackRatio;
                    }
                    break;
                default:
                    if (is_numeric($mode) && $mode > 0) {
                        $ratio = $size / $mode;
                    } else {
                        $ratio = 1;
                    }
                    if ($bestRatio === null
                        || abs(1 - $bestRatio / $ratio) > abs(1 - $fallbackRatio / $ratio)
                    ) {
                        $bestFallbackImage = $fallbackImage;
                        $bestRatio = $fallbackRatio;
                    }
                    break;
            }
        }

        return $bestFallbackImage;
    }

    /**
     * @param string $path
     * @param int $type
     * @return null|XenForo_Image_Abstract
     *
     * @see XenForo_Image_Gd::createFromFileDirect
     * @see XenForo_Image_ImageMagick_Pecl::createFromFileDirect
     */
    protected static function _createImageObjFromFile($path, $type)
    {
        try {
            return XenForo_Image_Abstract::createFromFile($path, $type);
        } catch (Exception $e) {
            return null;
        }
    }

    protected static function _downloadImageIfNeeded($uri)
    {
        if (bdImage_Helper_File::existsAndNotEmpty($uri)) {
            return $uri;
        }

        $boardUrl = XenForo_Application::getOptions()->get('boardUrl');
        if (strpos($uri, '..') === false
            && strpos($uri, $boardUrl) === 0
        ) {
            $path = self::_getLocalFilePath(substr($uri, strlen($boardUrl)));
            if (bdImage_Helper_File::existsAndNotEmpty($path)) {
                self::_log('Using local file path %s...', $path);
                return $path;
            }
        }

        if (preg_match('#attachments/(.+\.)*(?<id>\d+)/$#', $uri, $matches)) {
            $path = self::_getAttachmentDataFilePath($matches['id']);
            if (bdImage_Helper_File::existsAndNotEmpty($path)) {
                self::_log('Using attachment data file path %s...', $path);
                return $path;
            }
        }

        // this is a remote uri, try to download it and return the downloaded file's path
        $originalCachePath = bdImage_Integration::getOriginalCachePath($uri);
        if (!bdImage_Helper_File::existsAndNotEmpty($originalCachePath)) {
            XenForo_Helper_File::createDirectory(dirname($originalCachePath), true);
            $downloaded = bdImage_ShippableHelper_TempFile::download($uri, array(
                'tempFile' => $originalCachePath,
                'maxDownloadSize' => XenForo_Application::getOptions()->get('attachmentMaxFileSize') * 1024,
            ));
            if (empty($downloaded)) {
                self::_log('Cannot download %s', $uri);
                return self::ERROR_DOWNLOAD_REMOTE_URI;
            }
        }

        self::_log('Using original cache path %s...', $originalCachePath);
        return $originalCachePath;
    }

    protected static function _getLocalFilePath($path)
    {
        $path = preg_replace('#\?.*$#', '', $path);

        /** @var XenForo_Application $app */
        $app = XenForo_Application::getInstance();
        $path = sprintf('%s/%s', rtrim($app->getRootDir(), '/'), ltrim($path, '/'));

        return $path;
    }

    protected static function _getAttachmentDataFilePath($attachmentId)
    {
        /** @var XenForo_Model_Attachment $attachmentModel */
        static $attachmentModel = null;
        static $attachments = array();

        if ($attachmentModel === null) {
            $attachmentModel = XenForo_Model::create('XenForo_Model_Attachment');
        }

        if (!isset($attachments[$attachmentId])) {
            $attachments[$attachmentId] = $attachmentModel->getAttachmentById($attachmentId);
        }

        if (empty($attachments[$attachmentId])) {
            return '';
        }

        return $attachmentModel->getAttachmentDataFilePath($attachments[$attachmentId]);
    }

    protected static function _guessImageTypeByFileExtension($uri, $path = '')
    {
        switch ($path) {
            case self::ERROR_DOWNLOAD_REMOTE_URI:
                return null;
        }

        $ext = XenForo_Helper_File::getFileExtension($uri);
        switch ($ext) {
            case 'gif':
                $result = IMAGETYPE_GIF;
                break;
            case 'jpg':
            case 'jpeg':
                $result = IMAGETYPE_JPEG;
                break;
            case 'png':
                $result = IMAGETYPE_PNG;
                break;
            default:
                $result = IMAGETYPE_JPEG;
        }

        return $result;
    }

    protected static function _detectImageType($path, &$guessedImageType)
    {
        $detectedImageType = null;

        $fh = fopen($path, 'rb');
        if (!empty($fh)) {
            $data = fread($fh, 2);

            if (!empty($data)
                && strlen($data) == 2
            ) {
                switch ($data) {
                    case 'BM':
                        $detectedImageType = IMAGETYPE_BMP;
                        break;
                    case 'GI':
                        $detectedImageType = IMAGETYPE_GIF;
                        break;
                    case chr(0xFF) . chr(0xd8):
                        $detectedImageType = IMAGETYPE_JPEG;
                        break;
                    case chr(0x89) . 'P':
                        $detectedImageType = IMAGETYPE_PNG;
                        break;
                    case 'II':
                        $detectedImageType = IMAGETYPE_TIFF_II;
                        break;
                    case 'MM':
                        $detectedImageType = IMAGETYPE_TIFF_MM;
                        break;
                }
            }

            fclose($fh);
        }

        if ($detectedImageType !== null
            && $detectedImageType != $guessedImageType
        ) {
            $guessedImageType = $detectedImageType;
            return true;
        }

        return false;
    }

    protected static function _log()
    {
        static $headerCount = 0;

        if (!XenForo_Application::debugMode()) {
            return false;
        }

        $args = func_get_args();
        $message = call_user_func_array('sprintf', $args);

        $scriptFilename = (isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '');
        $scriptFilename = basename($scriptFilename);
        if ($scriptFilename === 'thumbnail.php') {
            return self::_setHeaderSafe(sprintf('X-Thumbnail-%d: %s', $headerCount++, $message));
        }

        $requestPaths = XenForo_Application::get('requestPaths');
        return XenForo_Helper_File::log(__CLASS__, sprintf('%s: %s', $requestPaths['requestUri'], $message));
    }

    protected static function _setHeaderSafe($string, $replace = true, $httpResponseCode = null)
    {
        if (headers_sent()) {
            return false;
        }

        header($string, $replace, $httpResponseCode);

        return true;
    }
}