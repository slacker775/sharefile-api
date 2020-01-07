<?php
declare(strict_types = 1);

namespace ShareFile;

use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\HistoryPlugin;
use Http\Client\Common\Plugin\Journal;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Http\Message\Authentication\Bearer;
use Http\Message\MultipartStream\MultipartStreamBuilder;
use Psr\Http\Message\StreamInterface;
use ShareFile\Api\Client as BaseClient;
use Symfony\Component\OptionsResolver\OptionsResolver;

class Client extends BaseClient
{
    public const UPLOAD_METHOD_STANDARD = 'standard';
    public const UPLOAD_METHOD_STREAMED = 'streamed';
    public const UPLOAD_METHOD_THREADED = 'threaded';
    public const UPLOAD_CHUNK_SIZE = 8 * 1024 * 1024;

    /**
     * 
     * @param string $subDomain custom subdomain like https://acmecorp.sf-api.com/sf/v3
     * @param string $token oAuth Token
     * @param Journal $journal optional Journal implementation for logging requests/Responses
     * @return \Http\Client\Common\PluginClient
     */
    public static function createHttpClient(string $subDomain, string $token, Journal $journal = null)
    {
        $httpClient = Psr18ClientDiscovery::find();
        $uri = Psr17FactoryDiscovery::findUrlFactory()->createUri('https://focalpoint.sf-api.com/sf/v3');
        
        $plugins = [];
        $plugins[] = new \Http\Client\Common\Plugin\AddHostPlugin($uri);
        $plugins[] = new \Http\Client\Common\Plugin\AddPathPlugin($uri);
        $plugins[] = new AuthenticationPlugin(new Bearer($token));
        if ($journal !== null) {
            $plugins[] = new HistoryPlugin($journal);
        }
        
        return new \Http\Client\Common\PluginClient($httpClient, $plugins);
    }

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

    public function uploadFile($stream, string $parentId, array $options = [])
    {
        $options['fileSize'] = fstat($stream)['size'];
        $options['clientCreatedDateUTC'] = fstat($stream)['ctime'];
        $options['clientModifiedDateUTC'] = fstat($stream)['mtime'];

        $resolver = new OptionsResolver();
        $this->configureUploadOptions($resolver);
        $resolvedOptions = $resolver->resolve($options);

        foreach($resolvedOptions as $key => $value) {
            if($value === null) {
                unset($resolvedOptions[$key]);
            }
        }

        dump($resolvedOptions);
        $uploadInfo = $this->getChunkUri($parentId, $resolvedOptions);
        if ($resolvedOptions['method'] == self::UPLOAD_METHOD_STANDARD) {
            return $this->uploadStandard($stream, $uploadInfo->getChunkUri());
        } else if ($resolvedOptions['method'] == self::UPLOAD_METHOD_STREAMED) {
            return $this->uploadStreamed($stream, $uploadInfo->getChunkUri());
        }
    }

    private function configureUploadOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'fileName' => null,
            'fileSize' => 0,
            'title' => null,
            'batchId' => null,
            'raw' => false,
/*            'batchLast' => false,
            'canResume' => false,
            'startOver' => false,
            'overwrite' => false,
            'isSend' => false,
            'notify' => false,
            'unzip' => false, */
            'tool' => 'apiv3',
            'details' => null,
            'sendGuid' => null,
            'opid' => null,
            'threadCount' => null,
            'responseFormat' => 'json',
            'clientCreatedDateUTC' => null,
            'clientModifiedDateUTC' => null,
            'expirationDays' => null,
            'baseFileId' => null,
            'method' => self::UPLOAD_METHOD_STANDARD
        ]);
        $resolver->setRequired([
            'fileName',
            'method'
        ]);
    }

    private function uploadStandard($stream, string $chunkUri)
    {
        $builder = new MultipartStreamBuilder($this->streamFactory);
        $builder->addResource('File1', $stream);
        $multipartStream = $builder->build();
        $boundary = $builder->getBoundary();

        $request = $this->requestFactory->createRequest('POST', $chunkUri)
            ->withAddedHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary)
            ->withBody($multipartStream);

        $response = $this->httpClient->sendRequest($request);
        if ($response->getStatusCode() == 404) {
            throw new ServerErrorException($response->getReasonPhrase(), $request, $response);
        }
        dump($response);
    }

    private function uploadStreamed($resource, string $chunkUri)
    {
        $chunkSize = SELF::UPLOAD_CHUNK_SIZE;
        $index = 0;

        /* First chunk */
        $stream = $this->streamFactory->createStreamFromResource($resource);
        $data = $this->readChunk($stream, $chunkSize);
        while (! ((strlen($data) < $chunkSize) || $stream->eof())) {
            $parameters = $this->buildHttpQuery([
                'index' => $index,
                'byteOffset' => $index * $chunkSize,
                'hash' => md5($data)
            ]);

            $response = $this->uploadChunk("{$chunkUri}&{$parameters}", $data);

            dump($response);
            if ($response != 'true') {
                return $response;
            }

            /* Following chunks */
            $index ++;
            $data = $this->readChunk($stream, $chunkSize);
        }

        /* Final chunk */
        $parameters = $this->buildHttpQuery([
            'index' => $index,
            'byteOffset' => $index * $chunkSize,
            'hash' => md5($data),
            'filehash' => \GuzzleHttp\Psr7\hash(\GuzzleHttp\Psr7\stream_for($stream), 'md5'),
            'finish' => true
        ]);

        return $this->uploadChunk("{$chunkUri}&{$parameters}", $data);
    }

    private function uploadChunk(string $uri, string $data): string
    {
        $stream = $this->streamFactory->createStream($data);
        $request = $this->requestFactory->createRequest('POST', $uri)
            ->withAddedHeader('Content-Length', strlen($data))
            ->withAddedHeader('Content-Type', 'application/octet-stream')
            ->withBody($stream);

        $response = $this->httpClient->sendRequest($request);

        return (string) $response->getBody();
    }

    private function readChunk(StreamInterface $stream, int $chunkSize)
    {
        $chunk = '';
        while ($stream->eof() === false && $chunkSize > 0) {
            $part = $stream->read($chunkSize);
            if ($part === '') {
                throw new \Exception('Error reading from $stream.');
            }
            $chunk .= $part;
            $chunkSize -= strlen($part);
        }

        return $chunk;
    }

    private function buildHttpQuery(array $parameters): string
    {
        return http_build_query(array_map(function ($parameter) {
            if (! is_bool($parameter)) {
                return $parameter;
            }

            return $parameter ? 'true' : 'false';
        }, $parameters));
    }
}