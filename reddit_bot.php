<?php

//// This scripts path
// /home/ubuntu/spiral-tower-bot/reddit_bot.php

//// Reddit settings for bot.
// https://ssl.reddit.com/prefs/apps/

//// Existing floors
// https://docs.google.com/document/d/19VIBoX6QmVRZCIQ-2liGAQFvJD9aglFoIbzhgySnqHI/edit


set_time_limit(0);
require 'vendor/autoload.php';
require_once('/var/www/html/wp-load.php');
require_once('/var/www/html/wp-content/plugins/the-spiral-tower/includes/class-spiral-tower-image-generator.php');
require_once(__DIR__ . '/plugin-loader.php');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class RedditBot
{
    // Appended to floor/room creation confirmation messages
    const MORE_INFO_LINK = "[more info](https://www.reddit.com/r/TheSpiralTower/comments/1l5wqx7/the_tower_is_ready_to_be_seen/)";

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

    private $pluginsEnabled = false;  // load private feature plugins (The Claw, etc.)

    private $lastProcessedFile = 'last_processed_timestamp.txt';  // File to store last processed timestamp
    private $lastProcessedPMFile = 'last_processed_pm_timestamp.txt';  // File to store last processed PM timestamp
    private $lastProcessedCommentFile = 'last_processed_comment_timestamp.txt';  // File to store last processed comment timestamp

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

        // Switch to the correct blog in WordPress multisite
        if (is_multisite() && $this->wpSiteId) {
            switch_to_blog($this->wpSiteId);
        }

        // OpenAI credentials
        $this->openAiUrl = $config['openai']['url'];
        $this->openAiKey = $config['openai']['key'];
        $this->userAgent = $config['openai']['user_agent'];
        $this->additionalImagePromptText = $config['openai']['additional_prompt'];

        $this->pluginsEnabled = $config['plugins_enabled'] ?? false;

        $this->ensurePostTypeSupport();
        $this->authenticate();
    }

    private function ensurePostTypeSupport()
    {
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
                    'scope' => 'privatemessages read submit identity modcontributors modflair'  // mod* needed for invites/flush (contributor + flair)
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

        // Track the most recent post timestamp we encounter
        $newestTimestamp = $lastTimestamp;
        $processedPostIds = [];

        $limit    = 100;
        $after    = null;
        $page     = 0;
        $burst    = false;
        $maxPages = 10; // burst / gap catch-up safety cap: up to 1000 posts

        try {
            // One page (100) normally covers it, but after an outage or a burst we
            // page back until we reach posts we've already processed, so missed
            // [New Floor] requests still get created and no post-activity is lost.
            do {
                $page++;
                $query = array('limit' => $limit);
                if ($after) {
                    $query['after'] = $after;
                }

                $response = $this->client->get("https://oauth.reddit.com/r/{$this->subreddit}/new", [
                    'headers' => [
                        'Authorization' => "Bearer {$this->accessToken}",
                        'User-Agent' => $this->userAgent
                    ],
                    'query' => $query
                ]);

                $posts    = json_decode($response->getBody(), true);
                $children = isset($posts['data']['children']) ? $posts['data']['children'] : array();

                if (empty($children)) {
                    if ($page === 1) {
                        echo "No new posts found. Nothing to do.\n";
                    }
                    break;
                }
                if ($page > 1) {
                    echo "Post burst/gap — paging back (page $page) to catch posts beyond the first 100...\n";
                }

                $oldestInPage = null;

                foreach ($children as $post) {
                    if (!isset($post['data'])) {
                        continue;
                    }
                    $createdTime = isset($post['data']['created_utc']) ? $post['data']['created_utc'] : 0;

                    // Track oldest on this page (for burst paging) and newest overall.
                    if ($createdTime && ($oldestInPage === null || $createdTime < $oldestInPage)) {
                        $oldestInPage = $createdTime;
                    }
                    if ($createdTime > $newestTimestamp) {
                        $newestTimestamp = $createdTime;
                    }

                    if (!isset($post['data']['title'])) {
                        continue; // Skip if title is missing
                    }

                    $title = $post['data']['title'];
                    $postId = $post['data']['id'];
                    $selftext = isset($post['data']['selftext']) ? $post['data']['selftext'] : '';
                    $redditUsername = isset($post['data']['author']) ? $post['data']['author'] : ''; // Get Reddit username
                    $redditPostUrl = isset($post['data']['permalink']) ? 'https://www.reddit.com' . $post['data']['permalink'] : '';

                    // Skip duplicate posts within the same run (Reddit API can return duplicates)
                if (in_array($postId, $processedPostIds)) {
                    continue;
                }
                $processedPostIds[] = $postId;

                if ($createdTime <= $lastTimestamp) {
                    continue; // Skip if post was posted before last processed time
                }

                // Game feature: track this author's activity / auto-enroll new members.
                $this->recordSubredditActivity($redditUsername);

                echo "Processing post ID: $postId (posted at " . date('Y-m-d H:i:s', $createdTime) . ")\n";
                echo "Post Title: $title\n";
                echo "Reddit Author: $redditUsername\n";

                $this->processFloorPost($title, $postId, $selftext, $redditUsername, $redditPostUrl);
                }

                // Cursor for the next page (Reddit gives data.after; fall back to
                // the last child's fullname).
                $after = (isset($posts['data']['after']) && $posts['data']['after'])
                    ? $posts['data']['after']
                    : (isset($children[count($children) - 1]['data']['name']) ? $children[count($children) - 1]['data']['name'] : null);

                // Burst/gap = a full page whose OLDEST post is still newer than the
                // last one we processed → more unseen posts likely lie beyond it.
                $burst = (count($children) >= $limit) && ($oldestInPage !== null) && ($oldestInPage > $lastTimestamp);
            } while ($burst && $after && $page < $maxPages);

            if ($burst && $page >= $maxPages) {
                echo "⚠️ Hit max post pages ($maxPages) — posts older than this tick's reach may be uncaught.\n";
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

    /**
     * Create a floor from a parsed [New Floor] post. Shared by the live monitor
     * and the one-off reprocessor. Floor numbers may be negative (e.g. -101).
     */
    private function processFloorPost($title, $postId, $selftext, $redditUsername, $redditPostUrl)
    {
        if (preg_match("/^\[New Floor\]\[(-?\d+)\](.*)/i", $title, $matches)) {
            $floorNumber = $matches[1];
            $floorName = trim($matches[2]);

            echo "✅ Match found! Floor Number: $floorNumber, Floor Name: '$floorName'\n";

            // Check if floor number already exists
            if ($this->floorNumberExists($floorNumber)) {
                echo "⚠️ Floor number $floorNumber already exists. Notifying user.\n";

                // Reply to the Reddit post with a comment about the duplicate floor
                $this->replyToPost($postId, "Sorry, that floor has already been claimed. You can create a room on that floor if you like.");
                return;
            }

            // Get or create WordPress user for the Reddit author
            $authorId = $this->checkUserExists($redditUsername);
            if (!$authorId) {
                $authorId = $this->createWordPressUser($redditUsername);
            }

            $postBody = $this->createWordPressPost($floorName, $selftext, $floorNumber, $authorId, $redditUsername, $redditPostUrl);
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "Your Floor Has Been Created",
                "Your floor '$floorName' (number $floorNumber) has been successfully created on The Spiral Tower.\n\n" .
                "View it here: " . (isset($postBody['link']) ? $postBody['link'] : "https://www.thespiraltower.net/floor/") . "\n\n" .
                self::MORE_INFO_LINK
            );

            // Reply to the Reddit post with a comment
            $this->replyToPost($postId, "Floor '$floorName' has been created in the tower! View it here: " . $postBody['link'] . "\n\n" . self::MORE_INFO_LINK);
        } else {
            echo "No match found in this post title.\n";
        }
    }

    /**
     * One-off: fetch a single post by its Reddit ID (e.g. "1vleu1y") and run it
     * through the floor-creation path. Used to catch up a request the monitor
     * skipped. Does NOT touch the last-processed timestamp.
     */
    public function processFloorPostById($postId)
    {
        $postId = preg_replace('/^t3_/', '', trim($postId));
        echo "Fetching post t3_$postId from Reddit...\n";

        $response = $this->client->get("https://oauth.reddit.com/api/info", [
            'headers' => [
                'Authorization' => "Bearer {$this->accessToken}",
                'User-Agent' => $this->userAgent
            ],
            'query' => ['id' => 't3_' . $postId]
        ]);

        $data     = json_decode($response->getBody(), true);
        $children = isset($data['data']['children']) ? $data['data']['children'] : array();
        if (empty($children) || !isset($children[0]['data'])) {
            echo "❌ Post t3_$postId not found.\n";
            return;
        }

        $post           = $children[0]['data'];
        $title          = isset($post['title']) ? $post['title'] : '';
        $selftext       = isset($post['selftext']) ? $post['selftext'] : '';
        $redditUsername = isset($post['author']) ? $post['author'] : '';
        $redditPostUrl  = isset($post['permalink']) ? 'https://www.reddit.com' . $post['permalink'] : '';

        echo "Post Title: $title\n";
        echo "Reddit Author: $redditUsername\n";

        $this->processFloorPost($title, $postId, $selftext, $redditUsername, $redditPostUrl);
    }

    public function monitorPrivateMessages()
    {
        echo "Checking for new private messages...\n";

        $lastPMTimestamp = $this->getLastPMTimestamp();
        echo "Last processed PM timestamp: " . $lastPMTimestamp . " (" . date('Y-m-d H:i:s', $lastPMTimestamp) . ")\n";

        try {
            // Get all messages (not just unread) and let timestamp filtering handle duplicates
            $response = $this->client->get("https://oauth.reddit.com/message/inbox", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'User-Agent' => $this->userAgent
                ],
                'query' => [
                    'limit' => 25
                ]
            ]);

            $messages = json_decode($response->getBody(), true);

            if (!isset($messages['data']['children']) || empty($messages['data']['children'])) {
                echo "No private messages found.\n";
                return;
            }

            $newestPMTimestamp = $lastPMTimestamp;

            foreach ($messages['data']['children'] as $message) {
                if (!isset($message['data'])) {
                    continue;
                }

                $messageData = $message['data'];
                $messageId = $messageData['id'];
                $createdTime = $messageData['created_utc'];
                $subject = isset($messageData['subject']) ? $messageData['subject'] : '';
                $body = isset($messageData['body']) ? $messageData['body'] : '';
                $author = isset($messageData['author']) ? $messageData['author'] : '';
                $isComment = isset($messageData['was_comment']) ? $messageData['was_comment'] : false;

                // Keep track of the newest message timestamp
                if ($createdTime > $newestPMTimestamp) {
                    $newestPMTimestamp = $createdTime;
                }

                // Skip if message was received before last processed time
                if ($createdTime <= $lastPMTimestamp) {
                    continue;
                }

                // Skip comment replies (we only want direct private messages)
                if ($isComment) {
                    continue;
                }

                echo "Processing private message ID: $messageId from $author\n";
                echo "Subject: $subject\n";
                echo "Body: " . substr($body, 0, 100) . "...\n";

                // Process commands
                $this->processPrivateMessageCommand($author, $subject, $body, $messageId);
            }

            // Update the PM timestamp
            if ($newestPMTimestamp > $lastPMTimestamp) {
                echo "Updating last processed PM timestamp from " . date('Y-m-d H:i:s', $lastPMTimestamp) .
                    " to " . date('Y-m-d H:i:s', $newestPMTimestamp) . "\n";
                $this->updateLastPMTimestamp($newestPMTimestamp);
            }

        } catch (RequestException $e) {
            echo "❌ Error fetching private messages: " . $e->getMessage() . "\n";
            if ($e->hasResponse()) {
                echo "Response: " . $e->getResponse()->getBody() . "\n";
            }
        }
    }
    private function processPrivateMessageCommand($author, $subject, $body, $messageId)
    {
        // Normalize the message content for case-insensitive matching
        $normalizedSubject = strtolower(trim($subject));
        $normalizedBody = strtolower(trim($body));

        // Check for "Create Account" command
        if ($normalizedSubject === 'create account' || $normalizedBody === 'create account') {
            echo "Processing 'Create Account' command from $author\n";
            $this->handleCreateAccountCommand($author);
            return;
        }

        // Check for "Reset Password" command
        if ($normalizedSubject === 'reset password' || $normalizedBody === 'reset password') {
            echo "Processing 'Reset Password' command from $author\n";
            $this->handleResetPasswordCommand($author);
            return;
        }

        // Check for "Invite Me" command — a former member asking to be invited back.
        if ($normalizedSubject === 'invite me' || $normalizedBody === 'invite me') {
            echo "Processing 'Invite Me' command from $author\n";
            $this->handleInviteMeCommand($author);
            return;
        }

        echo "No recognized command found in message from $author\n";
    }
    private function handleCreateAccountCommand($redditUsername)
    {
        echo "Handling create account request for $redditUsername\n";

        // Check if user already exists
        $existingUserId = $this->checkUserExists($redditUsername);
        if ($existingUserId) {
            echo "User $redditUsername already has an account (ID: $existingUserId)\n";
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "Account Already Exists",
                "Hello! You already have an account on The Spiral Tower.\n\n" .
                "Username: " . strtolower($redditUsername) . "\n\n" .
                "If you've forgotten your password, please send me a private message with 'Reset Password' as the subject or message body.\n\n" .
                "You can log in at https://www.thespiraltower.net/wp-login.php"
            );
            return;
        }

        // Create new user
        $newUserId = $this->createWordPressUser($redditUsername);
        if (!$newUserId) {
            echo "❌ Failed to create account for $redditUsername\n";
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "Account Creation Failed",
                "Sorry, there was an error creating your account on The Spiral Tower.\n\n" .
                "This might be because:\n" .
                "- You already have an account (try 'Reset Password' instead)\n" .
                "- There was a technical issue\n\n" .
                "Please try again later or contact the administrator if the problem persists."
            );
        }
        // Note: Success message is sent from createWordPressUser() method
    }
    private function handleResetPasswordCommand($redditUsername)
    {
        echo "Handling password reset request for $redditUsername\n";

        // Check if user exists
        $userId = $this->checkUserExists($redditUsername);
        if (!$userId) {
            echo "User $redditUsername does not have an account\n";
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "Account Not Found",
                "Hello! You don't appear to have an account on The Spiral Tower yet.\n\n" .
                "To create an account, please send me a private message with 'Create Account' as the subject or message body.\n\n" .
                "Once you have an account, you can log in at https://www.thespiraltower.net/wp-login.php"
            );
            return;
        }

        // Generate new password
        $newPassword = $this->generateRandomPassword(12);

        // Update user password
        $success = $this->updateUserPassword($userId, $newPassword);
        if ($success) {
            echo "✅ Successfully reset password for $redditUsername (ID: $userId)\n";

            $username = strtolower($redditUsername);
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "Password Reset Complete",
                "Your password has been reset on The Spiral Tower.\n\n" .
                "Username: $username\n" .
                "New Password: $newPassword\n\n" .
                "You can log in at https://www.thespiraltower.net/wp-login.php\n\n" .
                "Please consider changing your password after logging in for security."
            );
        } else {
            echo "❌ Failed to reset password for $redditUsername\n";
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "Password Reset Failed",
                "Sorry, there was an error resetting your password on The Spiral Tower. Please try again later or contact the administrator."
            );
        }
    }
    /**
     * "Invite Me" — a redditor asks to be invited back onto r/TheSpiralTower.
     * Only honored for FORMER members (flushed / status = removed). Unknown
     * users, current Claw candidates (queued), and current members are refused.
     * On success the user is queued for invite; processPendingInvites() (later
     * this same tick) approves them as a contributor and messages them.
     */
    private function handleInviteMeCommand($redditUsername)
    {
        if (!$this->pluginsEnabled || !class_exists('STI_Database')) {
            echo "Invite Me ignored — game features disabled\n";
            return;
        }

        $user = STI_Database::get_user_by_username($redditUsername);

        if ($user && $user['status'] === STI_Database::STATUS_MEMBER) {
            echo "$redditUsername is already a current member\n";
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "You're already a member",
                "You're already an active member of r/TheSpiralTower — no invite needed."
            );
            return;
        }

        if (!$user || $user['status'] !== STI_Database::STATUS_REMOVED) {
            // Never a member (unknown or only ever a Claw candidate) — refuse.
            echo "Invite Me from $redditUsername refused (not a former member)\n";
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "Invite request",
                "We couldn't find you as a former member of r/TheSpiralTower, so we can't invite you back automatically."
            );
            return;
        }

        $ok = STI_Database::invite_now((int) $user['id']);
        if ($ok) {
            echo "✅ $redditUsername requested Invite Me and was queued for re-invite\n";
            $this->sendRedditPrivateMessage(
                $redditUsername,
                "You've been invited back",
                "Welcome back to r/TheSpiralTower! You've been re-approved and can post again. " .
                "You'll start fresh with no number until the next flush."
            );
        } else {
            echo "❌ Invite Me for $redditUsername failed (banned or ineligible)\n";
        }
    }

    private function updateUserPassword($userId, $newPassword)
    {
        echo "Updating password for user ID: $userId\n";
        try {
            $response = $this->client->post("https://www.thespiraltower.net/wp-json/wp/v2/users/$userId", [
                'auth' => [$this->wpUser, $this->wpPassword],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => [
                    'password' => $newPassword
                ],
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();

            if ($statusCode === 200 || $statusCode === 201) {
                echo "✅ Password updated successfully for user ID: $userId\n";
                return true;
            } else {
                echo "❌ Failed to update password. Status: $statusCode, Response: $responseBody\n";
                return false;
            }

        } catch (\Exception $e) {
            echo "❌ Error updating user password: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function getLastPMTimestamp()
    {
        if (!file_exists($this->lastProcessedPMFile)) {
            echo "⚠️ PM timestamp file not found. Using 0 as the start time.\n";
            return 0;
        }

        $timestamp = file_get_contents($this->lastProcessedPMFile);

        if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
            echo "⚠️ Invalid PM timestamp in file: $timestamp. Using 0 as the start time.\n";
            return 0;
        }

        return (int) $timestamp;
    }
    private function updateLastPMTimestamp($timestamp)
    {
        if (!is_numeric($timestamp) || $timestamp <= 0) {
            echo "⚠️ Invalid PM timestamp value: $timestamp. Not updating.\n";
            return;
        }

        $result = file_put_contents($this->lastProcessedPMFile, $timestamp);

        if ($result === false) {
            echo "❌ Failed to write PM timestamp to file. Check permissions on: {$this->lastProcessedPMFile}\n";
        } else {
            echo "✅ Updated PM timestamp file with value: $timestamp (" . date('Y-m-d H:i:s', $timestamp) . ")\n";
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
        if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
            echo "⚠️ Invalid timestamp in file: $timestamp. Using 0 as the start time.\n";
            return 0;
        }

        echo "Read timestamp: $timestamp (" . date('Y-m-d H:i:s', (int) $timestamp) . ")\n";
        return (int) $timestamp;
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

    private function createWordPressPost($title, $content, $floorNumber, $authorId = null, $redditUsername = null, $redditPostUrl = null)
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

                    if (isset($body['id'])) {
                        $postId = $body['id'];
                        echo "✅ sendRedditPrivateMessage root88: $postId\n";
                        // Send notification to root88 for testing
                        $this->sendRedditPrivateMessage(
                            "root88",
                            "Test Floor Created",
                            "A new floor was created on The Spiral Tower:\n\n" .
                            "Floor Number: $floorNumber\n" .
                            "Title: $title\n" .
                            "Created By: " . ($redditUsername ?: "Unknown") . "\n" .
                            "WordPress User ID: " . ($authorId ?: "None") . "\n\n" .
                            "Link: " . (isset($body['link']) ? $body['link'] : "Not available") . "\n\n" .
                            "Reddit Post: " . ($redditPostUrl ?: "Not available")
                        );
                        return $body;
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

    private function generateImageFromPrompt($prompt)
    {
        echo "Generating image for prompt: " . substr($prompt, 0, 50) . "...\n";

        // Enhance prompt for better image generation
        $prompt = $prompt . ' ' . $this->additionalImagePromptText;

        // Use the WordPress plugin's image generator (respects plugin settings)
        $generator = new Spiral_Tower_Image_Generator();
        $result = $generator->generate_image_from_api($prompt);

        if (is_wp_error($result)) {
            echo "❌ Image generation failed: " . $result->get_error_message() . "\n";
            return null;
        }

        echo "✅ Image generated successfully. URL: " . $result['url'] . "\n";
        return $result['url'];
    }

    private function debugOpenAIAPI()
    {
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

    private function uploadImageToWordPress($image_url, $post_id)
    {
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
        add_filter('wp_insert_post_data', function ($data) {
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
                    $post_id,
                    '_thumbnail_id',
                    $attachment_id
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
    private function verifyWordPressImage($attachment_id, $post_id)
    {
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





    private function updateFeaturedImage($post_id, $attachment_id)
    {
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
                'meta_value' => (string) $attachment_id
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
            (string) $attachment_id
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
    private function fallbackImageUpload($file_array, $post_id)
    {
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
            'guid' => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $type['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
            'post_content' => '',
            'post_status' => 'inherit'
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
        // Convert to lowercase and remove underscores since WordPress usernames are stored this way
        $sanitizedUsername = strtolower(str_replace('_', '', $username));
        echo "Checking if WordPress user '$username' (sanitized: '$sanitizedUsername') exists...\n";
    
        try {
            $response = $this->client->get("https://www.thespiraltower.net/wp-json/wp/v2/users", [
                'auth' => [$this->wpUser, $this->wpPassword],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'query' => [
                    'search' => $sanitizedUsername,
                    'per_page' => 10 // Increased to catch more potential matches
                ]
            ]);
            $users = json_decode($response->getBody(), true);
            if (is_array($users) && count($users) > 0) {
                foreach ($users as $user) {
                    // Check both slug and username, both in lowercase
                    $userSlug = isset($user['slug']) ? strtolower($user['slug']) : '';
                    $userName = isset($user['username']) ? strtolower($user['username']) : '';
    
                    if ($userSlug === $sanitizedUsername || $userName === $sanitizedUsername) {
                        echo "✅ User '$username' found with ID: {$user['id']} (WordPress username: '{$user['username']}')\n";
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
    
        // Use the Reddit username in lowercase and remove underscores for WordPress
        $username = strtolower(str_replace('_', '', $redditUsername));
        echo "Using sanitized username: '$username' (from Reddit: '$redditUsername')\n";
    
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
            $responseBody = (string) $response->getBody();
    
            if ($statusCode === 201 || $statusCode === 200) {
                $user = json_decode($responseBody, true);
    
                if (isset($user['id'])) {
                    echo "✅ User account created for '$redditUsername' with ID: {$user['id']}\n";
    
                    // Send the Reddit user their credentials
                    $this->sendRedditPrivateMessage(
                        $redditUsername,
                        "Your Spiral Tower Account",
                        "Hello! Your account has been created on The Spiral Tower.\n\n" .
                        "Reddit Username: $redditUsername\n" .
                        "WordPress Username: $username\n" .
                        "Password: $password\n\n" .
                        "You can log in at https://www.thespiraltower.net/wp-login.php\n\n" .
                        "Note: Your WordPress username has underscores removed as they're not allowed.\n\n" .
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
        $words = [
            'ceiling', 'pancakes', 'floor', 'pizza', 'spiral', 'tower', 'dagger', 'robe', 
            'sacrifice', 'blood', 'towerling', 'dream', 'dungeon', 'alter', 'cult', 
            'koolaid', 'library', 'dark', 'light', 'knife', 'towel', 'wumpus', 'potion', 
            'lore', 'traveler', 'arcade', 'diamond', 'gold', 'coin', 'ring', 'cat', 
            'sexy', 'portal', 'axe', 'flush', 'scroll'
        ];
        
        // Pick 3 random words
        $selectedWords = array_rand($words, 3);
        
        // Create password with hyphens
        $password = $words[$selectedWords[0]] . '-' . $words[$selectedWords[1]] . '-' . $words[$selectedWords[2]];
        
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


    private function getLastCommentTimestamp()
    {
        if (empty($this->lastProcessedCommentFile)) {
            echo "⚠️ Comment file property not set, using default filename\n";
            $this->lastProcessedCommentFile = 'last_processed_comment_timestamp.txt';
        }

        $filePath = __DIR__ . '/' . $this->lastProcessedCommentFile;
        echo "Looking for comment timestamp file at: $filePath\n";
        echo "Filename property: '{$this->lastProcessedCommentFile}'\n";
        echo "Directory: " . __DIR__ . "\n";

        if (!file_exists($filePath)) {
            echo "⚠️ Comment timestamp file not found. Creating initial file with current timestamp.\n";

            $currentTime = time();
            $result = file_put_contents($filePath, $currentTime);

            if ($result === false) {
                echo "❌ Failed to create initial timestamp file. Check permissions on directory: " . __DIR__ . "\n";
                return 0;
            } else {
                echo "✅ Created initial timestamp file with value: $currentTime (" . date('Y-m-d H:i:s', $currentTime) . ")\n";
                return $currentTime;
            }
        }

        $timestamp = file_get_contents($filePath);
        echo "Raw file contents: '" . var_export($timestamp, true) . "'\n";
        echo "File size: " . filesize($filePath) . " bytes\n";

        if ($timestamp === false) {
            echo "❌ Failed to read timestamp file\n";
            return 0;
        }

        $timestamp = trim($timestamp);
        echo "Trimmed timestamp: '$timestamp'\n";

        if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
            echo "⚠️ Invalid timestamp in file: '$timestamp'. Using current time.\n";
            $currentTime = time();
            file_put_contents($filePath, $currentTime);
            echo "✅ Reset timestamp file to: $currentTime\n";
            return $currentTime;
        }

        $timestampInt = (int) $timestamp;
        echo "Valid timestamp found: $timestampInt (" . date('Y-m-d H:i:s', $timestampInt) . ")\n";
        return $timestampInt;
    }

    /**
     * SAFETY CHECK: Update timestamp with safety check
     */
    private function updateLastCommentTimestamp($timestamp)
    {
        if (empty($this->lastProcessedCommentFile)) {
            echo "⚠️ Comment file property not set, using default filename\n";
            $this->lastProcessedCommentFile = 'last_processed_comment_timestamp.txt';
        }

        if (!is_numeric($timestamp) || $timestamp <= 0) {
            echo "⚠️ Invalid comment timestamp value: $timestamp. Not updating.\n";
            return;
        }

        $filePath = __DIR__ . '/' . $this->lastProcessedCommentFile;
        echo "Writing timestamp $timestamp to file: $filePath\n";

        $result = file_put_contents($filePath, (string) $timestamp, LOCK_EX);

        if ($result === false) {
            echo "❌ Failed to write comment timestamp to file.\n";
        } else {
            echo "✅ Successfully wrote $result bytes to timestamp file\n";

            // Verify the write
            $verification = file_get_contents($filePath);
            echo "Verification read: '$verification'\n";
        }
    }

    /**
     * Monitor comments with better duplicate prevention
     */
    public function monitorComments()
    {
        echo "Checking for new comments with /create room requests...\n";

        $lastCommentTimestamp = $this->getLastCommentTimestamp();
        echo "Starting comment processing from timestamp: " . $lastCommentTimestamp . " (" . date('Y-m-d H:i:s', $lastCommentTimestamp) . ")\n";

        $newestCommentTimestamp = $lastCommentTimestamp;
        $roomRequestsFound = 0;
        $processedComments = 0;

        $limit    = 100;
        $after    = null;
        $page     = 0;
        $burst    = false;
        $maxPages = 10; // burst safety cap: up to 1000 comments in a single tick

        try {
            // Normally one page (100 comments) is plenty. But if a page comes back
            // full AND its oldest comment is still newer than what we last recorded,
            // more than 100 comments arrived since the last tick (a burst) — so we
            // page back until we reach comments we've already seen, to miss none.
            do {
                $page++;
                $query = array('limit' => $limit);
                if ($after) {
                    $query['after'] = $after;
                }

                $response = $this->client->get("https://oauth.reddit.com/r/{$this->subreddit}/comments", [
                    'headers' => [
                        'Authorization' => "Bearer {$this->accessToken}",
                        'User-Agent' => $this->userAgent
                    ],
                    'query' => $query
                ]);

                $comments = json_decode($response->getBody(), true);
                $children = isset($comments['data']['children']) ? $comments['data']['children'] : array();

                if (empty($children)) {
                    if ($page === 1) {
                        echo "No comments found. Nothing to do.\n";
                    }
                    break;
                }
                if ($page > 1) {
                    echo "Comment burst — paging back (page $page) to catch comments beyond the first 100...\n";
                }

                $oldestInPage = null;

                foreach ($children as $comment) {
                if (!isset($comment['data'])) {
                    continue;
                }

                $commentData = $comment['data'];
                $commentId = $commentData['id'];
                $createdTime = $commentData['created_utc'];
                $commentBody = isset($commentData['body']) ? $commentData['body'] : '';
                $commentAuthor = isset($commentData['author']) ? $commentData['author'] : '';
                $parentId = isset($commentData['link_id']) ? str_replace('t3_', '', $commentData['link_id']) : '';
                $commentUrl = isset($commentData['permalink']) ? 'https://www.reddit.com' . $commentData['permalink'] : '';

                // Track the oldest comment on this page (listing is newest-first) to
                // decide whether a burst means we should page back further.
                if ($oldestInPage === null || $createdTime < $oldestInPage) {
                    $oldestInPage = $createdTime;
                }

                // Keep track of the newest comment timestamp regardless of whether we process it
                if ($createdTime > $newestCommentTimestamp) {
                    $newestCommentTimestamp = $createdTime;
                }

                // Skip if comment was created before last processed time
                if ($createdTime <= $lastCommentTimestamp) {
                    continue;
                }

                $processedComments++;

                // Game feature: track this author's activity / auto-enroll new members.
                $this->recordSubredditActivity($commentAuthor);

                // ONLY process room creation requests
                if (preg_match("/^\/create room\s+(.+)/i", trim($commentBody), $matches)) {
                    $roomRequestsFound++;
                    $roomName = trim($matches[1]);

                    echo "\n=== PROCESSING ROOM REQUEST #$roomRequestsFound ===\n";
                    echo "Comment ID: $commentId from $commentAuthor\n";
                    echo "Created: " . date('Y-m-d H:i:s', $createdTime) . " (timestamp: $createdTime)\n";
                    echo "Room Name: '$roomName'\n";

                    try {
                        // Get the parent post to verify it's a floor creation post
                        $parentPost = $this->getPostById($parentId);
                        if (!$parentPost) {
                            echo "❌ Could not find parent post with ID: $parentId\n";
                            continue;
                        }

                        $parentTitle = $parentPost['title'];
                        $parentAuthor = $parentPost['author'];

                        echo "Parent post: '$parentTitle' by $parentAuthor\n";

                        // Check if parent post is a [New Floor] post and if the commenter is the original author
                        if (!preg_match("/^\[New Floor\]/i", $parentTitle)) {
                            echo "⚠️ Parent post is not a [New Floor] post, skipping\n";
                            continue;
                        }

                        if ($commentAuthor !== $parentAuthor) {
                            echo "⚠️ Comment author ($commentAuthor) is not the same as post author ($parentAuthor), skipping\n";
                            $this->replyToComment($commentId, "Sorry, only the creator of the floor can add rooms to it.");
                            continue;
                        }

                        // Find the WordPress floor ID for this Reddit post
                        $floorId = $this->findFloorByRedditPost($parentTitle, $parentAuthor);
                        if (!$floorId) {
                            echo "❌ Could not find WordPress floor for this Reddit post\n";
                            $this->replyToComment($commentId, "Sorry, I couldn't find the corresponding floor in the tower. Working on a fix...");
                            continue;
                        }

                        echo "✅ Found WordPress floor ID: $floorId\n";

                        // Create the room and portals
                        $this->createRoomWithPortals($floorId, $roomName, $commentAuthor, $commentId, $commentUrl);

                    } catch (\Exception $e) {
                        echo "❌ Error processing comment $commentId: " . $e->getMessage() . "\n";

                        $errorMessage = "❌ ERROR processing Reddit comment:\n\n" .
                            "Comment ID: $commentId\n" .
                            "Author: $commentAuthor\n" .
                            "Body: $commentBody\n" .
                            "Error: " . $e->getMessage() . "\n" .
                            "File: " . $e->getFile() . "\n" .
                            "Line: " . $e->getLine() . "\n\n" .
                            "Timestamp: " . date('Y-m-d H:i:s');

                        $this->sendRedditPrivateMessage(
                            "root88",
                            "Bot Error - Comment Processing Failed",
                            $errorMessage
                        );
                    }
                    echo "=== END ROOM REQUEST PROCESSING ===\n\n";
                }
                }

                // Cursor for the next page (Reddit gives data.after; fall back to
                // the last child's fullname).
                $after = (isset($comments['data']['after']) && $comments['data']['after'])
                    ? $comments['data']['after']
                    : (isset($children[count($children) - 1]['data']['name']) ? $children[count($children) - 1]['data']['name'] : null);

                // Burst = a full page whose OLDEST comment is still newer than the
                // last one we recorded → there are likely more unseen comments past it.
                $burst = (count($children) >= $limit) && ($oldestInPage !== null) && ($oldestInPage > $lastCommentTimestamp);
            } while ($burst && $after && $page < $maxPages);

            if ($burst && $page >= $maxPages) {
                echo "⚠️ Hit max comment pages ($maxPages) during a burst — comments older than this tick's reach may be uncaught.\n";
            }

            echo "Summary: Processed $processedComments new comments across $page page(s), found $roomRequestsFound room requests\n";

            // ALWAYS update the timestamp to prevent reprocessing, even if no room requests found
            if ($newestCommentTimestamp > $lastCommentTimestamp) {
                echo "Updating comment timestamp from " . date('Y-m-d H:i:s', $lastCommentTimestamp) .
                    " to " . date('Y-m-d H:i:s', $newestCommentTimestamp) . "\n";
                $this->updateLastCommentTimestamp($newestCommentTimestamp);
            } else {
                echo "No newer comments found, timestamp remains at " . date('Y-m-d H:i:s', $lastCommentTimestamp) . "\n";
            }

        } catch (RequestException $e) {
            echo "❌ Error fetching comments: " . $e->getMessage() . "\n";
            if ($e->hasResponse()) {
                echo "Response: " . $e->getResponse()->getBody() . "\n";
            }
        }
    }

    /**
     * Get a Reddit post by ID
     */
    private function getPostById($postId)
    {
        try {
            $response = $this->client->get("https://oauth.reddit.com/r/{$this->subreddit}/comments/{$postId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'User-Agent' => $this->userAgent
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data[0]['data']['children'][0]['data'])) {
                $postData = $data[0]['data']['children'][0]['data'];
                return [
                    'title' => $postData['title'],
                    'author' => $postData['author'],
                    'selftext' => $postData['selftext'] ?? '',
                    'created_utc' => $postData['created_utc']
                ];
            }

            return null;
        } catch (RequestException $e) {
            echo "❌ Error fetching post $postId: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * Find WordPress floor ID by Reddit post details
     */
    private function findFloorByRedditPost($title, $author)
    {
        // Extract floor number and name from title
        if (preg_match("/^\[New Floor\]\s*\[([^\]]*)\](.*)/i", $title, $matches)) {
            $floorNumber = trim($matches[1]);
            $floorName = trim($matches[2]);

            echo "Looking for floor: Number='$floorNumber', Name='$floorName', Author='$author'\n";

            try {
                // Search for floors by title and author
                $sanitizedAuthor = strtolower(str_replace('_', '', $author));

                $response = $this->client->get("https://www.thespiraltower.net/wp-json/wp/v2/floor", [
                    'auth' => [$this->wpUser, $this->wpPassword],
                    'query' => [
                        'search' => $floorName,
                        'per_page' => 50
                    ]
                ]);

                $floors = json_decode($response->getBody(), true);

                foreach ($floors as $floor) {
                    // Check if this floor matches our criteria
                    $floorTitle = $floor['title']['rendered'];
                    $floorAuthorId = $floor['author'];

                    // Get author username
                    $authorResponse = $this->client->get("https://www.thespiraltower.net/wp-json/wp/v2/users/{$floorAuthorId}", [
                        'auth' => [$this->wpUser, $this->wpPassword]
                    ]);
                    $authorData = json_decode($authorResponse->getBody(), true);
                    $wpUsername = strtolower($authorData['slug']);

                    // Check if titles match and authors match
                    if (stripos($floorTitle, $floorName) !== false && $wpUsername === $sanitizedAuthor) {
                        // If we have a floor number, verify it matches
                        if (!empty($floorNumber) && is_numeric($floorNumber)) {
                            $floorMeta = $this->getFloorMeta($floor['id']);
                            if ($floorMeta['floor_number'] != $floorNumber) {
                                continue;
                            }
                        }

                        echo "✅ Found matching floor: ID {$floor['id']}\n";
                        return $floor['id'];
                    }
                }

            } catch (\Exception $e) {
                echo "❌ Error searching for floor: " . $e->getMessage() . "\n";
            }
        }

        return null;
    }

    /**
     * Get floor meta data
     */
    private function getFloorMeta($floorId)
    {
        try {
            $response = $this->client->get("https://www.thespiraltower.net/wp-json/wp/v2/floor/{$floorId}", [
                'auth' => [$this->wpUser, $this->wpPassword]
            ]);

            $floor = json_decode($response->getBody(), true);
            return [
                'floor_number' => $floor['floor_number'] ?? '',
                'title' => $floor['title']['rendered'] ?? ''
            ];
        } catch (\Exception $e) {
            echo "❌ Error getting floor meta: " . $e->getMessage() . "\n";
            return [];
        }
    }

    /**
     * Get floor name for portal naming
     */
    private function getFloorName($floorId)
    {
        try {
            $response = $this->client->get("https://www.thespiraltower.net/wp-json/wp/v2/floor/{$floorId}", [
                'auth' => [$this->wpUser, $this->wpPassword]
            ]);

            $floor = json_decode($response->getBody(), true);

            if (isset($floor['title']['rendered'])) {
                return $floor['title']['rendered'];
            }

            return "Floor";

        } catch (\Exception $e) {
            echo "⚠️ Error getting floor name: " . $e->getMessage() . "\n";
            return "Floor";
        }
    }

    /**
     * Create room with portals
     */
    private function createRoomWithPortals($floorId, $roomName, $redditUsername, $commentId, $commentUrl = null)
    {
        echo "Creating room '$roomName' on floor $floorId for user $redditUsername...\n";

        // Get or create WordPress user
        $authorId = $this->checkUserExists($redditUsername);
        if (!$authorId) {
            echo "⚠️ WordPress user not found for $redditUsername, using ID 1 and notifying root88\n";
            $authorId = 1;

            $this->sendRedditPrivateMessage(
                "root88",
                "Room Creation - User Not Found",
                "Room creation attempted by Reddit user '$redditUsername' but no WordPress user found.\n\n" .
                "Room: '$roomName'\n" .
                "Floor ID: $floorId\n" .
                "Room was assigned to user ID 1."
            );
        }

        try {
            // Get floor name for the return portal
            $floorName = $this->getFloorName($floorId);

            // Create the room
            $roomData = [
                'title' => $roomName,
                'content' => '', // Empty content as requested
                'status' => 'publish',
                'author' => (int) $authorId,
                'meta' => [
                    '_room_floor_id' => $floorId,
                    '_room_type' => 'normal'
                ]
            ];

            $response = $this->client->post("https://www.thespiraltower.net/wp-json/wp/v2/room", [
                'auth' => [$this->wpUser, $this->wpPassword],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'json' => $roomData
            ]);

            $roomResult = json_decode($response->getBody(), true);

            if (!isset($roomResult['id'])) {
                throw new Exception("Failed to create room: " . json_encode($roomResult));
            }

            $roomId = $roomResult['id'];
            echo "✅ Successfully created room with ID: $roomId\n";

            // Set room floor ID using the REST API fields from Room Manager
            try {
                echo "Setting room floor ID using REST API fields...\n";
                $updateResponse = $this->client->post("https://www.thespiraltower.net/wp-json/wp/v2/room/$roomId", [
                    'auth' => [$this->wpUser, $this->wpPassword],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ],
                    'json' => [
                        'floor_id' => (int) $floorId,
                        'room_type' => 'normal'
                    ]
                ]);

                $updateResult = json_decode($updateResponse->getBody(), true);
                echo "✅ Room meta update successful\n";
            } catch (\Exception $e) {
                echo "⚠️ Couldn't update room meta, trying direct meta approach: " . $e->getMessage() . "\n";

                // Fallback to direct meta update
                $fallbackResponse = $this->client->post("https://www.thespiraltower.net/wp-json/wp/v2/room/$roomId", [
                    'auth' => [$this->wpUser, $this->wpPassword],
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ],
                    'json' => [
                        'meta' => [
                            '_room_floor_id' => (string) $floorId,
                            '_room_type' => 'normal'
                        ]
                    ]
                ]);
            }

            // Create floor → room portal (invisible with proper origin/destination)
            $floorToRoomPortalId = $this->createPortal(
                $roomName,
                'floor',
                $floorId,
                'room',
                $roomId,
                'invisible',
                $authorId
            );

            // Create room → floor portal (invisible with proper origin/destination)
            $roomToFloorPortalId = $this->createPortal(
                $floorName,
                'room',
                $roomId,
                'floor',
                $floorId,
                'invisible',
                $authorId
            );

            // Generate and upload image for the room
            echo "Starting image generation and upload process for room...\n";
            $imagePrompt = "make super interesting digital art like an unreal engine master. it should have lots of intricate unique details, beautiful lighting and vibrant color schemes. It should be an image of " . $roomName;
            $imageurl = $this->generateImageFromPrompt($imagePrompt);

            if (!empty($imageurl)) {
                $attachment_id = $this->uploadImageToWordPress($imageurl, $roomId);
                if ($attachment_id) {
                    echo "✅ Complete image workflow successful for room. Attachment ID: $attachment_id\n";
                } else {
                    echo "⚠️ Room image was generated but upload failed\n";
                }
            } else {
                echo "⚠️ Room image generation failed, skipping upload\n";
            }

            // Reply to the Reddit comment
            $roomUrl = isset($roomResult['link']) ? $roomResult['link'] : "https://www.thespiraltower.net/room/";
            $this->replyToComment($commentId, "Room '$roomName' has been created! You can access it here: $roomUrl\n\n" . self::MORE_INFO_LINK);

            // Notify root88
            $this->sendRedditPrivateMessage(
                "root88",
                "Room Created Successfully",
                "A new room was created:\n\n" .
                "Room: '$roomName' (ID: $roomId)\n" .
                "Floor: '$floorName' (ID: $floorId)\n" .
                "Created By: $redditUsername\n" .
                "WordPress User ID: $authorId\n" .
                "Floor→Room Portal ID: $floorToRoomPortalId (title: '$roomName', invisible)\n" .
                "Room→Floor Portal ID: $roomToFloorPortalId (title: '$floorName', invisible)\n" .
                "Image Generated: " . (!empty($imageurl) ? "Yes" : "No") . "\n\n" .
                "Link: $roomUrl\n\n" .
                "Reddit Comment: " . ($commentUrl ?: "Not available")
            );

        } catch (\Exception $e) {
            echo "❌ Error creating room: " . $e->getMessage() . "\n";

            $this->replyToComment($commentId, "Sorry, there was an error creating your room. Working on a fix...");

            $this->sendRedditPrivateMessage(
                "root88",
                "Room Creation Failed",
                "Failed to create room '$roomName' for user $redditUsername on floor $floorId.\n\n" .
                "Error: " . $e->getMessage()
            );

            throw $e;
        }
    }

    /**
     * Create a portal using portal_settings
     */
    private function createPortal($title, $originType, $originId, $destinationType, $destinationId, $portalType, $authorId)
    {
        echo "Creating portal '$title' ($originType → $destinationType, type: $portalType)\n";

        // Build portal settings
        $portalSettings = [
            'portal_type' => $portalType,
            'position_x' => '50',
            'position_y' => '50',
            'scale' => '100',
            'disable_pointer' => false,
            'disable_tooltip' => false,
            'use_custom_size' => false,
            'origin_type' => $originType,
            'destination_type' => $destinationType
        ];

        // Set origin and destination IDs
        if ($originType === 'floor') {
            $portalSettings['origin_floor_id'] = (string) $originId;
            $portalSettings['origin_room_id'] = '';
        } else {
            $portalSettings['origin_room_id'] = (string) $originId;
            $portalSettings['origin_floor_id'] = '';
        }

        if ($destinationType === 'floor') {
            $portalSettings['destination_floor_id'] = (string) $destinationId;
            $portalSettings['destination_room_id'] = '';
        } else {
            $portalSettings['destination_room_id'] = (string) $destinationId;
            $portalSettings['destination_floor_id'] = '';
        }

        // Create portal with settings
        $portalData = [
            'title' => $title,
            'status' => 'publish',
            'author' => (int) $authorId,
            'portal_settings' => $portalSettings
        ];

        echo "Creating portal with settings: " . json_encode($portalSettings) . "\n";

        $response = $this->client->post("https://www.thespiraltower.net/wp-json/wp/v2/portal", [
            'auth' => [$this->wpUser, $this->wpPassword],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'json' => $portalData
        ]);

        $portalResult = json_decode($response->getBody(), true);

        if (!isset($portalResult['id'])) {
            throw new Exception("Failed to create portal '$title': " . json_encode($portalResult));
        }

        $portalId = $portalResult['id'];
        echo "✅ Successfully created portal '$title' with ID: $portalId\n";

        return $portalId;
    }

    /**
     * Reply to a Reddit comment
     */
    private function replyToComment($commentId, $message)
    {
        echo "Replying to comment ID: $commentId...\n";
        try {
            $response = $this->client->post("https://oauth.reddit.com/api/comment", [
                'headers' => [
                    'Authorization' => "Bearer {$this->accessToken}",
                    'User-Agent' => $this->userAgent
                ],
                'form_params' => [
                    'thing_id' => "t1_$commentId", // 't1_' is the prefix for comments
                    'text' => $message
                ]
            ]);

            echo "✅ Reply sent to comment ID: $commentId\n";
        } catch (RequestException $e) {
            echo "❌ Error replying to comment: " . $e->getMessage() . "\n";
            if ($e->hasResponse()) {
                echo "Response: " . $e->getResponse()->getBody() . "\n";
            }
        }
    }

    /**
     * Main execution method - runs both post monitoring and private message monitoring
     */
    /**
     * Game feature: stamp a subreddit author's last-post date, enrolling them
     * as a member if we've never seen them. Fully isolated — flag-guarded and
     * try/caught so it can never disrupt floor/room processing. Skips deleted
     * authors, AutoModerator, and the bot's own account.
     */
    private function recordSubredditActivity($author)
    {
        if (!$this->pluginsEnabled) {
            return;
        }
        if (empty($author) || $author === '[deleted]') {
            return;
        }
        if (strcasecmp($author, 'AutoModerator') === 0 || strcasecmp($author, $this->redditUsername) === 0) {
            return;
        }
        if (!class_exists('STI_Database')) {
            return;
        }
        try {
            STI_Database::touch_member($author);
        } catch (\Throwable $e) {
            error_log('[activity] touch_member failed for ' . $author . ': ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Reddit moderation actions (need modcontributors / modflair scopes)  */
    /* ------------------------------------------------------------------ */

    private function approveContributor($username)
    {
        echo "Approving u/$username as contributor on r/{$this->subreddit}...\n";
        try {
            $response = $this->client->post("https://oauth.reddit.com/r/{$this->subreddit}/api/friend", [
                'headers'     => ['Authorization' => "Bearer {$this->accessToken}", 'User-Agent' => $this->userAgent],
                'form_params' => ['api_type' => 'json', 'type' => 'contributor', 'name' => $username],
                'http_errors' => false,
            ]);
            $code = $response->getStatusCode();
            $body = json_decode($response->getBody(), true);
            $errs = isset($body['json']['errors']) ? $body['json']['errors'] : array();
            if ($code === 200 && empty($errs)) {
                echo "✅ Approved u/$username\n";
                return true;
            }
            echo "⚠️ Approve u/$username failed ($code): " . ($errs ? json_encode($errs) : $response->getBody()) . "\n";
            return false;
        } catch (\Exception $e) {
            echo "❌ approveContributor($username): " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function removeContributor($username)
    {
        echo "Removing u/$username as contributor on r/{$this->subreddit}...\n";
        try {
            $response = $this->client->post("https://oauth.reddit.com/r/{$this->subreddit}/api/unfriend", [
                'headers'     => ['Authorization' => "Bearer {$this->accessToken}", 'User-Agent' => $this->userAgent],
                'form_params' => ['type' => 'contributor', 'name' => $username],
                'http_errors' => false,
            ]);
            $code = $response->getStatusCode();
            if ($code === 200) {
                echo "✅ Removed u/$username\n";
                return true;
            }
            echo "⚠️ Remove u/$username returned $code: " . $response->getBody() . "\n";
            return false;
        } catch (\Exception $e) {
            echo "❌ removeContributor($username): " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Submit a self (text) post to the subreddit. Needs the `submit` scope.
     */
    private function postToSubreddit($title, $body)
    {
        echo "Posting to r/{$this->subreddit}: \"$title\"\n";
        try {
            $response = $this->client->post("https://oauth.reddit.com/api/submit", [
                'headers'     => ['Authorization' => "Bearer {$this->accessToken}", 'User-Agent' => $this->userAgent],
                'form_params' => array(
                    'api_type' => 'json',
                    'sr'       => $this->subreddit,
                    'kind'     => 'self',
                    'title'    => $title,
                    'text'     => $body,
                ),
                'http_errors' => false,
            ]);
            $code = $response->getStatusCode();
            $b    = json_decode($response->getBody(), true);
            $errs = isset($b['json']['errors']) ? $b['json']['errors'] : array();
            if ($code === 200 && empty($errs)) {
                $url = isset($b['json']['data']['url']) ? $b['json']['data']['url'] : '';
                echo "✅ Posted: $url\n";
                return true;
            }
            echo "⚠️ Post failed ($code): " . $response->getBody() . "\n";
            return false;
        } catch (\Exception $e) {
            echo "❌ postToSubreddit: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function setUserFlairNumber($username, $number)
    {
        try {
            $response = $this->client->post("https://oauth.reddit.com/r/{$this->subreddit}/api/flair", [
                'headers'     => ['Authorization' => "Bearer {$this->accessToken}", 'User-Agent' => $this->userAgent],
                'form_params' => ['api_type' => 'json', 'name' => $username, 'text' => '#' . (int) $number],
                'http_errors' => false,
            ]);
            $code = $response->getStatusCode();
            if ($code === 200) {
                return true;
            }
            echo "⚠️ Flair u/$username => #$number returned $code: " . $response->getBody() . "\n";
            return false;
        } catch (\Exception $e) {
            echo "❌ setUserFlairNumber($username): " . $e->getMessage() . "\n";
            return false;
        }
    }

    /**
     * Fetch the subreddit's approved-users (contributor) list from Reddit.
     * Paginated. Returns an array of usernames, or null if the fetch failed
     * (e.g. the bot isn't a moderator yet — needs mod access).
     */
    private function fetchApprovedUsers()
    {
        $users = array();
        $after = null;
        $pages = 0;
        do {
            try {
                $query = array('limit' => 100);
                if ($after) {
                    $query['after'] = $after;
                }
                $response = $this->client->get("https://oauth.reddit.com/r/{$this->subreddit}/about/contributors", [
                    'headers'     => ['Authorization' => "Bearer {$this->accessToken}", 'User-Agent' => $this->userAgent],
                    'query'       => $query,
                    'http_errors' => false,
                ]);
                $code = $response->getStatusCode();
                if ($code !== 200) {
                    echo "⚠️ Approved-users fetch returned $code (bot needs mod access): " . $response->getBody() . "\n";
                    return null;
                }
                $data     = json_decode($response->getBody(), true);
                $children = isset($data['data']['children']) ? $data['data']['children'] : array();
                foreach ($children as $c) {
                    if (!empty($c['name'])) {
                        $users[] = $c['name'];
                    }
                }
                $after = isset($data['data']['after']) ? $data['data']['after'] : null;
                $pages++;
            } catch (\Exception $e) {
                echo "❌ fetchApprovedUsers: " . $e->getMessage() . "\n";
                return null;
            }
        } while ($after && $pages < 100);

        return $users;
    }

    /**
     * Fetch every user's flair from Reddit. Paginated. Returns a
     * lowercase-username => flair-text map (empty on failure — flair is
     * optional context, so a failure here doesn't abort a roster pull).
     */
    private function fetchUserFlair()
    {
        $flair = array();
        $after = null;
        $pages = 0;
        do {
            try {
                $query = array('limit' => 1000);
                if ($after) {
                    $query['after'] = $after;
                }
                $response = $this->client->get("https://oauth.reddit.com/r/{$this->subreddit}/api/flairlist", [
                    'headers'     => ['Authorization' => "Bearer {$this->accessToken}", 'User-Agent' => $this->userAgent],
                    'query'       => $query,
                    'http_errors' => false,
                ]);
                if ($response->getStatusCode() !== 200) {
                    echo "⚠️ Flair-list fetch returned " . $response->getStatusCode() . " (bot needs mod access)\n";
                    return $flair;
                }
                $data = json_decode($response->getBody(), true);
                foreach ((isset($data['users']) ? $data['users'] : array()) as $u) {
                    if (!empty($u['user'])) {
                        $flair[strtolower($u['user'])] = isset($u['flair_text']) ? $u['flair_text'] : '';
                    }
                }
                $after = isset($data['next']) ? $data['next'] : null;
                $pages++;
            } catch (\Exception $e) {
                echo "❌ fetchUserFlair: " . $e->getMessage() . "\n";
                return $flair;
            }
        } while ($after && $pages < 100);

        return $flair;
    }

    /**
     * Pre-flush double check: did this user submit a POST in the home subreddit
     * in the past week? Uses Reddit search restricted to the sub — a sub-side,
     * hide-proof signal that only needs the `read` scope. Comments can't be
     * queried per-user from the sub side, so those stay covered by the bot's
     * live activity tracking.
     *
     * FAIL-SAFE: returns true (spare them) if the check can't be completed, so
     * a search hiccup never causes us to wrongly flush an active member.
     *
     * @return bool true = posted this week (or unverifiable → spare).
     */
    private function userPostedInSubThisWeek($username)
    {
        try {
            $response = $this->client->get("https://oauth.reddit.com/r/{$this->subreddit}/search", [
                'headers'     => ['Authorization' => "Bearer {$this->accessToken}", 'User-Agent' => $this->userAgent],
                'query'       => array(
                    'q'           => 'author:' . $username,
                    'restrict_sr' => 1,
                    'sort'        => 'new',
                    't'           => 'week',
                    'limit'       => 10,
                ),
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() !== 200) {
                echo "⚠️ Post-check for u/$username returned " . $response->getStatusCode() . " — sparing (fail-safe)\n";
                return true;
            }
            $data     = json_decode($response->getBody(), true);
            $children = isset($data['data']['children']) ? $data['data']['children'] : array();
            $cutoff   = time() - 7 * 24 * 3600;
            foreach ($children as $c) {
                $d = isset($c['data']) ? $c['data'] : array();
                if (!empty($d['author']) && strcasecmp($d['author'], $username) === 0
                    && isset($d['created_utc']) && $d['created_utc'] >= $cutoff) {
                    return true;
                }
            }
            return false;
        } catch (\Exception $e) {
            echo "❌ userPostedInSubThisWeek($username): " . $e->getMessage() . " — sparing (fail-safe)\n";
            return true;
        }
    }

    /**
     * Pull Reddit's approved-users list + flair and reconcile it against our
     * roster: enroll/refresh each approved user (storing their flair and the
     * number parsed from it) and report members we have that Reddit does not.
     * Runs before the weekly Flush as a safety check. No-op (with a note) until
     * the bot has mod access.
     */
    private function pullAndVerifyRoster()
    {
        if (!$this->pluginsEnabled || !class_exists('STI_Database')) {
            return array('ok' => false, 'skipped' => true, 'reason' => 'Game features disabled');
        }
        echo "\n=== Verifying roster against Reddit's approved users ===\n";
        $approved = $this->fetchApprovedUsers();
        if ($approved === null) {
            echo "Roster verify skipped — approved-users list unavailable (mod access pending).\n";
            return array('ok' => false, 'skipped' => true, 'reason' => 'Could not fetch approved users — the bot needs moderator access.');
        }
        $flair     = $this->fetchUserFlair();
        $dbMembers = STI_Database::member_username_map(); // lowercase => name

        $approvedLower = array();
        $added         = 0;
        foreach ($approved as $name) {
            $lower                 = strtolower($name);
            $approvedLower[$lower] = true;
            $existed               = isset($dbMembers[$lower]);
            $f                     = isset($flair[$lower]) ? $flair[$lower] : null;
            if (STI_Database::sync_reddit_member($name, $f) && !$existed) {
                $added++;
            }
        }

        $missingOnReddit = array();
        foreach ($dbMembers as $lower => $name) {
            if (!isset($approvedLower[$lower])) {
                $missingOnReddit[] = $name;
            }
        }

        echo "Approved on Reddit: " . count($approved) . " | newly added to our roster: $added\n";
        echo "In our roster but NOT approved on Reddit: " . count($missingOnReddit) . "\n";
        if ($missingOnReddit) {
            echo "  -> " . implode(', ', array_slice($missingOnReddit, 0, 50))
                . (count($missingOnReddit) > 50 ? ' …' : '') . "\n";
        }

        return array(
            'ok'       => true,
            'skipped'  => false,
            'approved' => count($approved),
            'added'    => $added,
            'missing'  => $missingOnReddit,
        );
    }

    /**
     * Drain the invite queue: approve each pending invite as a contributor and
     * send the invite message ONCE (only if they've never received it). Honors
     * the global dry-run and messages toggles. Isolated + guarded.
     */
    private function processPendingInvites()
    {
        if (!$this->pluginsEnabled || !class_exists('STI_Database')) {
            return;
        }
        $pending = STI_Database::get_pending_invites();
        if (!$pending) {
            return;
        }
        $dryRun          = (int) STI_Settings::get('dry_run') === 1;
        $inviteMsgOn     = (int) STI_Settings::get('invite_message_enabled') === 1;
        $inviteMessage   = trim((string) STI_Settings::get('invite_message'));

        echo "\nProcessing " . count($pending) . " pending invite(s)...\n";
        foreach ($pending as $u) {
            $username = $u['reddit_username'];
            $needsMsg = empty($u['invite_message_sent']) && $inviteMsgOn && $inviteMessage !== '';
            if ($dryRun) {
                echo "[dry-run] would approve u/$username" . ($needsMsg ? " + send invite message" : "") . "\n";
                continue; // leave pending so it runs for real once dry-run is off
            }
            $this->approveContributor($username);
            $sent = false;
            if ($needsMsg) {
                $sent = $this->sendRedditPrivateMessage($username, "You've been invited to r/TheSpiralTower", $inviteMessage);
            }
            STI_Database::mark_invited($u['id'], $sent);
        }
    }

    /**
     * At the flush, invite everyone the Claw queued: approve each as a Reddit
     * contributor, send the invite message once, and move them onto the roster
     * (member, no number until the next flush). Empties the invite list.
     */
    private function inviteQueuedUsers($dryRun)
    {
        if (!$this->pluginsEnabled || !class_exists('STI_Database')) {
            return;
        }
        $queued = STI_Database::get_users(STI_Database::STATUS_QUEUED);
        if (!$queued) {
            echo "Invite list empty — no users to invite.\n";
            return;
        }
        $inviteMsgOn   = (int) STI_Settings::get('invite_message_enabled') === 1;
        $inviteMessage = trim((string) STI_Settings::get('invite_message'));
        $cap           = (int) STI_Settings::get('target_user_count');           // hard roster cap
        $members       = STI_Database::count_by_status(STI_Database::STATUS_MEMBER);

        echo count($queued) . " on the invite list — inviting up to the $cap-member cap (currently $members)...\n";
        $invited = 0;
        foreach ($queued as $u) {
            if ($cap > 0 && $members >= $cap) {
                echo "Reached the $cap-member cap — skipping the remaining " . (count($queued) - $invited) . " on the list.\n";
                break;
            }
            $username = $u['reddit_username'];
            if ($dryRun) {
                echo "[dry-run] Invited User u/$username\n";
                $members++; $invited++;
                continue;
            }
            $this->approveContributor($username);
            $sent = false;
            if ($inviteMsgOn && $inviteMessage !== '' && empty($u['invite_message_sent'])) {
                $sent = $this->sendRedditPrivateMessage($username, "You've been invited to r/TheSpiralTower", $inviteMessage);
            }
            STI_Database::update_user($u['id'], array(
                'status'              => STI_Database::STATUS_MEMBER,
                'invite_date'         => STI_Database::now(), // first_active; number stays null until the next flush
                'number'              => null,
                'invite_pending'      => 0,
                'invite_message_sent' => $sent ? STI_Database::now() : $u['invite_message_sent'],
            ));
            echo "Invited User u/$username\n";
            $members++; $invited++;
            usleep(1200000); // ~50/min — stay under Reddit's ~60/min or approvals get silently dropped
        }

        // Clear the invite list so the Claw rebuilds a fresh batch of active users next week.
        if ($dryRun) {
            echo "[dry-run] would clear the invite list (Claw starts fresh next week).\n";
        } else {
            $dropped = STI_Database::clear_queue();
            echo "Cleared the invite list — $invited invited, $dropped dropped. Claw starts fresh.\n";
        }
    }

    /**
     * The weekly Flush. Disabled by default (flush_enabled). When due: remove
     * the at-risk members as contributors, flush them, renumber every surviving
     * member by seniority, post the FLUSH! announcement, then invite the Claw's
     * queued list. Honors dry-run. Isolated + guarded.
     */
    private function runFlushIfDue()
    {
        if (!$this->pluginsEnabled || !class_exists('STI_Database')) {
            return;
        }
        if (!STI_Settings::flush_is_due()) {
            return;
        }
        // Claim this flush window up front. Paced Reddit calls make a real flush
        // take several minutes, so marking it here stops the next cron tick from
        // re-firing it while it's still running.
        STI_Settings::mark_flush_ran();

        // Pre-flush safety: reconcile our roster against Reddit's approved users
        // and their flair before we remove or renumber anyone.
        $this->pullAndVerifyRoster();

        $dryRun          = (int) STI_Settings::get('dry_run') === 1;
        $goodbyeOn       = (int) STI_Settings::get('goodbye_message_enabled') === 1;
        $goodbye         = trim((string) STI_Settings::get('goodbye_message'));

        $lastFlush = STI_Settings::last_flush_datetime();
        $prevFlush = (clone $lastFlush)->modify('-7 day');
        // At the flush moment last_flush is ~now, so evaluate the week that just
        // ended: flush members who have NOT posted since the PREVIOUS flush.
        $atRisk    = STI_Database::get_at_risk_members($prevFlush->format('Y-m-d H:i:s'));

        echo "\n===== RUNNING FLUSH =====\n";
        echo count($atRisk) . " at-risk member(s) to remove\n";

        $flushed = array();
        foreach ($atRisk as $u) {
            $username  = $u['reddit_username'];
            $hadNumber = !empty($u['number']);

            // Never flush a protected/unflushable member (e.g. a moderator).
            // They appear in the at-risk LIST for the counts, but are never removed.
            if (STI_Database::is_unflushable($username)) {
                echo "Skipping u/$username — on the unflushable (protected) list\n";
                continue;
            }

            // Per-user double check against the sub before removing anyone.
            if ($this->userPostedInSubThisWeek($username)) {
                echo "Sparing u/$username — recent post found in the sub this week\n";
                if (!$dryRun) {
                    STI_Database::record_activity($username); // refresh last_active
                }
                continue;
            }

            // This member is being flushed — record them (with the number they
            // held) for the FLUSH! announcement post.
            $flushed[] = array(
                'number'   => $u['number'] !== null ? (int) $u['number'] : null,
                'username' => $username,
            );

            $numLabel = $hadNumber ? "#{$u['number']} " : "";
            if ($dryRun) {
                echo "[dry-run] Flushed User {$numLabel}u/$username\n";
                continue;
            }
            $this->removeContributor($username);
            if ($hadNumber && $goodbyeOn && $goodbye !== '') {
                $this->sendRedditPrivateMessage($username, "You've been removed from r/TheSpiralTower", $goodbye);
            }
            STI_Database::flush_user($username, 'Flushed for inactivity');
            echo "Flushed User {$numLabel}u/$username\n";
        }

        $survivors = STI_Database::get_members_ordered_by_number();
        $n = 1;
        $changed = 0;
        foreach ($survivors as $u) {
            $current = $u['number'] !== null ? (int) $u['number'] : null;
            if ($current !== $n) {                // only rewrite when the number actually moved
                if (!$dryRun) {
                    STI_Database::set_number($u['id'], $n);
                    $this->setUserFlairNumber($u['reddit_username'], $n);
                    usleep(1200000); // pace flair writes under Reddit's ~60/min limit
                }
                $changed++;
            }
            $n++;
        }
        echo ($dryRun ? "[dry-run] " : "") . "Renumbered " . count($survivors) . " members — "
            . "$changed flair change" . ($changed === 1 ? "" : "s") . " written\n";

        // Announce the flush on the subreddit — always, even if nobody was flushed.
        if ($flushed) {
            usort($flushed, function ($a, $b) {
                if ($a['number'] === null && $b['number'] === null) { return strcasecmp($a['username'], $b['username']); }
                if ($a['number'] === null) { return 1; }
                if ($b['number'] === null) { return -1; }
                return $a['number'] - $b['number'];
            });
            $lines = array();
            foreach ($flushed as $f) {
                $lines[] = $f['number'] !== null
                    ? '* \#' . $f['number'] . ' u/' . $f['username']
                    : '* u/' . $f['username'];
            }
            $body = "Flushed down the great Spiral for a week of inactivity:\n\n" . implode("\n", $lines);
        } else {
            $body = "Amazingly no one was flushed... or I am completely broken!";
        }
        if ($dryRun) {
            echo "[dry-run] Created Flush Post (FLUSH!):\n$body\n";
        } else {
            $this->postToSubreddit('FLUSH!', $body);
            echo "Created Flush Post\n";
        }

        // After the flush, invite the Claw's queued list onto the roster.
        $this->inviteQueuedUsers($dryRun);

        // (mark_flush_ran already called at the start to claim the window.)
        if (!$dryRun) {
            STI_Settings::mark_flush_actual();   // true "when the flush ran" for the Flush List
            STI_Database::clear_claw_log();
        }
        echo "===== FLUSH COMPLETE =====\n";
    }

    public function run()
    {
        echo "\n===== STARTING REDDIT BOT MONITORING =====\n";
        echo "Monitoring subreddit: r/{$this->subreddit}\n";
        echo "Bot username: {$this->redditUsername}\n";
        echo "WordPress site: {$this->wpUrl}\n";
        echo "===========================================\n\n";

        // Gap detection: if we haven't run for 5+ minutes, note it. The post and
        // comment monitors page back to the last item they recorded, so they
        // catch up automatically (bounded by Reddit's ~1000-item pagination).
        $lastRunFile = __DIR__ . '/last_run_timestamp.txt';
        $lastRun     = file_exists($lastRunFile) ? (int) file_get_contents($lastRunFile) : 0;
        if ($lastRun > 0) {
            $gap = time() - $lastRun;
            if ($gap >= 300) {
                echo "⚠️ Gap of " . round($gap / 60) . " min since last run (last ran " . date('Y-m-d H:i:s', $lastRun) . ") — posts/comments will page back to catch up.\n\n";
            }
        }
        // Monitor posts for [New Floor] tags
        $this->monitorPosts();

        echo "\n";

        // Monitor private messages for commands
        $this->monitorPrivateMessages();

        echo "\n";

        // Monitor comments for /create room requests
        $this->monitorComments();

        // Private feature plugins (e.g. The Claw) run last, so core bot work is
        // already done. Fully isolated: flag-guarded and try/caught so a plugin
        // can never break floor/room processing.
        if ($this->pluginsEnabled) {
            try {
                $plugins = new BotPluginManager();
                $plugins->load(__DIR__ . '/plugins');
                $plugins->tick();
            } catch (\Throwable $e) {
                error_log('[plugins] manager error: ' . $e->getMessage());
            }

            // Execute pending invites and the weekly Flush (both guarded/isolated
            // so they can never break core floor/room processing).
            try {
                $this->processPendingInvites();
            } catch (\Throwable $e) {
                error_log('[invites] ' . $e->getMessage());
            }
            try {
                $this->runFlushIfDue();
            } catch (\Throwable $e) {
                error_log('[flush] ' . $e->getMessage());
            }

            // On-demand roster pull requested from the admin page.
            try {
                if (class_exists('STI_Settings') && STI_Settings::pull_requested()) {
                    $result = $this->pullAndVerifyRoster();
                    STI_Settings::set_pull_result(is_array($result) ? $result : array('ok' => false, 'skipped' => true, 'reason' => 'Unknown error'));
                    STI_Settings::clear_pull_request();
                }
            } catch (\Throwable $e) {
                error_log('[pull] ' . $e->getMessage());
                if (class_exists('STI_Settings')) {
                    STI_Settings::set_pull_result(array('ok' => false, 'skipped' => true, 'reason' => 'Error: ' . $e->getMessage()));
                    STI_Settings::clear_pull_request();
                }
            }
        }

        // Stamp this successful run so the next tick can measure any gap.
        @file_put_contents(__DIR__ . '/last_run_timestamp.txt', time());

        echo "\n===== BOT MONITORING COMPLETE =====\n";
    }
}

// Create bot instance and start monitoring posts
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$bot = new RedditBot($config['reddit']['subreddit']);

// One-off catch-up: `php reddit_bot.php --floor=<postId>` reprocesses a single
// [New Floor] post the monitor skipped, without running the full loop.
if (PHP_SAPI === 'cli' && isset($argv[1]) && strpos($argv[1], '--floor=') === 0) {
    $bot->processFloorPostById(substr($argv[1], strlen('--floor=')));
} else {
    $bot->run();
}