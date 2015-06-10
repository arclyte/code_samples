/**
 * Wrapper for Google Maps V3 API functionality
 */
var gMap = {
	map:				{},
	mapLoaded:			null,
	infoWindow:			{},
	markersArray:		[],
	options:			{},
	bounds:				{},
	autocomplete:		null,
	geocoder:			null,
	currentLocation:	{},
	reSearchVisible:	false,
	reCenter:			false,
	detailBoxVisible:	false,
	photos:				[],
	
	// Set up google map with basic options
	init: function(options) {
		var defaultOptions = {
			city: {
				center: {lat: 40.763381209080215, lng: -73.97712707519531}, // Midtown Manhattan
				bounds: {
					swLat: 40.58319212142312, // Avenel, NJ 
					swLng: -74.29023742675781,
					neLat: 40.943083108558135, // Rye, NY
					neLng: -73.66470336914062,
				}
			}, 
			mapElement: 'map_canvas',
			mapOptions: {
				zoom: 12,
				mapTypeControl: false,
				mapTypeId: google.maps.MapTypeId.ROADMAP
			}
		};
	
		if(typeof options != 'undefined' && !$.isEmptyObject(options)) {
			gMap.options = $.extend(true, {}, defaultOptions, options);
		} else {
			gMap.options = defaultOptions;
		}

		gMap.map = new google.maps.Map(document.getElementById(gMap.options.mapElement), gMap.options.mapOptions);
		
		gMap.bounds = new google.maps.LatLngBounds();
		
		// set default location
		gMap.setLocation();
		
		gMap.infoWindow = new google.maps.InfoWindow({});
		
		// After map loads, populate garage markers
		google.maps.event.addListener(gMap.map, 'idle', function() {
			if(gMap.mapLoaded) {
				google.maps.event.clearListeners(gMap.map, 'idle');
			} else {
				if (typeof garages != 'undefined' && !$.isEmptyObject(garages)) {
					populateGarages();
					gMap.reCenter = true;
				}
		
				gMap.mapLoaded = true;
			}
		});
		
		// Only show re-search button on list page
		if ($('div.filters-form').length > 0) {
			// Show re-search from current location button when map center changes
			google.maps.event.addListener(gMap.map, 'dragend', function() {
				if (gMap.reCenter == true) {
					var div = $('<div class="geoSearchBox"></div>')
					.append($('<a href="#">Redo Search For This Location</>'))
					.click(function() {
						var location = gMap.map.getCenter();
						gMap.currentLocation = gMap.getLocation($('#searchbar_lat').val(), $('#searchbar_lng').val());
						setSearchPos(location.lat(), location.lng(), true);
					})
					.get(0);

					if (gMap.reSearchVisible == false) {
						gMap.map.controls[google.maps.ControlPosition.TOP_CENTER].push(div);
						gMap.reSearchVisible = true;
					}
				}
			});
		}
	},

	/**
	 * Google Maps Javascript API V3
	 */
	
	// Set map position to fix on geolocation point
	setLocation: function() {
		if($('#searchbar_lat').length != 0 && $('#searchbar_lng') != 0 &&
			$('#searchbar_lat').val() != '' && $('#searchbar_lng').val() != '' &&
			$('#searchbar_lat').val() != 0 && $('#searchbar_lng').val() != 0) {
			// Use location set by search
			gMap.currentLocation = gMap.getLocation($('#searchbar_lat').val(), $('#searchbar_lng').val());
			var zoom = 14;
		} else if (typeof geoLocate != 'undefined' && geoLocate.located == 'geo') {
			setSearchPos(geoLocate.pos.lat, geoLocate.pos.lng, false);
			gMap.currentLocation = gMap.getLocation(geoLocate.pos.lat, geoLocate.pos.lng);
			var zoom = 16;
		} else if (typeof geoLocate != 'undefined' && geoLocate.located == 'ip') {
			// FIXME - What if ip location isn't in the city? Get nearest city center/default?
			setSearchPos(geoLocate.geoIp.lat, geoLocate.geoIp.lon, false);
			gMap.currentLocation = gMap.getLocation(geoLocate.geoIp.lat, geoLocate.geoIp.lon);
			var zoom = 12;
		} else {
			// Default location if geolocation/geoip failed
			//FIXME Don't set default location if Neighborhood is selected
			gMap.currentLocation = gMap.getLocation(gMap.options.city.center.lat, gMap.options.city.center.lng);
			var zoom = 12;
		}
		
		gMap.clearMarkers();
		gMap.addMarker(0, gMap.currentLocation, null, null, 'home', true);
		gMap.map.setCenter(gMap.currentLocation);
		gMap.map.setZoom(zoom);	
	},
	
	// Add a marker to the markers array
	addMarker: function(id, gLatLng, rate, infotext, icon, show) {
		var marker_url = '/getMarker';

		if (rate !== null && rate !== undefined) {
			marker_url += '/' + rate;
		}

		if (icon !== null && icon !== undefined) {
			if (rate == null || rate == undefined) {
				marker_url += '/null';
			}
			marker_url += '/' + icon;
		}
		
		// marker icon
		var image = new google.maps.MarkerImage(
		marker_url,
		new google.maps.Size(25,37),
		new google.maps.Point(0,0),
		new google.maps.Point(13,37)
	);
		
		// default marker image shadow
		if (typeof icon == 'undefined') icon = 'image';
		var shadow = new google.maps.MarkerImage(
		'/img/marker-images/' + icon + '_shadow.png',
		new google.maps.Size(47,37),
		new google.maps.Point(0,0),
		new google.maps.Point(13,37)
	);
			
		var marker = new google.maps.Marker({
			map: gMap.map,
			position: gLatLng,
			icon: image,
			shadow: shadow
		});
		
		gMap.markersArray[id] = marker;
		
		if (infotext != '' && infotext != null && typeof infotext != 'undefined') {
			google.maps.event.addListener(marker, 'mouseover', function () {
				gMap.infoWindow.setContent(infotext);
				gMap.infoWindow.open(gMap.map, this);
			});
			
			google.maps.event.addListener(marker, 'mouseout', function () {
				gMap.infoWindow.setContent();
				gMap.infoWindow.close(gMap.map, this);
			});
			
			google.maps.event.addListener(marker, 'click', function () {
				gMap.showDetailsFrame(id);
			});
			
			google.maps.event.addListener(gMap.infoWindow, 'domready', function () {
				$('a.info-link').click(function(){
					var garage_id = $(this).attr('id');
					gMap.showDetailsFrame(garage_id);
				});
			});
		}
		
		// extend the boundaries to contain all of the points
		gMap.bounds.extend(gLatLng);
		
		if(show === true) {
			gMap.showMarkers();
		}
	},

	// remove all markers from the map
	clearMarkers: function() {
		if (gMap.markersArray) {
			for (i in gMap.markersArray) {
				gMap.markersArray[i].setMap(null);
			}
			gMap.markersArray.length = 0;
		}
	},
	
	// load all markers onto the map
	showMarkers: function() {
		if (gMap.markersArray) {
			for (i in gMap.markersArray) {
				gMap.markersArray[i].setMap(gMap.map);
			}
		}
	},
	
	// shortcut to create new point
	getLocation: function(lat, lng) {
		return new google.maps.LatLng(lat, lng);
	},

	/**
	 * Places API Autocomplete
	 * - requires jQuery
	 */
	
	// Initialize autocomplete search box
	setAutocomplete: function(options) {
		// Default options, overwritten by init
		var defaultOptions = {
			// Input Text field to bind autocomplete to
			searchInput: 'searchbar_search',
			
			bounds: new google.maps.LatLngBounds(
			new google.maps.LatLng(gMap.options.city.bounds.neLat, gMap.options.city.bounds.neLng),
			new google.maps.LatLng(gMap.options.city.bounds.swLat, gMap.options.city.bounds.swLng)
		)
		};
		
		// overwrite defaults (uses jQuery)
		if(typeof options != 'undefined' && !$.isEmptyObject(options)) {
			options = $.extend(true, {}, defaultOptions, options);
		} else {
			options = defaultOptions;
		}

		var autocompleteOptions = {
			//			bounds: options.bounds
		};
		
		var input = document.getElementById(options.searchInput);
		
		gMap.autocomplete = new google.maps.places.Autocomplete(input, autocompleteOptions);
		
		// Set default value of search box text (Autocomplete uses HTML5 placeholder attrib)
		$('#' + options.searchInput).attr('placeholder', 'Enter an Address or Landmark');
		
		gMap.autocomplete.bindTo('bounds', gMap.map); 
	
		// Add map listener to update via the autocomplete text search box
		google.maps.event.addListener(gMap.autocomplete, 'place_changed', function() {
			// Add Place Lat/Lng to hidden fields of searchbar to pass along
			var place = gMap.autocomplete.getPlace();
			if (typeof place.geometry != "undefined") {
				setSearchPos(place.geometry.location.lat(), place.geometry.location.lng(), true);
			}
		});
		
		// Prevent enter on autocomplete from submitting form
//		google.maps.event.addDomListener(input, 'keydown', function(e) {
//			if (e.keyCode == 13) {
//				if (e.preventDefault) {
//					e.preventDefault();
//				} else {
//					e.cancelBubble = true;
//					e.returnValue = false;
//				}
//			}
//		});
	},
	
	/**
	 * Geocoding API
	 * 
	 * @param object options Google Maps Geocode API options object literal
	 * @param function callback Geocoder will not return data so a callback is required
	 */
	getGeocode: function(options, callback) {
		if(null === gMap.geocoder) {
			gMap.geocoder = new google.maps.Geocoder();
		}
		
		// Do a reverse lookup of current location by default
		var defaultOptions = {
			latLng: gMap.currentLocation,
			region: 'US'
		}
		
		if(typeof options != 'undefined' && !$.isEmptyObject(options)) {
			options = $.extend(true, {}, defaultOptions, options);
		} else {
			options = defaultOptions;
		}
		
		// address geocode, remove latlng
		if (options.address != 'undefined') {
			delete options.latLng;
		}
		
		var geocode_return = false;
		
		gMap.geocoder.geocode( options, function(results, status) {
			switch (status) {
				// indicates that the geocode was successful but returned no results. This may occur if the geocode was passed a non-existent address or a latng in a remote location.
				case google.maps.GeocoderStatus.ZERO_RESULTS:
				// indicates that you are over your quota.
				case google.maps.GeocoderStatus.OVER_QUERY_LIMIT:
				// indicates that your request was denied for some reason.
				case google.maps.GeocoderStatus.REQUEST_DENIED:
				// generally indicates that the query (address or latLng) is missing.
				case google.maps.GeocoderStatus.INVALID_REQUEST:
				break;
				case google.maps.GeocoderStatus.OK:
					dance:
						for (i in results) {
						switch (results[i].types[0]) {
							case 'street_address':
							case 'neighborhood':
							case 'intersection':
							case 'premise':
							case 'locality':
								if ('undefined' != typeof options.latLng) {
									// Reverse Geocode - grab closest address we can find
									geocode_return = results[i].formatted_address;
								} else if ('undefined' != typeof options.address) {
									// Geocode - grab location of closest match
									geocode_return = results[0].geometry.location
								}
								break dance;
						}
					}
					break;
			} 

			callback(geocode_return);
		});
	},
	
	/**
	* Check if a given bounds contains a point
	*/
	containsPoint: function(bounds, point) {
		if (!(bounds.sw.lat <= point.lat && point.lat <= bounds.ne.lat)) return false;
		return (bounds.sw.lng <= point.lng && point.lng <= bounds.ne.lng);
	},
	
	// get garage details
	updateDetailButtons: function(id) {
		$.ajax({
			url: '/detail/' + id,
			async: false,
			dataType: 'json',
			success: function(data) {
				// Gallery photos
				gMap.photos = data.photos;
				
				// Map buttons
				$('.geoDetailBox-blank .btn-directions a').attr('href', data.directions);
				
				$('.geoDetailBox-blank .btn-favorites a')
					.attr('class', data.isFavorite ? 'userfav' : 'userfav rem')
					.attr('href', (data.isFavorite ? '/removefav/' : '/addfav/') + id)
					.attr('title', data.isFavorite ? 'Add to your favorite parking spots!' : 'Remove this garage from your list of favorites');
				
				var fav_btn = 'btn-favorite.png';
				if (login) {
					if (!data.isFavorite) {
						'btn-unfavorite.png';
					}
				}
				$('.geoDetailBox-blank .btn-favorites img').attr('src', '/img/' + fav_btn);
				
				// Tax calculator
				$('#taxable_rate').val(data.rate);
			}
		});
		
		return true;
	},
	
	showDetailsFrame: function(id) {
		$('.geoDetailBox').hide(); // hide detail buttons if they're showing until they're updated
		$('#details-frame').show();
		// Load details page into iframe on listSuccess
		$('#details-frame').attr('src', '/show/' + id + '?frame=false');
		
		$('#details-frame').load(function(){
			// resize hight was here, but didn't change anything...
		}, function(){
			this.style.height = this.contentWindow.document.body.offsetHeight + 'px';
			var new_dest = $('#details-frame').offset().top;
			$('#details-frame').height( $('#details-frame body').height() );
			$('html,body').animate({
				scrollTop: new_dest
			}, 750, function(){
				$('#details-frame').parent().parent().removeClass('print_default_view').addClass('print_detail_only');
			});
		});
		
		// Resize the map to show the detail page more prominently
		$('#map_canvas').height(465);
		google.maps.event.trigger(gMap.map, 'resize');
		
		$(document).scrollTop($('.garage-detail').offset().top)
		
		// Update detail buttons link values (get photos)
		gMap.updateDetailButtons(id);
		
		// Show detail buttons overlayed on map
		div = $('.geoDetailBox-blank').clone(true).attr('class', 'geoDetailBox').show().get(0);
		
		if (gMap.detailBoxVisible == false) {
			gMap.map.controls[google.maps.ControlPosition.BOTTOM_CENTER].push(div);
			gMap.detailBoxVisible = true;
		}
		
		return false;
	}
};