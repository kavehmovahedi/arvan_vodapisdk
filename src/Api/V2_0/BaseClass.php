<?php

namespace Arvan\Vod\Api\V2_0;

use Arvan\Vod\ApiException;
use Arvan\Vod\Configuration;
use Arvan\Vod\Extensions\CommonFunctions;
use Arvan\Vod\HeaderSetup;
use Arvan\Vod\ObjectSerializer;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;

abstract class BaseClass
{
    use CommonFunctions;

    /**
     * @var ClientInterface
     */
    protected $client = null;
    /**
     * @var Configuration
     */
    protected $config = null;

    /**
     * @var HeaderSetup
     */
    protected $header = null;

    public function __construct()
    {
        $this->client = new Client();
        $this->config = $this->getConfig();
        $this->header = new HeaderSetup();
    }

    /**
     * @return Configuration
     */
    public function getConfig(): Configuration
    {
        if (isset($this->config)) {
            return $this->config;
        }
        $this->config = new Configuration();

        return $this->config;
    }

    /**
     * @return HeaderSetup
     */
    public function getHeader(): HeaderSetup
    {
        return $this->header;
    }

    /**
     * @return Client
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * Create http client request.
     *
     * @return
     */
    public function createClientHttpRequest($params)
    {
        $request = $this->requestGenerator(
            $params['filter'] ?? null,
            $params['page'] ?? null,
            $params['per_page'] ?? null,
            $params['route'] ?? null,
            $params['_tempBody'] ?? null,
            $params['method'] ?? 'GET',
            $params['multipart'] ?? false,
            $params['content_type'] ?? 'application/json',
            $params['hasFile'] ?? false,
            $params['fileName'] ?? null
        );

        try {
            $options = $this->createHttpClientOption();
            try {
                $response = $this->client->send($request, $options);
            } catch (RequestException $e) {
                throw new ApiException(
                    "[{$e->getCode()}] {$e->getMessage()}",
                    $e->getCode(),
                    $e->getResponse() ? $e->getResponse()->getHeaders() : null,
                    $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
                );
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    $response->getBody()
                );
            }

            return $this->getBodyContents($response->getBody()->getContents(), $statusCode);
        } catch (ApiException $e) {
            switch ($e->getCode()) {
            }
            throw $e;
        }
    }

    /**
     * Create request for operation 'channelsGet'.
     *
     * @param string $filter   Filter result (optional)
     * @param int    $page     Page number (optional)
     * @param int    $per_page Page limit (optional)
     *
     * @throws \InvalidArgumentException
     *
     * @return \GuzzleHttp\Psr7\Request
     */
    protected function requestGenerator(
        $filter = null,
        $page = null,
        $per_page = null,
        $route = null,
        $_tempBody = null,
        $method = 'GET',
        $multipart = false,
        $contentType = null,
        $hasFile = null,
        $fileName = null
    ) {
        $resourcePath = $route;
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';

        // query params
        if ($filter !== null) {
            $queryParams['filter'] = ObjectSerializer::toQueryValue($filter);
        }
        // query params
        if ($page !== null) {
            $queryParams['page'] = ObjectSerializer::toQueryValue($page);
        }

        if (isset($_tempBody)) {
            foreach ($_tempBody as $key => $value) {
                $formParams[$key] = $value;
            }
        }


        if ($hasFile) {
            $multipart = true;
            $formParams[$fileName] = \GuzzleHttp\Psr7\Utils::tryFopen(ObjectSerializer::toFormValue($formParams[$fileName]), 'rb');
        }

        // query params
        if ($per_page !== null) {
            $queryParams['per_page'] = ObjectSerializer::toQueryValue($per_page);
        }

        if ($multipart) {
            $headers = $this->header->selectHeadersForMultipart(
                []
            );
        } else {
            $headers = $this->header->selectHeaders(
                [],
                ['application/json', 'multipart/form-data']
            );
        }

        $_tempBody = null;

        // for model (json/xml)
        if (isset($_tempBody)) {
            // $_tempBody is the method argument, if present
            $httpBody = $_tempBody;

            if ($headers['Content-Type'] === 'application/json') {
                // \stdClass has no __toString(), so we should encode it manually
                if ($httpBody instanceof \stdClass) {
                    $httpBody = \GuzzleHttp\json_encode($httpBody);
                }
                // array has no __toString(), so we should encode it manually
                if (is_array($httpBody)) {
                    $httpBody = \GuzzleHttp\json_encode(ObjectSerializer::sanitizeForSerialization($httpBody));
                }
            }
        } elseif (count($formParams) > 0) {
            if ($multipart) {
                $multipartContents = [];
                foreach ($formParams as $formParamName => $formParamValue) {
                    $multipartContents[] = [
                        'name' => $formParamName,
                        'contents' => $formParamValue
                    ];
                }
                // for HTTP post (form)
                $httpBody = new MultipartStream($multipartContents);
            } elseif ($headers['Content-Type'] === 'application/json') {
                $httpBody = \GuzzleHttp\json_encode($formParams);
            } else {
                // for HTTP post (form)
                $httpBody = Query::build($formParams);
            }
        }

        // this endpoint requires API key authentication
        $apiKey = $this->config->getApiKey();

        if ($apiKey !== null) {
            $headers['Authorization'] = $apiKey;
        }

        $headers = array_merge(
            $headerParams,
            $headers
        );

        $query = Query::build($queryParams);

        return new Request(
            $method,
            $this->config->getHost() . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }

    protected function createHttpClientOption()
    {
        $options = [];
        if ($this->config->getDebug()) {
            $options[RequestOptions::DEBUG] = fopen($this->config->getDebugFile(), 'a');
            if (!$options[RequestOptions::DEBUG]) {
                throw new \RuntimeException('Failed to open the debug file: ' . $this->config->getDebugFile());
            }
        }

        return $options;
    }

    // abstract protected function dataBuilder();

    protected function queryStringBuilder($params)
    {
        $query = Query::build($params);

        return $query;
    }

    protected function createPostRequest(
        string $endPoint,
        array $body,
        string $endpointKey = null,
        string $endpointValue = null,
        bool $hasFile = false,
        string $fileName = null,
        string $defaultContentTye = 'application/json'
    ) {
        $result = null;

        try {
            $result = $this->createClientHttpRequest([
                'method' => 'POST',
                'route' => $this->urlBuilder($endPoint, $endpointKey, $endpointValue),
                '_tempBody' => $body,
                'content_type' => $defaultContentTye,
                'hasFile' => $hasFile,
                'fileName' => $fileName
            ]);
        } catch (\Throwable $e) {
            $result = $e->getMessage();
        }

        return $result;
    }

    protected function createPatchOrDeleteRequest(string $endPoint, string $key, string $value, array $body = null, $method = 'PATCH')
    {
        $result = null;

        try {
            $result = $this->createClientHttpRequest([
                'method' => $method,
                'route' => $this->urlBuilder($endPoint, $key, $value),
                '_tempBody' => $body,
            ]);
        } catch (\Throwable $e) {
            $result = $e->getMessage();
        }

        return $result;
    }

    protected function createGETRequest(string $endPoint, array $options = null, string $keyId = null, string $id = null)
    {
        $result = null;

        $queryParams['filter'] = isset($options['filter']) ? $options['filter'] : null;
        $queryParams['page'] = isset($options['page']) ? $options['page'] : null;
        $queryParams['per_page'] = isset($options['per_page']) ? $options['per_page'] : null;
        $queryParams['secure_ip'] = isset($options['secure_ip']) ? $options['secure_ip'] : null;
        $queryParams['secure_expire_time'] = isset($options['secure_expire_time']) ? $options['secure_expire_time'] : null;

        try {
            $response = $this->createClientHttpRequest([
                'method' => 'GET',
                'route' => $this->urlBuilder($endPoint, $keyId, $id) . '?' . $this->queryStringBuilder($queryParams),
            ]);
        } catch (\Throwable $e) {
            $response = $e->getMessage();
        }

        return $response;
    }
}
