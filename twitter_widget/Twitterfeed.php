<?php

/**
 * BI Live Twitter Feed Utilities
 */
class Util_Twitterfeed extends Bi_BaseCliObject
{
    /**
     * @var string
     */
    protected static $_logFormat = '%message%';

	/**
	 * Returns feed post for given vertical if one is active
	 *
	 * @param string vertical Name of vertical being checked
	 * @return Post|null
	 */
	public static function getActiveFeed($vertical, $position = null) 
	{
		$query = array(
			'type' 		 => 'feed',
			'live' 		 => true, 
			'start_time' => array('$lte' => new MongoDate(time())),
			'end_time'	 => array('$gte' => new MongoDate(time())),
		);
		
		// Main vertical
		$vertical = Util_Site::getVertical($vertical);
		if ($vertical->isMainVertical()) {
			$query['vertical'] = 'main';
			$query['widget_homepage'] = true;
		}
		// Secondary verticals
		else {
			$query['verticals'] = $vertical->name;
			$query['widget_vertical'] = true;
		}
		
		// Position on right rail (above or below fold)
		$query['widget_position'] = $position ?: 'below_fold';
		
		$feeds = Post::find($query, array(
			'sort'  => array('start_time' => -1),
			'limit' => 1
		));
		foreach ($feeds as $feed) {
			return $feed;
		}
		
		return null;
	}
	
	/**
	 * Returns latest tweets since $since_id
	 *
	 * @return array
	 */
	public static function getLatestTweets(Post $feed, $since_id = null, $limit = null)
	{
		$since_id 	   = (double) $since_id;
		$latest_tweets = array();
		
		foreach ((array) $feed->content_tweets as $tweet) {
			if ((double) $tweet['post_id'] > $since_id)
				$latest_tweets[] = $tweet;
			else
				break;
		}
		
		if ($limit)
			return array_slice($latest_tweets, 0, $limit);
		
		return $latest_tweets;
	}
    
    /**
	 * Batch Import latest tweets for all authors in a given feed
	 * 
	 * @param Post $feed Post object for the feed to get tweets for
	 * @param bool $log If false it will suppress logging statements
     */
	public static function importTweets(Post $feed, $log = true)
	{
		$dirty = false;
		
		foreach ($feed->twitter_handles as $name => $data) {

			if($log) {
				self::log("{$name} (since id={$data['last_post_id']}, hashtag={$feed->hashtag})");
			}

			$latest_redux = array();

			// Get latest tweets from this author
			$latest_tweets = self::_getLatestTweets($name, $feed->hashtag, $data['last_post_id']);			
			foreach ($latest_tweets as $tweet) {
    			$created_at = strtotime($tweet['created_at']);

				// Tweets are sorted by time so we can just break once
				// we find a tweet that's older then our feed's start time
				if ($created_at < $feed->start_time->sec)
					break;
					
				// Make sure tweet's author is the author we're looking for
				// In other words ignore retweets by other people
				if ($tweet['from_user'] != $name)
					continue;

				// Save the tweet!
    			$latest_redux[ $tweet['id'] ] = array(
    				'author'	 => $tweet['from_user'],
    				'text'		 => self::_processLinks($tweet['text']),
					// ID must be a string cause it's bigger than INT32
    				'post_id'	 => (string) $tweet['id'],
    				'created_at' => new MongoDate($created_at),
    			);

				// Store author last tweet's id
				$data['last_post_id'] = (string) $tweet['id'];
				// Update authors twitter image
				$data['twitter_avatar_url'] = $tweet['profile_image_url'];
				
				// Save author's data
				$names = $feed->twitter_handles;
				$names[$name] = $data;
				$feed->twitter_handles = $names;
			}
						
			$current_tweets = (array) $feed->content_tweets;
			// Save new tweets
			foreach ($current_tweets as $tweet) {
				// Remove existing tweets from the latest tweets
				$tweet_id = $tweet['post_id'];
				if (isset($latest_redux[$tweet_id])) {
					unset($latest_redux[$tweet_id]);
				}
			}
			
			// Anything new?
			if ($latest_redux) {
				$latest_redux = array_values($latest_redux);
				$tweets = array_merge($current_tweets, $latest_redux);
				usort($tweets, function($a, $b) {
					return (double) $b['post_id'] - (double) $a['post_id'];
				});		
				$feed->content_tweets = $tweets;
								
				$dirty = true;
			}

			if($log) {
				self::log(count($latest_redux) . " tweets");
			}
		}
		
		// Did we get any new tweets?
		$dirty && $feed->save();
	}

	/**
	 * Run a search on Twitter to get the latest tweets for a given author/hashtag
	 * 
	 * @param  string	$name 	Twitter handle of the author (from)
	 * @param  string	$hashtag 	Hashtag to limit search results on (#query)
	 * @param  int		$since_id 	Twitter post id to limit search results on (since_id)
	 * @return array 				Search Results
	 */
	private static function _getLatestTweets($name = null, $hashtag = null, $since_id = null) 
	{
		// author OR hashtag must be set
		if (!$name && !$hashtag) {
			throw new Exception("Neither author nor hashtag is passed");
		}
		
		// Search query
		$search = '';
		if ($name) {
			$search = "from:{$name}"; 
		}
		if ($hashtag) {
			if ($name) $search .= ' ';
			$search .= "#{$hashtag}";
		}
		
		// Parameters
		$params = array(
			'rpp' 		  => 50, 		// results per page (max=100)
			'result_type' => 'recent', 	// only show recent, not popular
		); 
		if ($since_id > 0) { 
			$params['since_id'] = $since_id;
		}

		// run the search
		try {
			$twitterSearch = new Zend_Service_Twitter_Search('json');
			$searchResults = $twitterSearch->search($search, $params);

			// over rate limit or something
			if (!isset($searchResults['results']))
				return array();
			
			$results = $searchResults['results'];	
			usort($results, function($a, $b) {
				return (double) $b['id'] - (double) $a['id'];
			});
			return $results;				
		}
		catch (Exception $e) {
			return array();
		}
	}	
	
	/**
	 * Process post text for href links, wrap them in anchor tag
	 * 
	 * @param string $text Post text
	 * @return string $text Post text processed for links
	 */
	private static function _processLinks($text) 
	{
		// wrap links in anchor tag
		$text = preg_replace('~((http|https)://([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,6}(/[\w+\?\-_&;\.\=]*)*)~', '<a href="$1" target="_blank">$1</a>', $text);
		// replace @ reposts with link to user
		$text = preg_replace('~(?:^|\s)@(\w{1,15})~', ' <a href="http://twitter.com/$1" target="_blank">@$1</a>', $text);
		// replace # hashtags with link to search on hashtag
		$text = preg_replace('~(?:^|\s)#(\w+)~', ' <a href="http://twitter.com/#!/search?q=%23$1">#$1</a>', $text);
		// process text for images and add them inline
		$text = self::_processInlineImages($text);
		 
		return $text;
	}

	/**
	 * Process post text for image hosting links, display images inline
	 * 
	 * @param string $text Post text
	 * @return string $text Post text processed for inline images
	 */
	private static function _processInlineImages($text) 
	{
		if (preg_match('~http://yfrog.com/\w+~', $text, $img_match)) {
			$yfrog_url = $img_match[0];
			
			$ch = curl_init();

			$curl_opts = array(
				CURLOPT_URL				=> $yfrog_url,
				CURLOPT_HEADER			=> false,
				CURLOPT_FOLLOWLOCATION	=> true,
				CURLOPT_RETURNTRANSFER	=> true,
			);
			
			curl_setopt_array($ch, $curl_opts);
			
			if ($yfrog_result = curl_exec($ch)) {
				//find 'main image' - tags not always in order
				if(preg_match('~<img.*id="main_image".*>~', $yfrog_result, $yfrog_main_img)) {
					//var_dump($yfrog_main_img);
					preg_match('~src="([\w+:/\.?=&]+)~', $yfrog_main_img[0], $yfrog_img);
					//var_dump($yfrog_img);
					$text = $text . '<div class="inline_img"><img src="'.$yfrog_img[1].'"></div>';
				}
			}
			curl_close($ch);
		}
		
		return $text;
	}	
}