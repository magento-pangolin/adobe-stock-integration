<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MediaGallerySynchronization\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Read;
use Magento\MediaGalleryApi\Api\IsPathBlacklistedInterface;
use Magento\MediaGallerySynchronizationApi\Api\SynchronizeInterface;
use Magento\MediaGallerySynchronizationApi\Api\SynchronizeFilesInterface;
use Magento\MediaGallerySynchronizationApi\Model\SynchronizerPool;
use Psr\Log\LoggerInterface;

/**
 * Synchronize media storage and media assets database records
 */
class Synchronize implements SynchronizeInterface
{
    private const IMAGE_FILE_NAME_PATTERN = '#\.(jpg|jpeg|gif|png)$# i';

    /**
     * @var IsPathBlacklistedInterface
     */
    private $isPathBlacklisted;

    /**
     * @var Read
     */
    private $mediaDirectory;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var LoggerInterface
     */
    private $log;

    /**
     * @var SynchronizerPool
     */
    private $synchronizerPool;

    /**
     * @var GetAssetsIterator
     */
    private $getAssetsIterator;

    /**
     * @param IsPathBlacklistedInterface $isPathBlacklisted
     * @param Filesystem $filesystem
     * @param LoggerInterface $log
     * @param SynchronizerPool $synchronizerPool
     * @param GetAssetsIterator $assetsIterator
     */
    public function __construct(
        IsPathBlacklistedInterface $isPathBlacklisted,
        Filesystem $filesystem,
        LoggerInterface $log,
        SynchronizerPool $synchronizerPool,
        GetAssetsIterator $assetsIterator
    ) {
        $this->isPathBlacklisted = $isPathBlacklisted;
        $this->filesystem = $filesystem;
        $this->log = $log;
        $this->synchronizerPool = $synchronizerPool;
        $this->getAssetsIterator = $assetsIterator;
    }

    /**
     * @inheritdoc
     */
    public function execute(): void
    {
        $failedItems = [];

        /** @var \SplFileInfo $item */
        foreach ($this->getAssetsIterator->execute($this->getMediaDirectory()->getAbsolutePath()) as $item) {
            $path = $item->getPath() . '/' . $item->getFilename();
            if (!$this->isApplicable($path)) {
                continue;
            }

            foreach ($this->synchronizerPool->get() as $synchronizer) {
                if ($synchronizer instanceof SynchronizeFilesInterface) {
                    try {
                        $synchronizer->execute([$item]);
                    } catch (\Exception $exception) {
                        $this->log->critical($exception);
                        $failedItems[] = $item->getFilename();
                    }
                }
            }
        }

        if (!empty($failedItems)) {
            throw new LocalizedException(
                __(
                    'Could not synchronize assets: %assets',
                    [
                        'assets' => implode(', ', $failedItems)
                    ]
                )
            );
        }
    }

    /**
     * Can synchronization be applied to asset with provided path
     *
     * @param string $path
     * @return bool
     */
    private function isApplicable(string $path): bool
    {
        try {
            return $this->getMediaDirectory()->getRelativePath($path)
                && !$this->isPathBlacklisted->execute($path)
                && preg_match(self::IMAGE_FILE_NAME_PATTERN, $path);
        } catch (\Exception $exception) {
            $this->log->critical($exception);
            return false;
        }
    }

    /**
     * Retrieve media directory instance with read permissions
     *
     * @return Read
     */
    private function getMediaDirectory(): Read
    {
        if (!$this->mediaDirectory) {
            $this->mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        }
        return $this->mediaDirectory;
    }
}
