<?php
// api.php - Complete Backend System
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get request data
$input = file_get_contents('php://input');
$data = $_POST;

if (empty($data) && !empty($input)) {
    parse_str($input, $data);
}

$url = $data['url'] ?? '';
$platform = $data['platform'] ?? '';

// Validate input
if (empty($url) || empty($platform)) {
    echo json_encode([
        'success' => false,
        'error' => 'URL and platform are required'
    ]);
    exit;
}

// Clean URL
$url = trim($url);
$platform = strtolower(trim($platform));

// Log the request
logRequest($url, $platform);

// Fetch video based on platform
switch ($platform) {
    case 'tiktok':
        $result = fetchTikTok($url);
        break;
    case 'facebook':
        $result = fetchFacebook($url);
        break;
    case 'instagram':
        $result = fetchInstagram($url);
        break;
    case 'youtube':
        $result = fetchYouTube($url);
        break;
    default:
        $result = [
            'success' => false,
            'error' => 'Unsupported platform'
        ];
}

// Return result
echo json_encode($result);

// ========== HELPER FUNCTIONS ==========

function makeRequest($url, $method = 'GET', $data = null, $headers = []) {
    $ch = curl_init();
    
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept: application/json, text/html, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
        'Connection: keep-alive',
        'Cache-Control: no-cache',
        'Pragma: no-cache'
    ];
    
    $allHeaders = array_merge($defaultHeaders, $headers);
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_HTTPHEADER => $allHeaders
    ]);
    
    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    return [
        'status' => $httpCode,
        'data' => $response,
        'error' => $error
    ];
}

function fetchTikTok($url) {
    // Method 1: TikTok Downloader API
    $apiUrl = 'https://www.tikwm.com/api/?url=' . urlencode($url) . '&hd=1';
    $result = makeRequest($apiUrl);
    
    if ($result['status'] === 200) {
        $data = json_decode($result['data'], true);
        
        if (isset($data['code']) && $data['code'] === 0 && isset($data['data'])) {
            $videoData = $data['data'];
            $videoUrl = $videoData['hdplay'] ?? $videoData['play'] ?? '';
            
            if ($videoUrl) {
                return [
                    'success' => true,
                    'platform' => 'tiktok',
                    'title' => $videoData['title'] ?? 'TikTok Video',
                    'author' => $videoData['author']['nickname'] ?? 'TikTok User',
                    'duration' => ($videoData['duration'] ?? 0) . 's',
                    'quality' => 'HD',
                    'videoUrl' => $videoUrl,
                    'thumbnail' => $videoData['cover'] ?? '',
                    'originalUrl' => $url,
                    'noWatermark' => true
                ];
            }
        }
    }
    
    // Method 2: Tikmate API
    $apiUrl2 = 'https://api.tikmate.app/api/lookup?url=' . urlencode($url);
    $result2 = makeRequest($apiUrl2);
    
    if ($result2['status'] === 200) {
        $data2 = json_decode($result2['data'], true);
        
        if (isset($data2['video_url'])) {
            return [
                'success' => true,
                'platform' => 'tiktok',
                'title' => 'TikTok Video',
                'author' => $data2['author_name'] ?? 'TikTok User',
                'duration' => '15-60s',
                'quality' => 'HD',
                'videoUrl' => $data2['video_url'],
                'originalUrl' => $url,
                'noWatermark' => isset($data2['watermark']) ? !$data2['watermark'] : true
            ];
        }
    }
    
    return [
        'success' => false,
        'error' => 'Could not fetch TikTok video. Please try another link.'
    ];
}

function fetchFacebook($url) {
    // Method 1: y2mate API (works for Facebook too)
    $apiUrl = 'https://y2mate.com/mates/analyzeV2/ajax';
    $postData = http_build_query([
        'k_query' => $url,
        'k_page' => 'home',
        'hl' => 'en',
        'q_auto' => '1'
    ]);
    
    $result = makeRequest($apiUrl, 'POST', $postData, [
        'Content-Type: application/x-www-form-urlencoded',
        'X-Requested-With: XMLHttpRequest'
    ]);
    
    if ($result['status'] === 200) {
        $data = json_decode($result['data'], true);
        
        if (isset($data['links']) && isset($data['links']['mp4'])) {
            $qualities = $data['links']['mp4'];
            
            // Get highest quality
            $bestQualityKey = max(array_keys($qualities));
            $bestQuality = $qualities[$bestQualityKey];
            
            return [
                'success' => true,
                'platform' => 'facebook',
                'title' => $data['title'] ?? 'Facebook Video',
                'author' => $data['author'] ?? 'Facebook User',
                'duration' => $data['t'] ?? 'N/A',
                'quality' => $bestQualityKey . 'p',
                'videoUrl' => $bestQuality['url'],
                'thumbnail' => $data['thumb'] ?? '',
                'originalUrl' => $url,
                'formats' => $qualities
            ];
        }
    }
    
    // Method 2: SaveFrom API
    $apiUrl2 = 'https://api.savefrom.net/service/1/from?url=' . urlencode($url);
    $result2 = makeRequest($apiUrl2);
    
    if ($result2['status'] === 200) {
        $data2 = json_decode($result2['data'], true);
        
        if (isset($data2['url'])) {
            return [
                'success' => true,
                'platform' => 'facebook',
                'title' => $data2['meta']['title'] ?? 'Facebook Video',
                'author' => $data2['meta']['author'] ?? 'Facebook User',
                'duration' => $data2['meta']['duration'] ?? 'N/A',
                'quality' => 'HD',
                'videoUrl' => $data2['url'],
                'thumbnail' => $data2['thumb'] ?? '',
                'originalUrl' => $url
            ];
        }
    }
    
    // Method 3: FBDownloader.net
    $apiUrl3 = 'https://fbdownloader.net/api?url=' . urlencode($url);
    $result3 = makeRequest($apiUrl3);
    
    if ($result3['status'] === 200) {
        $data3 = json_decode($result3['data'], true);
        
        if (isset($data3['links']['hd'])) {
            return [
                'success' => true,
                'platform' => 'facebook',
                'title' => $data3['title'] ?? 'Facebook Video',
                'author' => $data3['author'] ?? 'Facebook User',
                'duration' => $data3['duration'] ?? 'N/A',
                'quality' => 'HD',
                'videoUrl' => $data3['links']['hd'],
                'thumbnail' => $data3['thumbnail'] ?? '',
                'originalUrl' => $url
            ];
        } elseif (isset($data3['links']['sd'])) {
            return [
                'success' => true,
                'platform' => 'facebook',
                'title' => $data3['title'] ?? 'Facebook Video',
                'author' => $data3['author'] ?? 'Facebook User',
                'duration' => $data3['duration'] ?? 'N/A',
                'quality' => 'SD',
                'videoUrl' => $data3['links']['sd'],
                'thumbnail' => $data3['thumbnail'] ?? '',
                'originalUrl' => $url
            ];
        }
    }
    
    return [
        'success' => false,
        'error' => 'Could not fetch Facebook video. Try a different link.'
    ];
}

function fetchInstagram($url) {
    // Method 1: SaveIG API
    $apiUrl = 'https://saveig.app/api/ajaxSearch';
    $postData = http_build_query([
        'q' => $url,
        't' => 'media',
        'lang' => 'en'
    ]);
    
    $result = makeRequest($apiUrl, 'POST', $postData, [
        'Content-Type: application/x-www-form-urlencoded',
        'X-Requested-With: XMLHttpRequest'
    ]);
    
    if ($result['status'] === 200) {
        $data = json_decode($result['data'], true);
        
        if (isset($data['data']) && count($data['data']) > 0) {
            $media = $data['data'][0];
            
            return [
                'success' => true,
                'platform' => 'instagram',
                'title' => $data['title'] ?? 'Instagram Video',
                'author' => $media['author'] ?? 'Instagram User',
                'duration' => 'N/A',
                'quality' => 'HD',
                'videoUrl' => $media['url'],
                'thumbnail' => $media['cover'] ?? '',
                'originalUrl' => $url
            ];
        }
    }
    
    // Method 2: Instagram Downloader API
    $apiUrl2 = 'https://instagram-downloader-download-instagram-videos-stories.p.rapidapi.com/index?url=' . urlencode($url);
    $result2 = makeRequest($apiUrl2, 'GET', null, [
        'X-RapidAPI-Key: b8f2e2a8c8msh7e45d5b8a9a3b42p1c4a3ajsn3f9b2c1d2e3f',
        'X-RapidAPI-Host: instagram-downloader-download-instagram-videos-stories.p.rapidapi.com'
    ]);
    
    if ($result2['status'] === 200) {
        $data2 = json_decode($result2['data'], true);
        
        if (isset($data2['media'])) {
            $videoUrl = is_array($data2['media']) ? $data2['media'][0] : $data2['media'];
            
            return [
                'success' => true,
                'platform' => 'instagram',
                'title' => $data2['title'] ?? 'Instagram Video',
                'author' => $data2['author'] ?? 'Instagram User',
                'duration' => $data2['duration'] ?? 'N/A',
                'quality' => 'HD',
                'videoUrl' => $videoUrl,
                'thumbnail' => $data2['thumbnail'] ?? '',
                'originalUrl' => $url
            ];
        }
    }
    
    // Method 3: InstaDownloader API
    $apiUrl3 = 'https://api.insta-downloader.org/?url=' . urlencode($url);
    $result3 = makeRequest($apiUrl3);
    
    if ($result3['status'] === 200) {
        $data3 = json_decode($result3['data'], true);
        
        if (isset($data3['video'])) {
            return [
                'success' => true,
                'platform' => 'instagram',
                'title' => $data3['title'] ?? 'Instagram Video',
                'author' => $data3['author'] ?? 'Instagram User',
                'duration' => $data3['duration'] ?? 'N/A',
                'quality' => 'HD',
                'videoUrl' => $data3['video'],
                'thumbnail' => $data3['thumbnail'] ?? '',
                'originalUrl' => $url
            ];
        }
    }
    
    return [
        'success' => false,
        'error' => 'Could not fetch Instagram video'
    ];
}

function fetchYouTube($url) {
    // Method 1: Loader.to API
    $apiUrl = 'https://loader.to/ajax/download.php';
    $postData = http_build_query([
        'url' => $url,
        'format' => 'mp4'
    ]);
    
    $result = makeRequest($apiUrl, 'POST', $postData, [
        'Content-Type: application/x-www-form-urlencoded',
        'X-Requested-With: XMLHttpRequest'
    ]);
    
    if ($result['status'] === 200) {
        $data = json_decode($result['data'], true);
        
        if (isset($data['success']) && $data['success'] && isset($data['download_url'])) {
            return [
                'success' => true,
                'platform' => 'youtube',
                'title' => $data['title'] ?? 'YouTube Video',
                'author' => $data['author'] ?? 'YouTube Channel',
                'duration' => $data['duration'] ?? 'N/A',
                'quality' => $data['quality'] ?? 'HD',
                'videoUrl' => $data['download_url'],
                'thumbnail' => $data['thumbnail'] ?? '',
                'originalUrl' => $url
            ];
        }
    }
    
    // Method 2: y2mate API
    $apiUrl2 = 'https://y2mate.com/mates/analyzeV2/ajax';
    $postData2 = http_build_query([
        'k_query' => $url,
        'k_page' => 'home',
        'hl' => 'en',
        'q_auto' => '1'
    ]);
    
    $result2 = makeRequest($apiUrl2, 'POST', $postData2, [
        'Content-Type: application/x-www-form-urlencoded',
        'X-Requested-With: XMLHttpRequest'
    ]);
    
    if ($result2['status'] === 200) {
        $data2 = json_decode($result2['data'], true);
        
        if (isset($data2['links']) && isset($data2['links']['mp4'])) {
            $qualities = $data2['links']['mp4'];
            $bestQualityKey = max(array_keys($qualities));
            $bestQuality = $qualities[$bestQualityKey];
            
            // Extract video ID for thumbnail
            preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([^&\n?#]+)/', $url, $matches);
            $videoId = $matches[1] ?? '';
            
            return [
                'success' => true,
                'platform' => 'youtube',
                'title' => $data2['title'] ?? 'YouTube Video',
                'author' => $data2['channel'] ?? 'YouTube Channel',
                'duration' => $data2['t'] ?? 'N/A',
                'quality' => $bestQualityKey . 'p',
                'videoUrl' => $bestQuality['url'],
                'thumbnail' => $videoId ? "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg" : '',
                'originalUrl' => $url,
                'formats' => $qualities
            ];
        }
    }
    
    // Method 3: YT5s API
    $apiUrl3 = 'https://yt5s.com/api/ajaxSearch';
    $postData3 = http_build_query([
        'q' => $url,
        'vt' => 'home'
    ]);
    
    $result3 = makeRequest($apiUrl3, 'POST', $postData3);
    
    if ($result3['status'] === 200) {
        $data3 = json_decode($result3['data'], true);
        
        if (isset($data3['links']['mp4'])) {
            return [
                'success' => true,
                'platform' => 'youtube',
                'title' => $data3['title'] ?? 'YouTube Video',
                'author' => $data3['channel'] ?? 'YouTube Channel',
                'duration' => $data3['t'] ?? 'N/A',
                'quality' => 'HD',
                'videoUrl' => $data3['links']['mp4'][0]['url'] ?? '',
                'originalUrl' => $url
            ];
        }
    }
    
    return [
        'success' => false,
        'error' => 'Could not fetch YouTube video'
    ];
}

function logRequest($url, $platform) {
    // Create logs directory if not exists
    if (!file_exists('logs')) {
        mkdir('logs', 0777, true);
    }
    
    $logEntry = date('Y-m-d H:i:s') . " | Platform: $platform | URL: " . substr($url, 0, 100) . PHP_EOL;
    file_put_contents('logs/requests.log', $logEntry, FILE_APPEND);
}
?>