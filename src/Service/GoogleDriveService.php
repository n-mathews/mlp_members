<?php

namespace Drupal\mlp_members\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Fetches file listings from Google Drive folders via the Drive API v3.
 *
 * Setup:
 *   1. Create a Google Cloud project and enable the Google Drive API.
 *   2. Create a Service Account and download the JSON key file.
 *   3. Share each Drive folder with the service account email (view only).
 *   4. Upload the key file to a private location on the server
 *      (outside the webroot, e.g. /etc/mlp/google-service-account.json).
 *   5. Configure the path and folder IDs at /admin/config/mlp-members.
 */
class GoogleDriveService {

  const CACHE_BIN  = 'default';
  const CACHE_TTL  = 900; // 15 minutes
  const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
  const DRIVE_URL  = 'https://www.googleapis.com/drive/v3/files';
  const SCOPE      = 'https://www.googleapis.com/auth/drive.readonly';

  public function __construct(
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the files in a given Google Drive folder, cached.
   *
   * @param string $folder_id  Google Drive folder ID.
   * @return array  Array of ['name', 'id', 'mimeType', 'webViewLink', 'size', 'modifiedTime'].
   */
  public function getFolderContents(string $folder_id): array {
    $cid = 'mlp_members:drive:' . $folder_id;
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    $files = $this->fetchFromApi($folder_id);
    $this->cache->set($cid, $files, time() + self::CACHE_TTL);
    return $files;
  }

  /**
   * Clears all cached Drive folder listings.
   */
  public function clearCache(): void {
    // Use the cache tag invalidator service, not the cache backend directly.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['mlp_members:drive']);
  }

  // ── Private ──────────────────────────────────────────────

  private function fetchFromApi(string $folder_id): array {
    $config = $this->configFactory->get('mlp_members.settings');
    $key_path = $config->get('service_account_key_path');

    if (!$key_path || !file_exists($key_path)) {
      $this->loggerFactory->get('mlp_members')->warning(
        'Google Drive service account key file not found at: @path',
        ['@path' => $key_path ?? '(not configured)']
      );
      return [];
    }

    try {
      $token = $this->getAccessToken($key_path);
      if (!$token) {
        return [];
      }

      $response = $this->httpClient->get(self::DRIVE_URL, [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'query'   => [
          'q'        => "'{$folder_id}' in parents and trashed = false",
          'fields'   => 'files(id,name,mimeType,webViewLink,size,modifiedTime)',
          'orderBy'  => 'name',
          'pageSize' => 100,
        ],
      ]);

      $data  = json_decode($response->getBody()->getContents(), TRUE);
      $files = $data['files'] ?? [];

      // Filter out subfolders — return files only.
      return array_values(array_filter($files, fn($f) => $f['mimeType'] !== 'application/vnd.google-apps.folder'));

    }
    catch (RequestException $e) {
      $this->loggerFactory->get('mlp_members')->error(
        'Google Drive API error: @message',
        ['@message' => $e->getMessage()]
      );
      return [];
    }
  }

  /**
   * Gets a short-lived OAuth2 access token using the service account key.
   */
  private function getAccessToken(string $key_path): ?string {
    $cid = 'mlp_members:drive:token';
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }

    try {
      $key  = json_decode(file_get_contents($key_path), TRUE);
      $now  = time();
      $exp  = $now + 3600;

      $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
      $payload = base64_encode(json_encode([
        'iss'   => $key['client_email'],
        'scope' => self::SCOPE,
        'aud'   => self::TOKEN_URL,
        'exp'   => $exp,
        'iat'   => $now,
      ]));

      $signing_input = $header . '.' . $payload;
      openssl_sign($signing_input, $signature, $key['private_key'], 'SHA256');
      $jwt = $signing_input . '.' . base64_encode($signature);

      $response = $this->httpClient->post(self::TOKEN_URL, [
        'form_params' => [
          'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
          'assertion'  => $jwt,
        ],
      ]);

      $data  = json_decode($response->getBody()->getContents(), TRUE);
      $token = $data['access_token'] ?? NULL;

      if ($token) {
        $this->cache->set($cid, $token, $now + 3500);
      }

      return $token;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('mlp_members')->error(
        'Failed to obtain Google Drive access token: @message',
        ['@message' => $e->getMessage()]
      );
      return NULL;
    }
  }

}
