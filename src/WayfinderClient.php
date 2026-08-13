<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\search_api\SearchApiException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle client for Wayfinder's Solr-compatible core endpoints.
 */
class WayfinderClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly string $baseUrl,
    private readonly ?float $timeout = NULL,
    private readonly string $username = '',
    private readonly string $password = '',
  ) {}

  /**
   * Runs a query against the select endpoint.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function select(array $params): array {
    return $this->get('select', $params);
  }

  /**
   * Sends a JSON update command.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function update(array $command, array $options = []): array {
    return $this->request('POST', 'update', [
      'query' => $this->jsonQuery($options),
      'json' => $command,
    ]);
  }

  /**
   * Checks whether the core is reachable.
   */
  public function ping(): bool {
    try {
      $response = $this->httpClient->request(
        'GET',
        $this->endpointUrl('admin/ping'),
        $this->requestOptions(['query' => $this->jsonQuery([])]),
      );
      return $response->getStatusCode() === 200;
    }
    catch (GuzzleException) {
      return FALSE;
    }
  }

  /**
   * Runs a MoreLikeThis query.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function mlt(array $params): array {
    return $this->get('mlt', $params);
  }

  /**
   * Reads terms from the indexed term dictionary.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function terms(array $params): array {
    return $this->get('terms', $params);
  }

  /**
   * Requests suggestions from a configured suggester dictionary.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function suggest(array $params): array {
    return $this->get('suggest', $params);
  }

  /**
   * Extracts text and metadata from a file.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  public function extract(string $filepath): array {
    if (!is_file($filepath) || !is_readable($filepath)) {
      throw new SearchApiException(sprintf('File "%s" is not readable.', $filepath));
    }

    $stream = @fopen($filepath, 'rb');
    if ($stream === FALSE) {
      throw new SearchApiException(sprintf('File "%s" is not readable.', $filepath));
    }

    $filename = basename($filepath);
    return $this->request('POST', 'update/extract', [
      'query' => $this->jsonQuery([
        'extractOnly' => 'true',
        'extractFormat' => 'text',
        'resource.name' => $filename,
      ]),
      'multipart' => [[
        'name' => 'file',
        'contents' => $stream,
        'filename' => $filename,
      ]],
    ]);
  }

  /**
   * Performs a GET request to a core-relative endpoint.
   */
  private function get(string $endpoint, array $params): array {
    return $this->request('GET', $endpoint, [
      'query' => $this->jsonQuery($params),
    ]);
  }

  /**
   * Performs a request and decodes its JSON response.
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  private function request(string $method, string $endpoint, array $options): array {
    try {
      $response = $this->httpClient->request(
        $method,
        $this->endpointUrl($endpoint),
        $this->requestOptions($options),
      );
    }
    catch (RequestException $e) {
      $response = $e->getResponse();
      if ($response !== NULL) {
        throw new SearchApiException($this->errorMessage($response, $e->getMessage()), 0, $e);
      }
      throw new SearchApiException($e->getMessage(), 0, $e);
    }
    catch (GuzzleException $e) {
      throw new SearchApiException($e->getMessage(), 0, $e);
    }

    if ($response->getStatusCode() !== 200) {
      throw new SearchApiException($this->errorMessage($response, 'Wayfinder request failed.'));
    }

    $decoded = json_decode((string) $response->getBody(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Adds transport settings shared by every endpoint.
   */
  private function requestOptions(array $options): array {
    if ($this->timeout !== NULL) {
      $options['timeout'] = $this->timeout;
      $options['connect_timeout'] = $this->timeout;
    }
    if ($this->username !== '' && $this->password !== '') {
      $options['headers']['Authorization'] = 'Basic ' . base64_encode($this->username . ':' . $this->password);
    }
    return $options;
  }

  /**
   * Returns a core-relative endpoint URL.
   */
  private function endpointUrl(string $endpoint): string {
    return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
  }

  /**
   * Adds the JSON response type after all caller-supplied parameters.
   */
  private function jsonQuery(array $params): string {
    unset($params['wt']);
    $params['wt'] = 'json';
    return $this->encodeQuery($params);
  }

  /**
   * Encodes array values as repeated query-string keys.
   */
  private function encodeQuery(array $params): string {
    $encoded = [];
    foreach ($params as $name => $value) {
      $values = is_array($value) ? $value : [$value];
      foreach ($values as $item) {
        if (!is_scalar($item) && $item !== NULL) {
          throw new \InvalidArgumentException('Query parameters must be scalar values.');
        }
        $encoded[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $item);
      }
    }
    return implode('&', $encoded);
  }

  /**
   * Extracts the Solr-compatible error message from a response.
   */
  private function errorMessage(ResponseInterface $response, string $fallback): string {
    $body = json_decode((string) $response->getBody(), TRUE);
    return is_array($body) && is_string($body['error']['msg'] ?? NULL)
      ? $body['error']['msg']
      : $fallback;
  }

}
