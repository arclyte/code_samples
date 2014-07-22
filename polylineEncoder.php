<?php
/**
 *  Google Maps API Polygon Encoder
 *
 *  Code taken from the original by Jim Hribar at:
 *  http://facstaff.unca.edu/mcmcclur/GoogleMaps/EncodePolyline/PolylineEncoder.php.txt
 */

class encodePolygon {
	private $numLevels = 18; //number of map levels
	private $zoomFactor = 2;
	private $verySmall = 0.00001;
	private $forceEndpoints = true;
	private $zoomLevelBreaks = [];
	
	/**
	 * Creates zoomLevelBreaks array, used in compute_level
	 * @return unknown_type
	 */
	public function __construct($numLevels, $zoomFactor, $verySmall, $forceEndpoints) {
		$this->numLevels = $numLevels;
		$this->zoomFactor = $zoomFactor;
		$this->verySmall = $verySmall;
		$this->forceEndpoints = $forceEndpoints;

		for($i = 0; $i < $this->numLevels; $i++) {
			$this->zoomLevelBreaks[$i] = $this->verySmall * pow($this->zoomFactor, $this->numLevels - $i - 1);
		}
	}
	
	/**
	 * First creates an array of points based on Douglas-Peucker alogrithm for polyline simplification
	 * then encodes points and levels and returns an array of both
	 * 
	 * @param $points array Single ring array
	 * @return array
	 */
	public function encode_polygon($polygon) {
		$rings = count($polygon); // count of rings in polygon (in geometry class format)
		
		for ($r = 0; $r < $rings; $r++) {
			$points = $polygon[0]; // array of points for this ring
			
			if (count($points) > 2) {
				$stack[] = [0, count($points) - 1];
				
				//polyline simplification
				while (count($stack) > 0) {
					$current = array_pop($stack);
					$maxDist = 0;
					for ($i = $current[0] + 1; $i < $current[1]; $i++) {
						$temp = self::get_distance($points[$i], $points[$current[0]], $points[$current[1]]);
						if($temp > $maxDist) {
							$maxDist = $temp;
							$maxLoc = $i;
					
							if($maxDist > $absMaxDist) {
								$absMaxDist = $maxDist;
							}
						}
					}
					
					if($maxDist > $this->verySmall) {
						$dists[$maxLoc] = $maxDist;
						array_push($stack, [$current[0], $maxLoc]);
						array_push($stack, [$maxLoc, $current[1]]);
					}
				}
			}
		
			//Encode Points
			$encodedPolygon[$r]['points'] = self::create_encodings($points, $dists);
			//Encode Levels
			$encodedPolygon[$r]['levels'] = self::encode_levels($points, $dists, $absMaxDist);
		}
		
		return $encodedPolygon;
	}

	private function compute_level($dd) {
		if ($dd > $this->verySmall) {
			$lev = 0;
			while ($dd < $this->zoomLevelBreaks[$lev]) {
				$lev++;
			}
		}

		return $lev;
	}
	
	private function get_distance($p0, $p1, $p2) {
		if($p1[0] == $p2[0] && $p1[1] == $p2[1]) {
			$out = sqrt(pow($p2[0] - $p0[0], 2) + pow($p2[1] - $p0[1], 2));
		} else {
			$u = (($p0[0] - $p1[0]) * ($p2[0] - $p1[0]) + ($p0[1] - $p1[1]) * ($p2[1] - $p1[1])) / (pow($p2[0] - $p1[0], 2) + pow($p2[1] - $p1[1], 2));

			if($u <= 0) {
				$out = sqrt(pow($p0[0] - $p1[0],2) + pow($p0[1] - $p1[1],2));
			}
			if($u >= 1) {
				$out = sqrt(pow($p0[0] - $p2[0],2) + pow($p0[1] - $p2[1],2));
			}
			if(0 < $u && $u < 1) {
				$out = sqrt(pow($p0[0] - $p1[0] - $u * ($p2[0] - $p1[0]), 2) + pow($p0[1] - $p1[1] - $u * ($p2[1] - $p1[1]), 2));
			}
		}

		return $out;
	}
	
	private function encode_signed_number($num) {
	   $sgn_num = $num << 1;
	   if ($num < 0) {
		   $sgn_num = ~($sgn_num);
	   }

	   return self::encode_number($sgn_num);
	}
	
	private function create_encodings($points, $dists) {
		for ($i=0, $num_points = count($points); $i < $num_points; $i++) {
			if (isset($dists[$i]) || $i == 0 || $i == count($points) - 1) {
				$point = $points[$i];
				$lat = $point[0];
				$lng = $point[1];
				$late5 = floor($lat * 1e5);
				$lnge5 = floor($lng * 1e5);
				$dlat = $late5 - $plat;
				$dlng = $lnge5 - $plng;
				$plat = $late5;
				$plng = $lnge5;
				$encoded_points .= self::encode_signed_number($dlat) . self::encode_signed_number($dlng);
			}
		}
		
		return $encoded_points;
	}
	
	private function encode_levels($points, $dists, $absMaxDist) {
		if ($this->forceEndpoints) {
			$encoded_levels .= self::encode_number($this->numLevels - 1);
		} else {
			$encoded_levels .= self::encode_number($this->numLevels-self::compute_level($absMaxDist) - 1);
		}
		
		for ($i=1, $num_points = count($points)-1; $i < $num_points; $i++) {
			if (isset($dists[$i])) {
				$encoded_levels .= self::encode_number($this->numLevels-self::compute_level($dists[$i]) - 1);
			}
		}
		
		if ($this->forceEndpoints) {
			$encoded_levels .= self::encode_number($this->numLevels - 1);
		} else {
			$encoded_levels .= self::encode_number($this->numLevels-self::compute_level($absMaxDist) - 1);
		}
		
		return $encoded_levels;
	}
	
	private function encode_number($num) {
		while ($num >= 0x20) {
			$nextValue = (0x20 | ($num & 0x1f)) + 63;
			$encodeString .= chr($nextValue);
			$num >>= 5;
		}

		$finalValue = $num + 63;
		$encodeString .= chr($finalValue);
		
		return $encodeString;
	}
}
?>