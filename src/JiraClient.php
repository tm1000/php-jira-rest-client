<?php

namespace JiraRestApi;

use JiraRestApi\Configuration\ConfigurationInterface;
use JiraRestApi\Configuration\DotEnvConfiguration;
use JiraRestApi\NoOperationMonologHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use JsonMapper\JsonMapper;

/**
 * Interact jira server with REST API.
 */
class JiraClient
{
	/** @var ClientInterface */
	protected $httpClient;

	/** @var RequestFactoryInterface */
	protected $requestFactory;

	/** @var StreamFactoryInterface */
	protected $streamFactory;

	/**
	 * Json Mapper.
	 */
	protected \JsonMapper $json_mapper;

	/**
	 * HTTP response code.
	 */
	protected string|int $http_response;

	/**
	 * JIRA REST API URI.
	 */
	private string $api_uri = '/rest/api/2';

	/**
	 * Monolog instance.
	 */
	protected LoggerInterface $log;

	/**
	 * Jira Rest API Configuration.
	 */
	protected ConfigurationInterface $configuration;

	/**
	 * json en/decode options.
	 */
	protected int $jsonOptions;

	/**
	 * Constructor.
	 *
	 * @param ConfigurationInterface|null $configuration
	 * @param LoggerInterface|null        $logger
	 * @param string                      $path
	 * @param ClientInterface|null        $httpClient
	 * @param RequestFactoryInterface|null $requestFactory
	 * @param StreamFactoryInterface|null $streamFactory
	 *
	 * @throws JiraException
	 */
	public function __construct(
		?ConfigurationInterface $configuration = null,
		?LoggerInterface $logger = null,
		string $path = './',
		?ClientInterface $httpClient = null,
		?RequestFactoryInterface $requestFactory = null,
		?StreamFactoryInterface $streamFactory = null,
	) {
		if ($configuration === null) {
			if (!file_exists($path . '.env')) {
				// If calling the getcwd() on laravel it will returning the 'public' directory.
				$path = '../';
			}
			$this->configuration = new DotEnvConfiguration($path);
		} else {
			$this->configuration = $configuration;
		}

		$this->json_mapper = new \JsonMapper();

		// Fix "\JiraRestApi\JsonMapperHelper::class" syntax error, unexpected 'class' (T_CLASS), expecting identifier (T_STRING) or variable (T_VARIABLE) or '{' or '$'
		$this->json_mapper->undefinedPropertyHandler = [
			new \JiraRestApi\JsonMapperHelper(),
			'setUndefinedProperty',
		];

		// Properties that are annotated with `@var \DateTimeInterface` should result in \DateTime objects being created.
		$this->json_mapper->classMap['\\' . \DateTimeInterface::class] =
			\DateTime::class;

		// Just class mapping is not enough, bStrictObjectTypeChecking must be set to false.
		$this->json_mapper->bStrictObjectTypeChecking = false;

		// Initialize logger
		if ($logger !== null) {
			// Use the provided logger
			$this->log = $logger;
		} else {
			// Create a new logger instance
			/** @var \Monolog\Logger */
			$this->log = new Logger('JiraClient');

			if ($this->configuration->getJiraLogEnabled()) {
				// Add a stream handler for logging to file
				$this->log->pushHandler(
					new StreamHandler(
						$this->configuration->getJiraLogFile(),
						$this->configuration->getJiraLogLevel(),
					),
				);
			} else {
				// Add a no-op handler when logging is disabled
				$this->log->pushHandler(new NoOperationMonologHandler());
			}
		}

		$this->http_response = 200;
		$this->jsonOptions = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

		// Initialize HTTP client and factories
		if (
			$httpClient === null ||
			$requestFactory === null ||
			$streamFactory === null
		) {
			// Only require Guzzle if not all dependencies are provided
			if (!class_exists(\GuzzleHttp\Client::class)) {
				throw new \RuntimeException(
					'GuzzleHttp is required when not providing all PSR-18 dependencies',
				);
			}

			$guzzleConfig = [
				'timeout' => $this->configuration->getTimeout() ?: 30,
				'http_errors' => false, // We'll handle HTTP errors manually
				'verify' => $this->configuration->isCurlOptSslVerifyPeer(),
			];

			if ($this->configuration->isCurlOptSslVerifyHost() === false) {
				$guzzleConfig['verify'] = false;
			}

			$this->httpClient =
				$httpClient ?? new \GuzzleHttp\Client($guzzleConfig);
			$this->requestFactory =
				$requestFactory ?? new \GuzzleHttp\Psr7\HttpFactory();
			$this->streamFactory =
				$streamFactory ?? new \GuzzleHttp\Psr7\HttpFactory();
		} else {
			$this->httpClient = $httpClient;
			$this->requestFactory = $requestFactory;
			$this->streamFactory = $streamFactory;
		}
	}

	/**
	 * Serialize only not null field.
	 */
	protected function filterNullVariable(array $haystack): array
	{
		foreach ($haystack as $key => $value) {
			if (is_array($value)) {
				$haystack[$key] = $this->filterNullVariable($haystack[$key]);
			} elseif (is_object($value)) {
				$haystack[$key] = $this->filterNullVariable(
					get_class_vars(get_class($value)),
				);
			}

			if (is_null($haystack[$key]) || empty($haystack[$key])) {
				unset($haystack[$key]);
			}
		}

		return $haystack;
	}

	/**
	 * Execute a PSR-18 HTTP request.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
	 * @param string $url Full URL to request
	 * @param array $headers Array of HTTP headers
	 * @param string|array|StreamInterface|null $body Request body
	 * @param array $options Additional request options
	 *
	 * @return ResponseInterface The PSR-7 response object
	 * @throws JiraException
	 */
	protected function sendRequest(
		string $method,
		string $url,
		array $headers = [],
		$body = null,
		array $options = [],
	): \Psr\Http\Message\ResponseInterface {
		try {
			// Create a PSR-7 request
			$request = $this->requestFactory->createRequest($method, $url);

			// Add headers
			foreach ($headers as $name => $value) {
				$request = $request->withHeader($name, $value);
			}

			// Add body if provided
			if ($body !== null) {
				if (is_string($body)) {
					$stream = $this->streamFactory->createStream($body);
				} elseif ($body instanceof StreamInterface) {
					$stream = $body;
				} else {
					$stream = $this->streamFactory->createStream(
						http_build_query($body),
					);
				}
				$request = $request->withBody($stream);
			}

			// Configure proxy if needed
			$options = $this->configureProxy($options);

			// Log the request
			$this->log->debug('Sending HTTP request:', [
				'method' => $method,
				'url' => $url,
				'headers' => $request->getHeaders(),
				'body' => (string) $request->getBody(),
			]);

			// Send the request and return the response
			$response = $this->httpClient->sendRequest($request);

			// Check for error responses
			$statusCode = $response->getStatusCode();
			if ($statusCode < 200 || $statusCode >= 300) {
				$responseBody = (string) $response->getBody();
				throw new JiraException(
					'HTTP Request Failed: Status Code: ' .
						$statusCode .
						', URL: ' .
						$url .
						'\nError Message: ' .
						$responseBody,
					$statusCode,
					null,
					$responseBody,
				);
			}

			return $response;
		} catch (\Psr\Http\Client\ClientExceptionInterface $e) {
			$this->log->error('HTTP Request Failed: ' . $e->getMessage());
			throw new JiraException(
				'HTTP Request Failed: ' . $e->getMessage(),
				0,
				$e,
			);
		}
	}

	/**
	 * Execute REST request.
	 *
	 * @param string            $context        Rest API context (ex.:issue, search, etc..)
	 * @param array|string|null $post_data
	 * @param string|null       $custom_request [PUT|DELETE]
	 * @param string|null       $cookieFile     cookie file
	 *
	 * @throws JiraException
	 *
	 * @return string|bool
	 */
	public function exec(
		string $context,
		array|string|null $post_data = null,
		?string $custom_request = null,
		?string $cookieFile = null,
	): string|bool {
		$url = $this->createUrlByContext($context);

		// Log the request
		if (is_string($post_data)) {
			$this->log->info("$custom_request $url: $post_data");
		} elseif (is_array($post_data)) {
			$this->log->info(
				"$custom_request $url: " .
					json_encode($post_data, JSON_UNESCAPED_UNICODE),
			);
		}

		// Prepare headers
		$headers = [
			'Accept' => '*/*',
			'Content-Type' => 'application/json',
			'X-Atlassian-Token' => 'no-check',
			'X-ExperimentalApi' => 'opt-in',
			'User-Agent' => $this->getConfiguration()->getCurlOptUserAgent(),
		];

		if ($this->getConfiguration()->isTokenBasedAuth()) {
			$headers['Authorization'] =
				'Bearer ' . $this->getConfiguration()->getPersonalAccessToken();
		} else {
			$headers['Authorization'] =
				'Basic ' .
				base64_encode(
					$this->getConfiguration()->getJiraUser() .
						':' .
						$this->getConfiguration()->getJiraPassword(),
				);
		}

		// Set up the request method and body
		$method = 'GET';
		$body = null;

		if ($post_data !== null) {
			if ($custom_request === 'PUT' || $custom_request === 'DELETE') {
				$method = $custom_request;
				$body = $post_data;
			} else {
				$method = 'POST';
				$body = $post_data;
			}
		} elseif ($custom_request === 'DELETE') {
			$method = 'DELETE';
		}

		try {
			//getPersonalAccessToken
			$response = $this->sendRequest($method, $url, $headers, $body);

			// Store the HTTP status code for backward compatibility
			$this->http_response = $response->getStatusCode();

			// Return the response body as a string for backward compatibility
			$responseBody = (string) $response->getBody();

			// For backward compatibility, return false for non-2xx responses
			if ($this->http_response < 200 || $this->http_response >= 300) {
				return false;
			}

			return $responseBody;
		} catch (JiraException $e) {
			// Re-throw JiraException as is
			throw $e;
		} catch (\Exception $e) {
			// Wrap other exceptions in JiraException
			throw new JiraException(
				'Request failed: ' . $e->getMessage(),
				0,
				$e,
			);
		}
	}

	/**
	 * File upload using PSR-18 HTTP client.
	 *
	 * @param string $context REST API context (e.g., 'issue/KEY/attachments')
	 * @param array $filePathArray Array of file paths to upload
	 *
	 * @return array Array of responses for each file
	 * @throws JiraException
	 */
	public function upload(string $context, array $filePathArray): array
	{
		$url = $this->createUrlByContext($context);
		$results = [];

		foreach ($filePathArray as $idx => $filePath) {
			if (!file_exists($filePath) || !is_readable($filePath)) {
				throw new JiraException(
					"File not found or not readable: $filePath",
				);
			}

			$filename = basename((string) $filePath);
			$fileContent = file_get_contents($filePath);

			try {
				// Create a multipart stream for the file upload
				$multipartStream = $this->streamFactory->createStream(
					'--' .
						uniqid() .
						"\r\n" .
						'Content-Disposition: form-data; name="file"; filename="' .
						$filename .
						'"' .
						"\r\n" .
						'Content-Type: ' .
						mime_content_type($filePath) .
						"\r\n\r\n" .
						$fileContent .
						"\r\n" .
						'--' .
						uniqid() .
						'--',
				);

				// Set up headers for multipart form data
				$boundary = uniqid();
				$headers = [
					'X-Atlassian-Token' => 'nocheck',
					'Accept' => 'application/json',
					'Content-Type' =>
						'multipart/form-data; boundary=' . $boundary,
				];

				// Make the request
				$response = $this->sendRequest(
					'POST',
					$url,
					$headers,
					$multipartStream,
				);
				$results[$idx] = $response;
			} catch (\Exception $e) {
				$this->log->error('File upload failed: ' . $e->getMessage());
				throw new JiraException(
					'File upload failed: ' . $e->getMessage(),
					0,
					$e,
				);
			}
		}

		return $results;
	}

	/**
	 * Get URL by context.
	 *
	 * @param string $context The API context path
	 * @return string Full URL for the API endpoint
	 */
	protected function createUrlByContext(string $context): string
	{
		$host = $this->getConfiguration()->getJiraHost();

		return $host .
			$this->api_uri .
			'/' .
			preg_replace('/\//', '', $context, 1);
	}

	/**
	 * Jira Rest API Configuration.
	 */
	public function getConfiguration(): ConfigurationInterface
	{
		return $this->configuration;
	}

	/**
	 * Set a custom Jira API URI for the request.
	 *
	 * @param string $api_uri
	 */
	public function setAPIUri(string $api_uri): string
	{
		$this->api_uri = $api_uri;

		return $this->api_uri;
	}

	/**
	 * convert to query array to http query parameter.
	 */
	public function toHttpQueryParameter(
		array $paramArray,
		bool $dropNullKey = true,
	): string {
		$queryParam = '?';

		foreach ($paramArray as $key => $value) {
			if ($dropNullKey === true && empty($value)) {
				continue;
			}
			$v = null;

			// some param field(Ex: expand) type is array.
			if (is_array($value)) {
				$v = implode(',', $value);
			} else {
				$v = $value;
			}

			$queryParam .=
				rawurlencode($key) . '=' . rawurlencode((string) $v) . '&';
		}

		return $queryParam;
	}

	/**
	 * Download a file from a URL and save it to the specified directory.
	 *
	 * @param string $url The URL to download the file from
	 * @param string $outDir The directory to save the downloaded file
	 * @param string $filename The name to give to the downloaded file
	 *
	 * @return string The path to the downloaded file
	 * @throws JiraException If the file cannot be downloaded or saved
	 */
	public function download(
		string $url,
		string $outDir,
		string $filename,
	): string {
		$outputPath = rtrim($outDir, '/\\') . '/' . $filename;

		// Check if directory is writable
		if (!is_writable(dirname($outputPath))) {
			throw new JiraException(
				'Directory is not writable: ' . dirname($outputPath),
			);
		}

		try {
			// Set up headers
			$headers = [
				'Accept' => '*/*',
				'Content-Type' => 'application/octet-stream',
				'X-Atlassian-Token' => 'no-check',
				'X-ExperimentalApi' => 'opt-in',
			];

			// Make the request
			$response = $this->sendRequest('GET', $url, $headers, null, [
				'sink' => $outputPath,
				'http_errors' => false,
			]);

			// Check for successful response
			$statusCode = $this->http_response;
			if ($statusCode < 200 || $statusCode >= 300) {
				throw new JiraException(
					'Download failed with status code: ' .
						$statusCode .
						', URL: ' .
						$url,
					(int) $statusCode,
				);
			}

			// Verify file was created and has content
			if (!file_exists($outputPath) || filesize($outputPath) === 0) {
				throw new JiraException(
					'Downloaded file is empty or was not saved correctly',
				);
			}

			return $outputPath;
		} catch (\Exception $e) {
			// Clean up partially downloaded file if it exists
			if (file_exists($outputPath)) {
				@unlink($outputPath);
			}
			throw new JiraException(
				'Download failed: ' . $e->getMessage(),
				0,
				$e,
			);
		}
	}

	/**
	 * Configure proxy settings for the HTTP client.
	 *
	 * @param array $options The request options array to modify
	 * @return array The modified request options with proxy settings
	 */
	private function configureProxy(array $options = []): array
	{
		if ($this->getConfiguration()->getProxyServer()) {
			$proxyUri = $this->getConfiguration()->getProxyServer();
			if ($this->getConfiguration()->getProxyPort()) {
				$proxyUri .= ':' . $this->getConfiguration()->getProxyPort();
			}

			$options['proxy'] = $proxyUri;

			// Add proxy authentication if credentials are provided
			if (
				$this->getConfiguration()->getProxyUser() &&
				$this->getConfiguration()->getProxyPassword()
			) {
				$auth = base64_encode(
					$this->getConfiguration()->getProxyUser() .
						':' .
						$this->getConfiguration()->getProxyPassword(),
				);
				$options['headers']['Proxy-Authorization'] = 'Basic ' . $auth;
			}

			// Note: PSR-18 clients handle proxy types differently
			// The client implementation will need to handle the proxy type appropriately
		}

		return $options;
	}

	/**
	 * setting REST API url to V2.
	 *
	 * @return $this
	 */
	public function setRestApiV2()
	{
		$this->api_uri = '/rest/api/2';

		return $this;
	}

	/**
	 * setting JSON en/decoding options.
	 */
	public function setJsonOptions(int $jsonOptions): static
	{
		$this->jsonOptions = $jsonOptions;

		return $this;
	}

	/**
	 * get json en/decode options.
	 */
	public function getJsonOptions(): int
	{
		return $this->jsonOptions;
	}

	public function getHttpResponse(): string|int
	{
		return $this->http_response;
	}
}
