var LiveFeed = {
	params: 	{},
	truncate : 	false,
	
	init: function(params, truncate) {
		this.params   = params;
		this.truncate = truncate;

		setTimeout(LiveFeed.updateFeed, 60000);
	},
	
	updateFeed: function() {
		// Update timestamps
		$("div#twitter_top span.time").each(function() {
			var span = $(this);
			var date = new Date();
			var time = date.getTime() - 1000 * parseInt(span.attr("data-time"));
			span.text(elapsed_time(time) + " ago");
		});
		
		// Load more tweets
		$.ajax({
			type: 	  'GET',
			url: 	  '/live_feed',
			data: 	  LiveFeed.params,
			dataType: 'json',
			success:  function(response) {
				LiveFeed.params.last_id = response.last_id;
				$("#twitter_top").prepend(response.html);

				// Remove "Loading"
				if (response.last_id) {
					$("#noposts").remove();
				}

				// Show only so many tweets
				if (LiveFeed.truncate) {
		            $("#twitter_top").find(".twitter-module-post").each(function(e) {
		                if (e >= LiveFeed.truncate) {
							$(this).next().remove();
							$(this).remove();
						}
		            });
				}
			}
		});	
		
		setTimeout(LiveFeed.updateFeed, 60000);
	}
};