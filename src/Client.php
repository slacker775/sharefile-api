<?php
declare(strict_types = 1);

namespace ShareFile;

use ShareFile\Api\Client as BaseClient;
use Psr\Http\Message\StreamInterface;

class Client extends BaseClient
{
    public const UPLOAD_METHOD_STANDARD = 'standard';
    public const UPLOAD_METHOD_STREAMED = 'streamed';
    public const UPLOAD_METHOD_THREADED = 'threaded';

    /**
     * Download a file from ShareFile
     *
     * Example Usage:
     *
     * $download = $apiClient->downloadFile($fileId);
     * $f = fopen('download.bin','wb');
     * $download->rewind();
     * fwrite($f, $download->getContents());
     * fclose($f);
     */
    public function downloadFile(string $id, array $queryParameters = []): StreamInterface
    {
        $response = $this->getItemContents($id, $queryParameters, self::FETCH_RESPONSE);
        return $response->getBody();
    }

    public function uploadFile(string $filename, string $parentId, string $method = self::UPLOAD_METHOD_STANDARD)
    {}

    private function uploadStandard(string $chunkUri)
    {}
}