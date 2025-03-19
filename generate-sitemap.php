<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Configuration type definition
 * @var array{
 *     store: array{
 *         shopify_domain: string,
 *         product_url_template: string,
 *         password?: string
 *     },
 *     cache: array{
 *         dir: string,
 *         ttl: int
 *     },
 *     api: array{
 *         limit: int,
 *         start_page: int,
 *         rate_limit: int,
 *         max_pages?: int
 *     },
 *     xml: array{
 *         namespaces: array{
 *             sitemap: string,
 *             image: string
 *         }
 *     }
 * } $config
 */
$config = require __DIR__ . '/config.php';

/**
 * Simple console logger implementation
 */
final class ConsoleLogger implements LoggerInterface
{
    public function emergency($message, array $context = []): void { echo "[EMERGENCY] $message\n"; }
    public function alert($message, array $context = []): void { echo "[ALERT] $message\n"; }
    public function critical($message, array $context = []): void { echo "[CRITICAL] $message\n"; }
    public function error($message, array $context = []): void { echo "[ERROR] $message\n"; }
    public function warning($message, array $context = []): void { echo "[WARNING] $message\n"; }
    public function notice($message, array $context = []): void { echo "[NOTICE] $message\n"; }
    public function info($message, array $context = []): void { echo "[INFO] $message\n"; }
    public function debug($message, array $context = []): void { echo "[DEBUG] $message\n"; }
    public function log($level, $message, array $context = []): void { echo "[$level] $message\n"; }
}

// Initialize logger
$logger = new ConsoleLogger();

// Validate store URL
if (!filter_var($config['store']['shopify_domain'], FILTER_VALIDATE_URL)) {
    die("Invalid store URL: {$config['store']['shopify_domain']}\n");
}

// Create cache directory if it doesn't exist
if (!is_dir($config['cache']['dir'])) {
    if (!mkdir($config['cache']['dir'], 0755, true)) {
        die("Failed to create cache directory: {$config['cache']['dir']}\n");
    }
    $logger->info("Created cache directory");
}

// Clean old cache files
$cache_files = glob($config['cache']['dir'] . '/*.json');
foreach ($cache_files as $file) {
    if (time() - filemtime($file) > $config['cache']['ttl']) {
        unlink($file);
        $logger->debug("Cleaned old cache file: " . basename($file));
    }
}

// Create a cookie jar to store session
$cookieJar = new CookieJar();

// Initialize Guzzle client with headers
$client = new Client([
    'timeout' => 30,
    'http_errors' => false,
    'headers' => [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.5',
        'Connection' => 'keep-alive',
        'Upgrade-Insecure-Requests' => '1'
    ],
    'allow_redirects' => [
        'max' => 10,
        'strict' => false,
        'track_redirects' => true
    ],
    'cookies' => $cookieJar
]);

// First, get the password page to capture any tokens
try {
    // Only attempt authentication if password is defined
    if (!empty($config['store']['password'])) {
        $logger->info("Store is password protected, attempting authentication...");
        $response = $client->get($config['store']['shopify_domain']);
        $body = $response->getBody()->getContents();
        
        // Extract authenticity token if present
        if (preg_match('/<input[^>]*name="authenticity_token"[^>]*value="([^"]*)"/', $body, $matches)) {
            $authenticity_token = $matches[1];
            $logger->info("Found authenticity token");
        }
        
        // Now submit the password
        $logger->info("Submitting password...");
        $response = $client->post($config['store']['shopify_domain'] . 'password', [
            'form_params' => [
                'form_type' => 'storefront_password',
                'utf8' => '✓',
                'password' => $config['store']['password'],
                'authenticity_token' => $authenticity_token ?? ''
            ],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Origin' => $config['store']['shopify_domain'],
                'Referer' => $config['store']['shopify_domain']
            ]
        ]);
        
        $logger->info("Password submitted, status: " . $response->getStatusCode());
    } else {
        $logger->info("Store is not password protected, proceeding with direct access");
    }
    
    // Test if we can access the products endpoint
    $logger->info("Testing products endpoint...");
    $test_response = $client->get($config['store']['shopify_domain'] . 'products.json?limit=1');
    
    // Check content type
    $content_type = $test_response->getHeaderLine('Content-Type');
    if (!str_contains($content_type, 'application/json')) {
        $logger->error("Invalid content type: $content_type. Expected application/json");
        die("Store endpoint returned invalid content type. Store might be password protected.\n");
    }
    
    // Try to decode JSON
    $body = $test_response->getBody()->getContents();
    $data = json_decode($body, true);
    if ($data === null) {
        $logger->error("Invalid JSON response from store");
        die("Store endpoint returned invalid JSON response\n");
    }
    
    // Validate product data structure
    if (!isset($data['products']) || !is_array($data['products'])) {
        $logger->error("Invalid product data structure received");
        die("Store endpoint returned invalid product data structure\n");
    }
    
    $logger->info("Products endpoint test successful");
    
} catch (GuzzleException $e) {
    $logger->error("Error during store access: " . $e->getMessage());
    die("Failed to access store\n");
}

// Create XML document with proper namespaces
$namespaces = $config['xml']['namespaces'];
$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="' . $namespaces['sitemap'] . '" xmlns:image="' . $namespaces['image'] . '"/>');

/**
 * Get cached data or fetch from API
 *
 * @param Client $client Guzzle HTTP client
 * @param string $url API endpoint URL
 * @param string $cache_dir Directory to store cache files
 * @param int $cache_ttl Cache time to live in seconds
 * @param LoggerInterface $logger Logger instance
 * @return array{products: array<array{handle: string, title: string, updated_at: string, images?: array<array{src: string}>}>}
 */
function getProducts(Client $client, string $url, string $cache_dir, int $cache_ttl, LoggerInterface $logger): array
{
    $cache_file = $cache_dir . '/' . md5($url) . '.json';
    
    // Check cache
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_ttl)) {
        $logger->debug("Using cached data for: $url");
        $data = json_decode(file_get_contents($cache_file), true);
        if ($data !== null) {
            return $data;
        }
    }
    
    $logger->info("Fetching: $url");
    try {
        $response = $client->get($url);
        $logger->debug("Response status: " . $response->getStatusCode());
        
        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody()->getContents(), true);
            if ($data !== null) {
                // Validate product data structure
                if (!isset($data['products']) || !is_array($data['products'])) {
                    $logger->warning("Invalid product data structure received");
                    return ['products' => []];
                }
                
                // Cache the response
                if (file_put_contents($cache_file, json_encode($data)) === false) {
                    $logger->error("Failed to write cache file: " . basename($cache_file));
                }
                
                $logger->info("Found " . count($data['products']) . " products");
                return $data;
            }
        }
    } catch (GuzzleException $e) {
        $logger->error("Error fetching products: " . $e->getMessage());
    }
    
    return ['products' => []];
}

/**
 * Format date to ISO 8601
 *
 * @param string $date Date string to format
 * @return string Formatted date in ISO 8601 format
 */
function formatDate(string $date): string
{
    return date('c', strtotime($date));
}

// Start from configured page
$page = $config['api']['start_page'];
$total_products = 0;
$max_pages = $config['api']['max_pages'] ?? null;

while (true) {
    $url = $config['store']['shopify_domain'] . 'products.json?limit=' . $config['api']['limit'] . '&page=' . $page;
    $logger->info("Fetching: $url");
    
    try {
        $products = getProducts($client, $url, $config['cache']['dir'], $config['cache']['ttl'], $logger);
        
        if (!isset($products['products']) || empty($products['products'])) {
            $logger->info("No more products found on page $page");
            break;
        }
        
        $product_list = $products['products'];
        $logger->info("Found " . count($product_list) . " products");
        $total_products += count($product_list);
        
        // Add products to sitemap
        foreach ($product_list as $product) {
            $product_url = str_replace('<id>', $product['handle'], $config['store']['product_url_template']);
            $url_element = $xml->addChild('url');
            $url_element->addChild('loc', $product_url);
            $url_element->addChild('lastmod', date('c', strtotime($product['updated_at'])));
            
            // Add image if available
            if (!empty($product['images'])) {
                $image = $product['images'][0];
                $image_element = $url_element->addChild('image:image', null, $config['xml']['namespaces']['image']);
                $image_element->addChild('image:loc', $image['src'], $config['xml']['namespaces']['image']);
                // Escape special characters in title
                $escaped_title = htmlspecialchars($product['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $image_element->addChild('image:title', $escaped_title, $config['xml']['namespaces']['image']);
            }
        }
        
        // Check if we've hit the max pages limit
        if ($max_pages !== null && $page >= ($config['api']['start_page'] + $max_pages - 1)) {
            $logger->info("Reached max pages limit of $max_pages");
            break;
        }
        
        $page++;
        sleep($config['api']['rate_limit']);
        
    } catch (GuzzleException $e) {
        $logger->error("Error fetching products: " . $e->getMessage());
        break;
    }
}

// Save sitemap
try {
    $xml->asXML('sitemap.xml');
    $logger->info("Sitemap generated successfully! Total products: $total_products");
} catch (\Exception $e) {
    $logger->error("Failed to save sitemap: " . $e->getMessage());
    die("Failed to save sitemap\n");
} 