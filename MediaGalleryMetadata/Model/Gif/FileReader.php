<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MediaGalleryMetadata\Model\Gif;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\MediaGalleryMetadataApi\Model\FileInterface;
use Magento\MediaGalleryMetadataApi\Model\FileInterfaceFactory;
use Magento\MediaGalleryMetadataApi\Model\FileReaderInterface;
use Magento\MediaGalleryMetadataApi\Model\SegmentInterface;
use Magento\MediaGalleryMetadataApi\Model\SegmentInterfaceFactory;
use Magento\MediaGalleryMetadata\Model\SegmentNames;

/**
 * File segments reader
 */
class FileReader implements FileReaderInterface
{
    /**
     * @var DriverInterface
     */
    private $driver;

    /**
     * @var SegmentInterfaceFactory
     */
    private $segmentFactory;

    /**
     * @var FileInterfaceFactory
     */
    private $fileFactory;

    /**
     * @var SegmentNames
     */
    private $segmentNames;

    /**
     * @param DriverInterface $driver
     * @param FileInterfaceFactory $fileFactory
     * @param SegmentInterfaceFactory $segmentFactory
     * @param SegmentNames $segmentNames
     */
    public function __construct(
        DriverInterface $driver,
        FileInterfaceFactory $fileFactory,
        SegmentInterfaceFactory $segmentFactory,
        SegmentNames $segmentNames
    ) {
        $this->driver = $driver;
        $this->fileFactory = $fileFactory;
        $this->segmentFactory = $segmentFactory;
        $this->segmentNames = $segmentNames;
    }

    /**
     * @inheritdoc
     */
    public function isApplicable(string $path): bool
    {
        $resource = $this->driver->fileOpen($path, 'rb');
        $marker = $this->read($resource, 3);
        $this->driver->fileClose($resource);

        return $marker == "GIF";
    }

    /**
     * @inheritdoc
     */
    public function execute(string $path): FileInterface
    {
        $resource = $this->driver->fileOpen($path, 'rb');

        $header = $this->read($resource, 3);

        if ($header != "GIF") {
            $this->driver->fileClose($resource);
            throw new LocalizedException(__('Not a GIF image'));
        }

        $version = $this->read($resource, 3);

        if (!in_array($version, ['87a', '89a'])) {
            $this->driver->fileClose($resource);
            throw new LocalizedException(__('Unexpected GIF version'));
        }

        $headerSegment = $this->segmentFactory->create([
            'name' => 'header',
            'dataStart' => 0,
            'data' => $header . $version
        ]);

        $width = $this->read($resource, 2);
        $height = $this->read($resource, 2);
        $bitPerPixelBinary = $this->read($resource, 1);
        $bitPerPixel = $this->getBitPerPixel($bitPerPixelBinary);
        $backgroundAndAspectRatio = $this->read($resource, 2);
        $globalColorTable = $this->getGlobalColorTable($resource, $bitPerPixel);

        $generalSegment = $this->segmentFactory->create([
            'name' => 'header2',
            'dataStart' => 0,
            'data' => $width . $height . $bitPerPixelBinary . $backgroundAndAspectRatio . $globalColorTable
        ]);

        $segments = $this->getSegments($resource);

        array_unshift($segments, $headerSegment, $generalSegment);

        return $this->fileFactory->create([
            'path' => $path,
            'compressedImage' => '',
            'segments' => $segments
        ]);
    }

    /**
     * @param $resource
     * @return SegmentInterface[]
     * @throws FileSystemException
     */
    private function getSegments($resource): array
    {
        $gifFrameSeparator = pack("C", ord(","));
        $gifExtensionSeparator = pack("C", ord("!"));
        $gifTerminator = pack("C", ord(";"));

        $segments = [];
        do {
            $separator = $this->read($resource, 1);

            if ($separator == $gifTerminator) {
                return $segments;
            }

            if ($separator == $gifFrameSeparator) {
                $segments[] = $this->segmentFactory->create([
                    'name' => 'frame',
                    'dataStart' => 0,
                    'data' => $this->readFrame($resource)
                ]);
                continue;
            }

            if ($separator != $gifExtensionSeparator) {
                throw new \Exception('The image is corrupted');
            }

            $segments[] = $this->getExtensionSegment($resource);

        } while (!$this->driver->endOfFile($resource));

        return $segments;
    }

    /**
     * @param $resource
     * @return SegmentInterface
     * @throws FileSystemException
     */
    private function getExtensionSegment($resource): SegmentInterface
    {
        $extensionCodeBinary = $this->read($resource, 1);
        $extensionCode = unpack('C', $extensionCodeBinary)[1];

        if ($extensionCode == 0xF9) {
            return $this->segmentFactory->create([
                'name' => 'Graphics Control Extension',
                'dataStart' => 0,
                'data' => $extensionCodeBinary . $this->readBlock($resource)
            ]);
        }

        if ($extensionCode == 0xFE) {
            return $this->segmentFactory->create([
                'name' => 'comment',
                'dataStart' => 0,
                'data' => $extensionCodeBinary . $this->readBlock($resource)
            ]);
        }

        if ($extensionCode != 0xFF) {
            return $this->segmentFactory->create([
                'name' => 'unknown',
                'dataStart' => 0,
                'data' => $extensionCodeBinary . $this->readBlock($resource)
            ]);
        }

        $blockLengthBinary = $this->read($resource, 1);
        $blockLength = unpack('C', $blockLengthBinary)[1];
        $name = $this->read($resource, $blockLength);

        if ($blockLength != 11) {
            throw new \Exception('The file is corrupted');
        }

        if ($name == 'XMP DataXMP') {
            return $this->segmentFactory->create([
                'name' => $name,
                'dataStart' => 0,
                'data' => $extensionCodeBinary . $blockLengthBinary
                    . $name . $this->readBlockWithSubblocks($resource)
            ]);
        }

        return $this->segmentFactory->create([
            'name' => $name,
            'dataStart' => 0,
            'data' => $extensionCodeBinary . $blockLengthBinary . $name . $this->readBlock($resource)
        ]);
    }

    /**
     * @param resource $resource
     * @return string
     * @throws FileSystemException
     */
    private function readFrame($resource)
    {
        $boundingBox = $this->read($resource, 8);
        $bitPerPixelBinary = $this->read($resource, 1);
        $bitPerPixel = $this->getBitPerPixel($bitPerPixelBinary);
        $globalColorTable = $this->getGlobalColorTable($resource, $bitPerPixel);
        return $boundingBox . $bitPerPixelBinary . $globalColorTable . $this->read($resource, 1)
            . $this->readBlockWithSubblocks($resource);
    }

    /**
     * @param string $data
     * @return int
     */
    private function getBitPerPixel(string $data): int
    {
        $bitPerPixel = unpack('C', $data)[1];
        $bpp = ($bitPerPixel & 7) + 1;
        $bitPerPixel >>= 7;
        $haveMap = $bitPerPixel & 1;
        return $haveMap ? $bpp : 0;
    }

    /**
     * @param resource $resource
     * @param int $bitPerPixel
     * @return string
     * @throws FileSystemException
     */
    private function getGlobalColorTable($resource, int $bitPerPixel): string
    {
        $globalColorTable = '';
        if ($bitPerPixel > 0) {
            $max = pow(2, $bitPerPixel);
            for ($i = 1; $i <= $max; ++$i) {
                $globalColorTable .= $this->read($resource, 3);
            }
        }
        return $globalColorTable;
    }

    /**
     * @param resource $resource
     * @param int $length
     * @return string
     * @throws FileSystemException
     */
    private function read($resource, int $length): string
    {
        $data = '';

        while (!$this->driver->endOfFile($resource) && strlen($data) < $length) {
            $data .= $this->driver->fileRead($resource, $length - strlen($data));
        }

        return $data;
    }

    /**
     * @param resource $resource
     * @return string
     * @throws FileSystemException
     */
    private function readBlockWithSubblocks($resource): string
    {
        $data = '';
        $subLength = $this->read($resource, 1);
        $blocks = 0;

        while ($subLength !== "\0") {
            $blocks++;
            $data .= $subLength;

            $data .= $this->read($resource, ord($subLength));
            $subLength = $this->read($resource, 1);
        }

        return $data;
    }

    /**
     * @param resource $resource
     * @return string
     * @throws FileSystemException]
     */
    private function readBlock($resource): string
    {
        $blockLengthBinary = $this->read($resource, 1);
        $blockLength = ord($blockLengthBinary);
        if ($blockLength == 0) {
            return '';
        }
        return $blockLength . $this->read($resource, $blockLength) . $this->read($resource, 1);
    }
}
