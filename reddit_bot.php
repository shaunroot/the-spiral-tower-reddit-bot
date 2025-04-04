<?php

//// This scripts path
// /home/ubuntu/spiral-tower-bot/reddit_bot.php

//// Reddit settings for bot.
// https://ssl.reddit.com/prefs/apps/

//// Existing floors
// https://docs.google.com/document/d/19VIBoX6QmVRZCIQ-2liGAQFvJD9aglFoIbzhgySnqHI/edit


require 'vendor/autoload.php';
require_once('/var/www/html/wp-load.php');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class RedditBot
{
    private $client;
    private $accessToken;
    private $subreddit;
    private $redditUsername;
    private $redditPassword;
    private $redditClientId;
    private $redditClientSecret;
    private $userAgent;
    private $openAiUrl;
    private $openAiKey;
    private $additionalImagePromptText;

    private $wpUrl;
    private $wpUser;
    private $wpPassword;    
    private $wpSiteId;

    private $lastProcessedFile = 'last_processed_timestamp.txt';  // File to store last processed timestamp

    public function __construct($subreddit)
    {
        // Load settings from JSON configuration file
        $configFile = __DIR__ . '/config.json';
        if (!file_exists($configFile)) {
            throw new Exception("Config file not found at: $configFile");
        }
        
        $config = json_decode(file_get_contents($configFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error parsing config.json: " . json_last_error_msg());
        }

        $this->client = new Client();
        $this->subreddit = $subreddit;

        // Reddit API credentials
        $this->redditUsername = $config['reddit']['username'];
        $this->redditPassword = $config['reddit']['password'];
        $this->redditClientId = $config['reddit']['client_id'];
        $this->redditClientSecret = $config['reddit']['client_secret'];
        $this->redditSubreddit = $config['reddit']['subreddit'];
        
        
        // WordPress API credentials
        $this->wpUrl = $config['wordpress']['url'];
        $this->wpUser = $config['wordpress']['user'];
        $this->wpPassword = $config['wordpress']['password'];
        $this->wpSiteId = $config['wordpress']['site_id'];
        
        // OpenAI credentials
        $this->openAiUrl = $config['openai']['url'];
        $this->openAiKey = $config['openai']['key'];
        $this->userAgent = $config['openai']['user_agent'];
        $this->additionalImagePromptText = $config['openai']['additional_prompt'];

        $this->ensurePostTypeSupport();
        $this->authenticate();
    }

    private function ensurePostTypeSupport() {
        // Check if the 'floor' post type exists
        if (post_type_exists('floor')) {
            // Check if it already has thumbnail support
            if (!post_type_supports('floor', 'thumbnail')) {
                // Add thumbnail support
                add_post_type_support('floor', 'thumbnail');
                echo "✅ Added thumbnail support to 'floor' post type\n";
            } else {
                echo "✅ 'floor' post type already supports thumbnails\n";
            }
        } else {
            echo "⚠️ 'floor' post type not found in WordPress\n";
        }
    }    

    private function authenticate()
    {
        echo "Authenticating with Reddit...\n";
        try {
            $response = $this->client->post('https://www.reddit.com/api/v1/access_token', [
                'auth' => [$this->redditClientId, $this->redditClientSecret],
                'form_params' => [
                    'grant_type' => 'password',
                    'username' => $this->redditUsername,
                    'password' => $this->redditPassword,
                    'scope' => 'privatemessages read submit identity'  // Added privatemessages and identity scopes
                ],
                'headers' => ['User-Agent' => $this->userAgent]
            ]);

            $body = json_decode($response->getBody(), true);
            $this->accessToken = $body['access_token'];

            // Debug: Print out the scopes we got
            if (isset($body['scope'])) {
                echo "✅ Granted scopes: " . $body['scope'] . "\n";
            }

            echo "✅ Authentication successful! Access token obtained.\n";
        } catch (RequestException $e) {
            echo "❌ Authentication failed: " . $e->getMessage() . "\n";
            if ($e->hasResponse()) {
                echo "Response: " . $e->getResponse()->getBody() . "\n";
            }
            exit;
        }
    }

    public function monitorPosts()
    {
        echo "Checking for new posts in r/{$this->subreddit} with [New Floor] tag...\n";
    
        $lastTimestamp = $this->getLastTimestamp();
        echo "Last processed timestamp: " . $lastTimestamp . " (" . date('Y-m-d H:i:s', $lastTimestamp) . ")\n";
    
        try {
            $response = $this->client->get("https://oauth.reddit.com/r/{$this->subreddit}/new", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'User-Agent' => $this->userAgent
                ],
                'query' => [
                    'limit' => 25  // Adjust as needed
                ]
            ]);
    
            $posts = json_decode($response->getBody(), true);
    
            if (!isset($posts['data']['children']) || empty($posts['data']['children'])) {
                echo "No new posts found. Nothing to do.\n";
                return;
            }
    
            // Track the most recent post timestamp we encounter
            $newestTimestamp = $lastTimestamp;
    
            foreach ($posts['data']['children'] as $post) {
                if (!isset($post['data']['title'])) {
                    continue; // Skip if title is missing
                }
    
                $title = $post['data']['title'];
                $postId = $post['data']['id'];
                $createdTime = $post['data']['created_utc']; // Timestamp when the post was created
                $selftext = isset($post['data']['selftext']) ? $post['data']['selftext'] : '';
                $redditUsername = isset($post['data']['author']) ? $post['data']['author'] : ''; // Get Reddit username
    
                // Keep track of the newest post timestamp
                if ($createdTime > $newestTimestamp) {
                    $newestTimestamp = $createdTime;
                }
    
                if ($createdTime <= $lastTimestamp) {
                    echo "Skipping post ID: $postId (posted at " . date('Y-m-d H:i:s', $createdTime) . ", which is before or equal to last processed time: " . date('Y-m-d H:i:s', $lastTimestamp) . ")\n";
                    continue; // Skip if post was posted before last processed time
                }
    
                echo "Processing post ID: $postId (posted at " . date('Y-m-d H:i:s', $createdTime) . ")\n";
                echo "Post Title: $title\n";
                echo "Reddit Author: $redditUsername\n";
    
                if (preg_match("/^\[New Floor\]\[(\d+)\](.*)/i", $title, $matches)) {
                    $floorNumber = $matches[1];
                    $floorName = trim($matches[2]);
    
                    echo "✅ Match found! Floor Number: $floorNumber, Floor Name: '$floorName'\n";
    
                    // Check if floor number already exists
                    if ($this->floorNumberExists($floorNumber)) {
                        echo "⚠️ Floor number $floorNumber already exists. Notifying user.\n";
    
                        // Reply to the Reddit post with a comment about the duplicate floor
                        $this->replyToPost($postId, "Sorry, that floor has already been claimed. You can create a room on that floor if you like.");
                        continue;
                    }
    
                    // Get or create WordPress user for the Reddit author
                    $authorId = $this->checkUserExists($redditUsername);
                    if (!$authorId) {
                        $authorId = $this->createWordPressUser($redditUsername);
                    }
    
                    $postBody = $this->createWordPressPost($floorName, $selftext, $floorNumber, $authorId, $redditUsername);
                    $this->sendRedditPrivateMessage(
                        $redditUsername,
                        "Your Floor Has Been Created",
                        "Your floor '$floorName' (number $floorNumber) has been successfully created on The Spiral Tower.\n\n" .
                        "View it here: " . (isset($postBody['link']) ? $postBody['link'] : "https://www.thespiraltower.net/floor/")
                    );
    
                    // Reply to the Reddit post with a comment
                    $this->replyToPost($postId, "Floor '$floorName' has been created in the tower!", $postBody['link']);
                } else {
                    echo "No match found in this post title.\n";
                }
            }
    
            // Update the timestamp ONLY once at the end of processing to the newest post we've seen
            // This ensures we don't miss any posts that came in while we were processing
            if ($newestTimestamp > $lastTimestamp) {
                echo "Updating last processed timestamp from " . date('Y-m-d H:i:s', $lastTimestamp) . 
                     " to " . date('Y-m-d H:i:s', $newestTimestamp) . "\n";
                $this->updateLastTimestamp($newestTimestamp);
            } else {
                echo "No newer posts found than our last timestamp, keeping at: " . date('Y-m-d H:i:s', $lastTimestamp) . "\n";
            }
        } catch (RequestException $e) {
            echo "❌ Error fetching posts: " . $e->getMessage() . "\n";
            if ($e->hasResponse()) {
                echo "Response: " . $e->getResponse()->getBody() . "\n";
            }
        }
    }
    
    private function updateLastTimestamp($timestamp)
    {
        // Ensure we're not somehow saving an invalid timestamp
        if (!is_numeric($timestamp) || $timestamp <= 0) {
            echo "⚠️ Invalid timestamp value: $timestamp. Not updating.\n";
            return;
        }
        
        $result = file_put_contents($this->lastProcessedFile, $timestamp);
        
        if ($result === false) {
            echo "❌ Failed to write timestamp to file. Check permissions on: {$this->lastProcessedFile}\n";
        } else {
            echo "✅ Updated timestamp file with value: $timestamp (" . date('Y-m-d H:i:s', $timestamp) . ")\n";
        }
    }
    
    private function getLastTimestamp()
    {
        // Return 0 if no timestamp has been saved before
        if (!file_exists($this->lastProcessedFile)) {
            echo "⚠️ Timestamp file not found. Using 0 as the start time.\n";
            return 0;
        }
        
        $timestamp = file_get_contents($this->lastProcessedFile);
        
        // Validate the timestamp is a valid unix timestamp
        if (!is_numeric($timestamp) || (int)$timestamp <= 0) {
            echo "⚠️ Invalid timestamp in file: $timestamp. Using 0 as the start time.\n";
            return 0;
        }
        
        echo "Read timestamp: $timestamp (" . date('Y-m-d H:i:s', (int)$timestamp) . ")\n";
        return (int)$timestamp;
    }



    private function replyToPost($postId, $message, $postUrl = null)
    {
        echo "Replying to post ID: $postId...\n";
        try {
            // Include the WordPress URL if provided
            $replyText = $postUrl ? "$message View it here: $postUrl" : $message;

            $response = $this->client->post("https://oauth.reddit.com/api/comment", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'User-Agent' => $this->userAgent
                ],
                'form_params' => [
                    'thing_id' => "t3_$postId", // 't3_' is the prefix for posts
                    'text' => $replyText
                ]
            ]);

            echo "✅ Reply sent to post ID: $postId\n";
        } catch (RequestException $e) {
            echo "❌ Error replying to post: " . $e->getMessage() . "\n";
            if ($e->hasResponse()) {
                echo "Response: " . $e->getResponse()->getBody() . "\n";
            }
        }
    }

    private function createWordPressPost($title, $content, $floorNumber, $authorId = null, $redditUsername = null)
    {
        echo "Creating WordPress Floor post: '$title' with floor number $floorNumber...\n";
        try {
            // Use the Reddit post content for the WordPress post
            $postContent = !empty($content) ? $content : "A new floor has been created: $title";

            // Use the floor endpoint
            $floorEndpoint = str_replace('/posts', '/floor', $this->wpUrl);

            // Prepare post data
            $postData = [
                'title' => $title,
                'content' => $postContent,
                'status' => 'publish',
                // We'll try both approaches to set the floor number
                'floor_number' => $floorNumber,
                'meta' => [
                    '_floor_number' => $floorNumber
                ]
            ];

            // Add author if provided
            if ($authorId) {
                $postData['author'] = (int) $authorId;
                echo "Setting author ID to: $authorId\n";
            }

            echo "Post data being sent: " . json_encode($postData) . "\n";

            // Create the post
            $response = $this->client->post($floorEndpoint, [
                'auth' => [$this->wpUser, $this->wpPassword],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $postData
            ]);

            // Decode response
            $body = json_decode($response->getBody(), true);

            // If we got a post ID, check if everything worked
            if (isset($body['id'])) {
                $postId = $body['id'];
                echo "✅ Successfully created Floor post with ID: $postId\n";

                // Try updating the floor number directly using meta
                try {
                    echo "Updating floor number meta directly...\n";
                    $updateResponse = $this->client->post("https://www.thespiraltower.net/wp-json/wp/v2/floor/$postId", [
                        'auth' => [$this->wpUser, $this->wpPassword],
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ],
                        'json' => [
                            'meta' => [
                                '_floor_number' => $floorNumber
                            ]
                        ]
                    ]);
                    
                    //// Debugging
                    // $updateBody = json_decode($updateResponse->getBody(), true);
                    // echo "Meta update response: " . json_encode($updateBody) . "\n";



                    echo "Starting image generation and upload process...\n";
        
                    $imageurl = $this->generateImageFromPrompt($postContent);
                    
                    if (!empty($imageurl)) {
                        $attachment_id = $this->uploadImageToWordPress($imageurl, $postId);
                        
                        if ($attachment_id) {
                            echo "✅ Complete image workflow successful. Attachment ID: $attachment_id\n";
                        } else {
                            echo "⚠️ Image was generated but upload failed\n";
                        }
                    } else {
                        echo "⚠️ Image generation failed, skipping upload\n";
                    }
                } catch (\Exception $e) {
                    echo "⚠️ Couldn't update meta directly: " . $e->getMessage() . "\n";
                }

                return $body;
            } elseif (isset($body['code'])) {
                echo "❌ WordPress API error: {$body['message']}\n";
            } else {
                echo "⚠️ Unexpected response format from WordPress\n";
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            echo "❌ HTTP Request failed: " . $e->getMessage() . "\n";
            if ($e->hasResponse()) {
                echo "Response: " . $e->getResponse()->getBody() . "\n";
            }
        }

        return null;  // Return null if something fails
    }

    private function floorNumberExists($floorNumber)
    {
        echo "Checking if floor number $floorNumber already exists...\n";
        try {
            // Use our custom endpoint
            $response = $this->client->get("https://www.thespiraltower.net/wp-json/spiral-tower/v1/check-floor-number/{$floorNumber}", [
                'headers' => [
                    'Accept' => 'application/json'
                ]
            ]);

            $result = json_decode($response->getBody(), true);

            if (isset($result['exists']) && $result['exists']) {
                echo "❌ Floor number $floorNumber already exists (ID: {$result['matching_id']})\n";
                return true;
            }

            echo "✅ Floor number $floorNumber is available\n";
            return false;
        } catch (\Exception $e) {
            echo "❌ Error checking existing floors: " . $e->getMessage() . "\n";
            // If we can't check, assume it's new to avoid duplication errors
            return false;
        }
    }

    private function generateImageFromPrompt($prompt) {
        echo "Generating image for prompt: " . substr($prompt, 0, 50) . "...\n";
        
        // Enhance prompt for better image generation
        $prompt = $prompt . ' ' . $this->additionalImagePromptText;
        
        // Prepare request body to match the successful curl format
        $requestBody = [
            'prompt' => $prompt,
            'n' => 1,
            'size' => '1024x1024'
        ];
        
        echo "Sending request to OpenAI URL: {$this->openAiUrl}\n";
        echo "Request body: " . json_encode($requestBody) . "\n";
        
        $response = wp_remote_post($this->openAiUrl, [
            'headers' => [
                'Content-Type' => 'application/json',
                'api-key' => $this->openAiKey,
            ],
            'body' => json_encode($requestBody),
            'timeout' => 60, // Increase timeout for image generation
        ]);
        
        if (is_wp_error($response)) {
            echo "❌ Image generation failed: " . $response->get_error_message() . "\n";
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        echo "Response status code: $status_code\n";
        
        // Log detailed error information if the response code isn't successful
        if ($status_code < 200 || $status_code >= 300) {
            echo "❌ API request failed with status code: $status_code\n";
            echo "Response body: " . $body . "\n";
            return null;
        }
        
        // Print the full response body for debugging
        echo "Full response body: " . $body . "\n";
        
        $decoded_body = json_decode($body, true);
        
        if (!isset($decoded_body['data'][0]['url'])) {
            echo "❌ No image URL found in the response.\n";
            return null;
        }
        
        $url = $decoded_body['data'][0]['url'];
        echo "✅ Image generated successfully. URL: " . $url . "\n";
        
        return $url;
    }

    private function debugOpenAIAPI() {
        echo "Debugging OpenAI API connection...\n";
        
        // Create a simple test prompt
        $testPrompt = "A mystical tower spiraling into the sky";
        
        // Prepare request exactly like the working curl command
        $requestBody = [
            'prompt' => $testPrompt,
            'n' => 1,
            'size' => '1024x1024'
        ];
        
        echo "OpenAI URL: {$this->openAiUrl}\n";
        echo "API Key (first 10 chars): " . substr($this->openAiKey, 0, 10) . "...\n";
        echo "Request body: " . json_encode($requestBody) . "\n";
        
        // Use cURL directly for more detailed error info
        $ch = curl_init($this->openAiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api-key: ' . $this->openAiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        // Enable verbose output to see exactly what's being sent
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        
        // Print cURL transfer info
        echo "cURL Info: " . json_encode($info) . "\n";
        
        // Print verbose output
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        echo "Verbose cURL log:\n" . $verboseLog . "\n";
        
        if ($error) {
            echo "❌ cURL Error: " . $error . "\n";
        } else {
            echo "Response status code: " . $info['http_code'] . "\n";
            echo "Response body: " . $response . "\n";
            
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                echo "Decoded response: " . json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "Failed to decode JSON response: " . json_last_error_msg() . "\n";
            }
        }
        
        curl_close($ch);
    }
    
    // Add this to your main code or constructor to run the debug
    // $this->debugOpenAIAPI();    

    private function uploadImageToWordPress($image_url, $post_id) {
        global $wpdb;
        
        $is_multisite = is_multisite();
        $original_site_id = null;
        
        // Only switch sites if we're in a multisite environment
        if ($is_multisite) {
            $site_id = $this->wpSiteId;
            echo "Multisite detected. Switching to site ID: $site_id (from current: " . get_current_blog_id() . ")\n";
            
            // Store the current site ID to switch back later
            $original_site_id = get_current_blog_id();
            
            // Switch to the correct site
            switch_to_blog($site_id);
        } else {
            echo "Standard WordPress installation detected (non-multisite)\n";
        }
        
        // Store the current site ID to switch back later
        $original_site_id = get_current_blog_id();
        
        // Switch to the correct site
        switch_to_blog($site_id);
        
        echo "STARTING IMAGE UPLOAD for Post ID: $post_id on site ID: " . get_current_blog_id() . "\n";
        
        // Make sure post exists and get its type
        $post = get_post($post_id);
        if (!$post) {
            echo "❌ Post ID $post_id does not exist in WordPress\n";
            restore_current_blog();
            return null;
        }
        
        // If this is a revision, get the parent post
        if ($post->post_type === 'revision' && $post->post_parent) {
            $parent_id = $post->post_parent;
            echo "Post is a revision, switching to parent post ID: $parent_id\n";
            $post_id = $parent_id;
            $post = get_post($post_id);
        }
        
        echo "Working with Post ID: $post_id (type: {$post->post_type})\n";
        
        // Make sure we have all required includes
        require_once(ABSPATH . 'wp-admin/includes/admin.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Ensure we have admin privileges - this is critical
        $original_user_id = get_current_user_id();
        if (!current_user_can('upload_files') || !current_user_can('edit_post', $post_id)) {
            $admin_users = get_users(array('role' => 'administrator', 'number' => 1));
            if (!empty($admin_users)) {
                echo "Switching to admin user for permissions\n";
                wp_set_current_user($admin_users[0]->ID);
            }
        }
        
        // STEP 1: Download the image
        echo "Downloading image from URL: $image_url\n";
        $tmp_file = download_url($image_url);
        
        if (is_wp_error($tmp_file)) {
            echo "❌ Failed to download image: " . $tmp_file->get_error_message() . "\n";
            restore_current_blog();
            return null;
        }
        
        // STEP 2: Convert PNG to JPEG to reduce file size
        $timestamp = date('YmdHis');
        $jpg_temp_path = sys_get_temp_dir() . '/dalle-' . $timestamp . '.jpg';
        
        try {
            if (function_exists('imagecreatefrompng')) {
                echo "Converting PNG to JPEG...\n";
                $image = @imagecreatefrompng($tmp_file);
                
                if ($image) {
                    // Create white background (for transparency)
                    $bg = imagecreatetruecolor(imagesx($image), imagesy($image));
                    imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                    imagealphablending($bg, true);
                    imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                    
                    // Save as JPEG
                    imagejpeg($bg, $jpg_temp_path, 90);
                    imagedestroy($image);
                    imagedestroy($bg);
                    
                    // Use the JPEG instead
                    @unlink($tmp_file);
                    $tmp_file = $jpg_temp_path;
                    $file_type = 'jpg';
                    echo "Converted to JPEG: $jpg_temp_path\n";
                }
            }
        } catch (Exception $e) {
            echo "Warning: PNG to JPEG conversion failed, continuing with original file\n";
            $file_type = 'png';
        }
        
        // STEP 3: Use WordPress media_handle_sideload
        $file_array = array(
            'name' => 'dalle-image-' . $timestamp . '.' . ($file_type ?? 'jpg'),
            'tmp_name' => $tmp_file,
            'error' => 0,
            'size' => filesize($tmp_file),
        );
        
        echo "Uploading file: {$file_array['name']} to site ID " . get_current_blog_id() . "\n";
        
        // Make sure the post type supports thumbnails
        if (!post_type_supports($post->post_type, 'thumbnail')) {
            echo "Adding thumbnail support to post type: {$post->post_type}\n";
            add_post_type_support($post->post_type, 'thumbnail');
        }
        
        // Set a filter to ensure post type is 'attachment'
        add_filter('wp_insert_post_data', function($data) {
            if (isset($data['post_type']) && $data['post_type'] !== 'attachment' && isset($data['post_mime_type'])) {
                echo "Forcing post type to attachment\n";
                $data['post_type'] = 'attachment';
            }
            return $data;
        }, 99);
        
        // Upload the file and create an attachment
        $attachment_id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($attachment_id)) {
            echo "❌ Failed to upload image: " . $attachment_id->get_error_message() . "\n";
            @unlink($tmp_file);
            
            // Reset user
            if ($original_user_id != get_current_user_id()) {
                wp_set_current_user($original_user_id);
            }
            
            // Switch back to original site
            restore_current_blog();
            
            return null;
        }
        
        echo "✅ Successfully uploaded image to media library for site ID " . get_current_blog_id() . ". Attachment ID: $attachment_id\n";
        
        // STEP 4: Set as featured image
        echo "Setting as featured image for post $post_id\n";
        
        // First try the WordPress function
        $result = set_post_thumbnail($post_id, $attachment_id);
        
        // If that fails, try direct database update
        if (!$result) {
            echo "Standard method failed. Trying direct database update...\n";
            
            // Delete any existing thumbnail association
            delete_post_meta($post_id, '_thumbnail_id');
            
            // Add the new association
            $meta_result = add_post_meta($post_id, '_thumbnail_id', $attachment_id);
            
            if (!$meta_result) {
                echo "❌ Failed to set featured image with add_post_meta. Trying direct SQL...\n";
                
                // Try direct SQL as last resort
                $wpdb->query($wpdb->prepare(
                    "REPLACE INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES (%d, %s, %d)",
                    $post_id, '_thumbnail_id', $attachment_id
                ));
            }
        }
        
        // STEP 5: Verify everything worked
        echo "Verifying featured image attachment...\n";
        
        // Clear caches to ensure we get fresh data
        clean_post_cache($post_id);
        clean_attachment_cache($attachment_id);
        
        // Check the featured image association
        $thumbnail_id = get_post_thumbnail_id($post_id);
        
        if ($thumbnail_id == $attachment_id) {
            echo "✅ Featured image verified successfully\n";
        } else {
            echo "❌ Featured image verification failed. Current thumbnail ID: " . ($thumbnail_id ?: "None") . "\n";
            
            // One last attempt with wp_update_post to trigger proper hooks
            wp_update_post([
                'ID' => $post_id
            ]);
            
            // Check again
            $thumbnail_id = get_post_thumbnail_id($post_id);
            echo "After post update, thumbnail ID: " . ($thumbnail_id ?: "None") . "\n";
        }
        
        // Get the media library URL for this attachment on this site
        $admin_url = get_admin_url(get_current_blog_id(), 'upload.php?item=' . $attachment_id);
        echo "Media item can be viewed at: $admin_url\n";
        
        // Reset user if we changed it
        if ($original_user_id != get_current_user_id()) {
            wp_set_current_user($original_user_id);
        }
        
        // Switch back to original site
        echo "Switching back to original site ID: $original_site_id\n";
        restore_current_blog();
        
        // Clean up
        @unlink($tmp_file);
        
        echo "IMAGE UPLOAD COMPLETE\n";
        return $attachment_id;
    }    

    /**
     * Verification function for debugging
     */
    private function verifyWordPressImage($attachment_id, $post_id) {
        global $wpdb;
        echo "\n===== DETAILED WORDPRESS IMAGE VERIFICATION =====\n";
        
        // Get post type
        $post_type = get_post_type($post_id);
        echo "Post type: " . ($post_type ?: "Unknown") . "\n";
        echo "Post type supports thumbnails: " . (post_type_supports($post_type, 'thumbnail') ? "Yes" : "No") . "\n";
        
        // 1. Direct database check for attachment post type
        $attachment_post = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $wpdb->posts WHERE ID = %d",
            $attachment_id
        ));
        
        if (!$attachment_post) {
            echo "❌ Attachment ID $attachment_id not found in database\n";
        } else {
            echo "✅ Attachment exists in database (DB check):\n";
            echo "   - Title: " . $attachment_post->post_title . "\n";
            echo "   - Status: " . $attachment_post->post_status . "\n";
            echo "   - Type: " . $attachment_post->post_type . "\n";
            echo "   - Mime Type: " . $attachment_post->post_mime_type . "\n";
            echo "   - Parent Post ID: " . $attachment_post->post_parent . "\n";
            
            // If post type is not 'attachment', try to fix it
            if ($attachment_post->post_type !== 'attachment') {
                echo "❌ CRITICAL: Attachment has wrong post_type: {$attachment_post->post_type}\n";
                echo "   Attempting to fix post type...\n";
                
                $update_result = $wpdb->update(
                    $wpdb->posts,
                    array('post_type' => 'attachment'),
                    array('ID' => $attachment_id),
                    array('%s'),
                    array('%d')
                );
                
                echo "   Update result: " . ($update_result ? "Success" : "Failed") . "\n";
            }
        }
        
        // 2. Check with WordPress API
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            echo "❌ Attachment not found via get_post()\n";
        } else {
            echo "✅ Attachment exists via get_post():\n";
            echo "   - Title: " . $attachment->post_title . "\n";
            echo "   - Status: " . $attachment->post_status . "\n";
            echo "   - Type: " . $attachment->post_type . "\n";
            echo "   - Mime Type: " . $attachment->post_mime_type . "\n";
            echo "   - Parent Post ID: " . $attachment->post_parent . "\n";
        }
        
        // 3. Check file existence on disk
        $file_path = get_attached_file($attachment_id);
        if (!$file_path) {
            echo "❌ No file path found for attachment ID: $attachment_id\n";
        } else {
            echo "File path: $file_path\n";
            if (!file_exists($file_path)) {
                echo "❌ File doesn't exist at: $file_path\n";
            } else {
                echo "✅ File exists at: $file_path (size: " . filesize($file_path) . " bytes)\n";
            }
        }
        
        // 4. Check attachment metadata
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (empty($metadata)) {
            echo "❌ No attachment metadata found\n";
        } else {
            echo "✅ Attachment metadata found:\n";
            echo "   - Width: " . (isset($metadata['width']) ? $metadata['width'] : 'Not set') . "\n";
            echo "   - Height: " . (isset($metadata['height']) ? $metadata['height'] : 'Not set') . "\n";
            echo "   - File: " . (isset($metadata['file']) ? $metadata['file'] : 'Not set') . "\n";
            echo "   - Sizes: " . (isset($metadata['sizes']) ? count($metadata['sizes']) . " thumbnail sizes" : 'No thumbnails') . "\n";
        }
        
        // 5. Check featured image association
        $thumbnail_id = get_post_thumbnail_id($post_id);
        echo "Featured image ID: " . ($thumbnail_id ?: "None") . " (Expected: $attachment_id)\n";
        
        if ($thumbnail_id != $attachment_id) {
            echo "❌ Featured image ID ($thumbnail_id) doesn't match attachment ID ($attachment_id)\n";
            
            // Check post meta directly
            $meta_thumbnail_id = get_post_meta($post_id, '_thumbnail_id', true);
            echo "Post meta _thumbnail_id: " . ($meta_thumbnail_id ?: "None") . "\n";
            
            // Direct DB check
            $db_thumbnail = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = '_thumbnail_id'",
                $post_id
            ));
            echo "DB check _thumbnail_id: " . ($db_thumbnail ?: "None") . "\n";
        } else {
            echo "✅ Featured image ID correctly set to: $attachment_id\n";
        }
        
        // 6. Check attachment URL
        $attachment_url = wp_get_attachment_url($attachment_id);
        echo "Attachment URL: " . ($attachment_url ?: "Not set") . "\n";
        
        // 7. Media library check
        $in_library = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->posts WHERE ID = %d AND post_type = 'attachment'",
            $attachment_id
        ));
        
        echo "Attachment in media library: " . ($in_library ? "Yes" : "No") . "\n";
        
        // 8. Check media library URL
        echo "Try viewing in media library: " . admin_url('upload.php?item=' . $attachment_id) . "\n";
        
        echo "===== VERIFICATION COMPLETE =====\n\n";
    }


    


    private function updateFeaturedImage($post_id, $attachment_id) {
        global $wpdb;
        
        echo "Setting featured image (post ID: $post_id, attachment ID: $attachment_id)...\n";
        
        // Try standard method
        $result = set_post_thumbnail($post_id, $attachment_id);
        echo "Standard set_post_thumbnail result: " . ($result ? "Success" : "Failed") . "\n";
        
        // Check if it worked
        $current_thumbnail_id = get_post_thumbnail_id($post_id);
        if ($current_thumbnail_id == $attachment_id) {
            echo "✅ Featured image set successfully using standard method\n";
            return true;
        }
        
        echo "Attempting direct meta update...\n";
        
        // Try direct meta update
        $meta_result = update_post_meta($post_id, '_thumbnail_id', $attachment_id);
        echo "Direct update_post_meta result: " . ($meta_result ? "Success" : "Failed") . "\n";
        
        // Check again
        $current_thumbnail_id = get_post_thumbnail_id($post_id);
        if ($current_thumbnail_id == $attachment_id) {
            echo "✅ Featured image set successfully using direct meta update\n";
            return true;
        }
        
        // Force direct database update as last resort
        echo "Forcing direct database update as last resort...\n";
        
        // First, delete any existing thumbnail meta
        $delete_result = $wpdb->delete(
            $wpdb->postmeta,
            array(
                'post_id' => $post_id,
                'meta_key' => '_thumbnail_id'
            ),
            array(
                '%d',
                '%s'
            )
        );
        echo "Deleted existing meta: " . ($delete_result !== false ? "Yes ($delete_result rows)" : "Failed") . "\n";
        
        // Then insert the new meta
        $insert_result = $wpdb->insert(
            $wpdb->postmeta,
            array(
                'post_id' => $post_id,
                'meta_key' => '_thumbnail_id',
                'meta_value' => (string)$attachment_id
            ),
            array(
                '%d',
                '%s',
                '%s'
            )
        );
        echo "Insert direct meta result: " . ($insert_result ? "Success" : "Failed") . "\n";
        
        // One more direct query to be absolutely sure
        $query = $wpdb->prepare(
            "REPLACE INTO $wpdb->postmeta (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            $post_id,
            '_thumbnail_id',
            (string)$attachment_id
        );
        
        $replace_result = $wpdb->query($query);
        echo "REPLACE INTO query result: " . ($replace_result !== false ? "Success" : "Failed") . "\n";
        
        // Clear all caches
        clean_post_cache($post_id);
        
        // Final check
        $final_thumbnail_id = get_post_thumbnail_id($post_id);
        if ($final_thumbnail_id == $attachment_id) {
            echo "✅ Featured image set successfully using direct database operations\n";
            return true;
        } else {
            echo "❌ ALL METHODS FAILED. Current thumbnail ID: " . ($final_thumbnail_id ?: "None") . "\n";
            
            // Emergency debug - show the actual database content
            $debug_query = $wpdb->prepare(
                "SELECT * FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = '_thumbnail_id'",
                $post_id
            );
            $debug_result = $wpdb->get_results($debug_query);
            echo "Database content for _thumbnail_id:\n";
            print_r($debug_result);
            
            return false;
        }
    }    


    // Fallback method for uploading images
    private function fallbackImageUpload($file_array, $post_id) {
        $upload_dir = wp_upload_dir();
        
        // Copy file to uploads directory
        $filename = wp_unique_filename($upload_dir['path'], $file_array['name']);
        $new_file = $upload_dir['path'] . '/' . $filename;
        
        echo "Attempting to copy file to: $new_file\n";
        
        if (!copy($file_array['tmp_name'], $new_file)) {
            echo "❌ Failed to copy file in fallback method\n";
            return null;
        }
        
        // Set correct file permissions
        $stat = stat(dirname($new_file));
        $perms = $stat['mode'] & 0000666;
        chmod($new_file, $perms);
        
        // Get file type
        $type = wp_check_filetype($filename, null);
        
        // Prepare attachment data
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $type['type'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        
        // Insert attachment into database
        $attachment_id = wp_insert_attachment($attachment, $new_file, $post_id);
        
        if (is_wp_error($attachment_id)) {
            echo "❌ Fallback wp_insert_attachment failed: " . $attachment_id->get_error_message() . "\n";
            @unlink($new_file);
            return null;
        }
        
        // Include image handling functions
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Generate metadata and thumbnails
        $attach_data = wp_generate_attachment_metadata($attachment_id, $new_file);
        wp_update_attachment_metadata($attachment_id, $attach_data);
        
        echo "✅ Fallback image upload successful with attachment ID: $attachment_id\n";
        
        return $attachment_id;
    }  
  

    private function checkUserExists($username)
    {
        echo "Checking if WordPress user '$username' exists...\n";
        try {
            $response = $this->client->get("https://www.thespiraltower.net/wp-json/wp/v2/users", [
                'auth' => [$this->wpUser, $this->wpPassword],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'query' => [
                    'search' => $username,
                    'per_page' => 1
                ]
            ]);

            $users = json_decode($response->getBody(), true);

            if (is_array($users) && count($users) > 0) {
                foreach ($users as $user) {
                    if ($user['slug'] === $username || $user['username'] === $username) {
                        echo "✅ User '$username' found with ID: {$user['id']}\n";
                        return $user['id'];
                    }
                }
            }

            echo "✅ User '$username' does not exist\n";
            return false;

        } catch (\Exception $e) {
            echo "❌ Error checking if user exists: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function createWordPressUser($redditUsername) 
    {
        echo "Creating new WordPress user for Reddit user '$redditUsername'...\n";
        
        // Use the Reddit username in lowercase for WordPress
        $username = strtolower($redditUsername);
        echo "Using lowercase username: '$username'\n";
        
        // Generate a random password
        $password = $this->generateRandomPassword(12);
        
        try {
            $response = $this->client->post("https://www.thespiraltower.net/wp-json/wp/v2/users", [
                'auth' => [$this->wpUser, $this->wpPassword],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => [
                    'username' => $username,
                    'email' => $username . "@thespiraltower.net",
                    'password' => $password,
                    'roles' => ['floor_author'],
                ],
                'http_errors' => false
            ]);
            
            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody();
            
            if ($statusCode === 201 || $statusCode === 200) {
                $user = json_decode($responseBody, true);
                
                if (isset($user['id'])) {
                    echo "✅ User account created for '$redditUsername' with ID: {$user['id']}\n";
                    
                    // Send the Reddit user their credentials
                    $this->sendRedditPrivateMessage($redditUsername, 
                        "Your Spiral Tower Account", 
                        "Hello! Your account has been created on The Spiral Tower.\n\n" .
                        "Username: $username\n" .
                        "Password: $password\n\n" .
                        "You can log in at https://www.thespiraltower.net/wp-login.php\n\n" .
                        "You now have author privileges on the site and can create new content!"
                    );
                    
                    return $user['id'];
                }
            } else {
                echo "❌ Failed to create user account. Status: $statusCode, Response: $responseBody\n";
            }
            
            return false;
        } catch (\Exception $e) {
            echo "❌ Error creating WordPress user: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function generateRandomPassword($length = 12)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }

        return $password;
    }

    private function sendRedditPrivateMessage($recipient, $subject, $message)
    {
        // Don't attempt to send PM to empty recipient
        if (empty($recipient)) {
            echo "⚠️ Cannot send PM: Empty recipient\n";
            return false;
        }

        echo "Attempting to send PM to Reddit user '$recipient'...\n";

        try {
            $response = $this->client->post("https://oauth.reddit.com/api/compose", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'User-Agent' => $this->userAgent
                ],
                'form_params' => [
                    'api_type' => 'json',
                    'to' => $recipient,
                    'subject' => $subject,
                    'text' => $message
                ],
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();

            echo "Reddit PM response status: $statusCode\n";
            echo "Reddit PM response: $responseBody\n";

            $jsonResponse = json_decode($responseBody, true);

            // Check for specific errors in the response
            if (isset($jsonResponse['json']) && isset($jsonResponse['json']['errors']) && !empty($jsonResponse['json']['errors'])) {
                foreach ($jsonResponse['json']['errors'] as $error) {
                    echo "⚠️ Reddit PM error: " . json_encode($error) . "\n";
                }
                return false;
            }

            if ($statusCode === 200) {
                echo "✅ Private message sent to '$recipient'\n";
                return true;
            }

            echo "⚠️ Failed to send PM. Status code: $statusCode\n";
            return false;
        } catch (\Exception $e) {
            echo "❌ Error sending private message: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

// Create bot instance and start monitoring posts
$bot = new RedditBot($this->redditSubreddit);
$bot->monitorPosts();