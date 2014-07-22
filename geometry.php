<?php
/**
 * Geometry Class
 *
 * This class is used in conjunction with the OpenGIS data types found in MySQL
 * It contains functions to be utilized by any code dealing with geometry data types
 * and functions to compare geometries.
 *
 * @author James Alday
 *
 */

class geometry
{
	/**
	 * Convert an array of points into a format acceptable by MySQL
	 * Input array in format is the same as the output format for convert_poly_to_array
	 *
	 * @param $poly_array Polygon Array (see convert_poly_to_array)
	 * @return string - MySQL-ready Polygon string, or false on failure
	 */
	protected function convert_array_to_poly($poly_array) {
		if (count($poly_array)) {
			// each element in this level is a separate polygon
			foreach($poly_array as $polygon) {
				// make sure path is closed
				$polyLen = count($polygon);
				if ($polygon[0] !== $polygon[$polyLen - 1]) {
					$polygon[] = $polygon[0];
				}
				
				// each element is a pair of x,y vertices
				foreach ($polygon as $point_array) {
					//final format has a space btw vertices
					$point_pairs[] = implode(' ', $point_array);
				}
				// put commas between pairs, enclose in parentheses
				$points_array[] = '(' . implode(',', $point_pairs) . ')';
				unset($point_pairs);
			}
			// parenth enclosed polys separated by commas
			$polygon = implode(',', $points_array);
	
			// return code ready to be used by MySQL
			return "(PolyFromText('POLYGON(" . $polygon . ")'))";
		} else {
			return false;
		}
	}

	/**
	 * Convert MySQL AsText(POLYGON) data type into an array
	 * Works for simple polygons as well as those containing interior rings
	 *
	 * @param $poly string Raw Mysql AsText string
	 * @return array Format [0 => [0 => [0 => x, 1 => y]]] (shape => point => x,y coords)
	 */
	protected function convert_poly_to_array($poly) {
		$ring_num = 0;
		$poly_array = array();
		$poly = substr($poly, 9, -2); //strip 'Polygon((' and ending '))'
		//break polygon into separate rings
		foreach (explode('),(', $poly) as $index => $rings) {
			foreach (explode(',', $rings) as $index => $point_set) {
				$point_set = explode(' ', $point_set);
				$poly_array[$ring_num][] = $point_set;
			}
			$ring_num++;
		}
		return $poly_array;
	}

	/**
	 * Find the area of a given polygon, sign determines direction
	 *
	 * @param $polygon array Polygon vertices
	 * @return float Area of polygon
	 */
	protected function get_area($polygon) {
		//count how many rings we have in the polygon
   		$rings = count($polygon);

   		for($r = 0; $r < $rings; $r++) {
			$ring_area = 0;

   			// determine the area for the given ring
			$n = count($polygon[$r]);

			for ($i = 0; $i < $n; $i++) {
	      		$j = ($i + 1) % $n;
	      		$ring_area += $polygon[$r][$i][0] * $polygon[$r][$j][1];
	      		$ring_area -= $polygon[$r][$i][1] * $polygon[$r][$j][0];
			}

	   		$ring_area /= 2;

	   		if ($r == 0) {
	   			$area = $ring_area;
	   			$ring_e_dir = ($ring_area == abs($ring_area)) ? '+' : '-'; // direction of exterior ring
	   		} else {
	   			$ring_i_dir = ($ring_area == abs($ring_area)) ? '+' : '-'; // direction of interior ring
	   			
	   			if ($ring_e_dir == $ring_i_dir) {
	   				$area -= $ring_area;
	   			} else {
	   				$area += $ring_area;
	   			}
	   		}
   		}

   		return $area;
	}

	/**
	 * Gets the distance in MI/KM between two points given as arrays
	 * @param $start array [0 => x, 1 => y]
	 * @param $end array [0 => x, 1 => y]
	 * @param $metric string - KM is default, or MI for miles
	 * @return float Distance between $start and $end in given metric
	 */
	protected function get_distance($start, $end, $metric = "KM") {
		// Define Earth's radius in miles or kilometers
		// This will determine the distance returned
		$erad = array(
			"KM" => 6371, //Kilometers
			"MI" => 3959, //Miles
		);
		
		return $erad[$metric] * 2 * ASIN(SQRT(POW(SIN(($start[0] - $end[0]) * M_PI / 180 / 2), 2) + COS($start[0] * M_PI / 180) * COS($end[0] * M_PI / 180) * POW(SIN(($start[1] - $end[1]) * M_PI / 180 / 2), 2)));
	}

	/**
	 * Creates a circular polygon array of n nodes given a center point and radius
	 *
	 * @param $center array Center point of the circle to draw
	 * @param $radius float Radius, usually given in miles
	 * @param $nodes int Number of points to return, 36 by default
	 * @param $metric string - KM is default, or MI for miles
	 * @return array $circle Coordinate pairs for the circle
	 */
	protected function get_circle($center, $radius, $nodes = 36, $metric = "KM") {
			if ($metric === "MI") {
				// algos here deal in KM, so convert from miles if necessary
				$radius = $radius * 1.609344;
			}
			
	        //calculate km/degree at decimal degree 0 (111km/degree at equator)
	        $latConv = self::get_distance($center, array($center[0] + 1, $center[1]));
	        $lngConv = self::get_distance($center, array($center[0], $center[1] + 1));
	        
	        $step = 360 / $nodes; //specify # of degrees btw each node

	        for($i = 0; $i <= 360; $i += $step) {
	        		//add point to circle array
	                $circle[] = array(
	                	($center[0] + ($radius / $latConv * cos($i * M_PI / 180))),
	                	($center[1] + ($radius / $lngConv * sin($i * M_PI / 180)))
	                );
	        }
	        
	        //if $nodes is a divisor of 360 the polygon will be closed
	        //if it is not, we close it here to make sure we have a valid polygon
	        if ($circle[0] != $circle[count($circle) - 1]) {
	        	$circle[] = $circle[0];
	        }
	
	        //return in standard polygon format
	        return array(0=>$circle);
	}

	/**
	 * Compares two polygons to test if any line segments intersect
	 * Based on the algorithm by Paul Bourke, "Intersection point of two lines in 2 dimensions":
	 * http://paulbourke.net/geometry/pointlineplane/
	 *
	 * Perturb mode - on first run, check if any points in either polygon
	 * are on a line in the other polygon (point=intersect).  If so, move
	 * that point ever so slightly off the line (7th precision point, since
	 * only 6 points of precision are really needed)
	 *
	 * @param $poly1 array Vertices for first polygon
	 * @param $poly2 - array Vertices for second polygon
	 * @return array  [0 => intersect points, 1 => $poly1 with intersect points]
	 */
	protected function get_intersect(&$poly1, &$poly2, $perturb_mode = true, $swapped = false) {
		$all_intersect = array();

		$cp1 = count($poly1) - 1;
		$cp2 = count($poly2) - 1;

		// loop over each line segment in poly1 once
		for ($x = 0; $x < $cp1; $x++) {
			$p1 = $poly1[$x]; //Line 1 Start - Array(x,y)
			$p2 = $poly1[$x + 1]; //Line 1 End

			// loop over each line segment in poly2 (for each poly1 segment)
			for($y = 0; $y < $cp2; $y++) {
				$p3 = $poly2[$y]; //Line 2 Start
				$p4 = $poly2[$y + 1]; //Line 2 End
				
				// calculate differences
				$xD1 = $p2[0] - $poly1[$x][0]; // diff x Ln 1
				$xD2 = $p4[0] - $poly2[$y][0]; // diff x Ln 2
				$yD1 = $p2[1] - $poly1[$x][1]; // diff y Ln 1
				$yD2 = $p4[1] - $poly2[$y][1]; // diff y Ln 2

				$div = round($yD2 * $xD1 - $xD2 * $yD1, 12);  // divisor
				if ($div == 0) continue; // parallel lines, prevent divide by zero

				$xD3 = $poly1[$x][0] - $poly2[$y][0]; // diff x Ln 1 - Ln 2
				$yD3 = $poly1[$x][1] - $poly2[$y][1]; // diff y Ln 1 - Ln 2

				$ua = round((($xD2 * $yD3) - ($yD2 * $xD3)),12) / $div; // math stuff
				
				// if ua or ub is between 0 and 1, the point lies on that line
				// so we check to see if the point is on both lines and return it
				if ($ua >= 0 && $ua <= 1) {
					$ub = round((($xD1 * $yD3) - ($yD1 * $xD3)),12) / $div; // more math stuff

					if ($ub >= 0 && $ub <= 1) {
						$intersect = array();
						$intersect[0] = round($poly1[$x][0] + ($ua * $xD1), 12); // x of intersect
						$intersect[1] = round($poly1[$x][1] + ($ua * $yD1), 12); // y of intersect

						// check if we have a point in poly1 equal to the intersect
						if ($perturb_mode === TRUE) {
							if (($key = array_search($intersect, $poly2)) !== false) {
								// found a match - perturb line very slightly
								$poly2[$key][0] += .0000000005;
								$poly2[$key][1] += .0000000005;

								// if the last point, change the first point too
								if ($y == count($poly2) - 1) {
									$poly2[0] = $poly2[$y];
								} elseif ($y === 0) {
									// if the first point, change the last point too
									$poly2[count($poly2) - 1] = $poly2[$y];
								}
							}
						} else {
							// inject intersect into new poly2
							if (!in_array($intersect, $poly2)) {
								array_splice($poly2, ($y + 1), 0, array($intersect));
								$all_intersect[] = $intersect;
								$y++;
								$cp2++;
							}
						}
					}
				}
			}
		}

		// rerun this function with the perturbed polygons
		if ($perturb_mode === TRUE) {
			return $this->get_intersect($poly1, $poly2, FALSE);
		}

		return $swapped === false ?
			$this->get_intersect($poly2, $poly1, false, true) :
			array($all_intersect, $poly2, $poly1);
	}

	/**
	 * Get overlap area of poly1 and poly2
	 *
	 * @param $poly1
	 * @param $poly2
	 * @param $intersections
	 * @param $poly2_exclusions
	 * @return float
	 */
	protected function get_overlap_area($poly1, $poly2, $intersections, $poly2_exclusions) {
		$area = 0;

		$this->poly1 = $poly1;
		$this->poly2 = $poly2;
		$this->intersections = $intersections;

		$overlap_polys = $this->get_overlap_polys();

		foreach($overlap_polys as $poly) {
			$area += abs($this->get_area($poly));
		}

	 	if ($area == 0) {
			// check to see if ANY ONE point of poly1 is in_range of poly2
			// if so, poly1 is fully inside of poly2
			foreach ($poly1 as $pt) {
	 			if ($this->in_range($poly2, $pt[0], $pt[1])) {
	 				$area = abs($this->get_area($poly1));
	 				break;
	 			}
			}

	 		// check to see if ANY ONE point of poly2 is in_range of poly1
			// if so, poly2 is fully inside of poly1
	 		foreach ($poly2 as $pt) {
	 			if ($this->in_range($poly1, $pt[0], $pt[1])) {
	 				$area = abs($this->get_area($poly2));
	 				break;
	 			}
			}
	 	}

	 	// if the overlap polygon(s) have an area and there are exclusions, remove the area of the overlap
		// of poly2_exclusions with the overlap polygon(s) from the overall area
		if ($area > 0 && count($poly2_exclusions)) {
		  //get overlap area of overlap_polys and poly2_exclusions by calling ourself
		  //recursively and subtract from overall area
		  $area -= $this->get_overlap_area($overlap_polys, $poly2_exclusions);
		}

		return $area;
	}

	private function get_overlap_polys() {
		$tmp_intersections = $this->intersections; // temporary array of intersect points
		$intersection_polygons = array(); // our final overlap polygons

		while($start_point = array_shift($tmp_intersections)) {
			$poly = $this->poly1; // we start on poly1
			$point = array_search($start_point, $poly);
			$tmp_new_poly = array(); // temp overlap polygon

			do {
				// push point into temp overlap polygon
				$tmp_new_poly[] = $poly[$point];
				// look for the point in the master array of intersections
				$which_intersect = in_array($poly[$point], $this->intersections);

				// we hit an intersection point
				if ($poly[$point] != $start_point && $which_intersect !== false) {
					$old_point = $poly[$point];

					// switch to other poly
					$poly = $poly === $this->poly1 ? $this->poly2 : $this->poly1;

					// set the current pointer to the which_intersect point in the new/other polygon
					$point = array_search($old_point, $poly);
				}

				//find the other poly
				$other_poly = $poly === $this->poly1 ? $this->poly2 : $this->poly1;

				// if it is NOT in range of the OTHER poly
				if ($which_intersect === false && !self::in_range($other_poly, $poly[$point][0], $poly[$point][1])) {
		     		unset($tmp_new_poly);
		        	continue 2;
				}

				// move to next point on whatever poly we are on
				$point = $point == count($poly) - 1 ? 1 : $point + 1 ;// next point in $poly
			}

		    while($poly[$point] != $start_point);

		    foreach ($tmp_new_poly as $new_point_set) {
				if (($key = array_search($new_point_set, $tmp_intersections)) !== false) {
					// remove this intersect from tmp_intersections
					unset($tmp_intersections[$key]);
				}
			}

			// push start_point to close polygon
			$tmp_new_poly[] = $start_point;

			// closed polygon, save in intersect polygon array
			$intersection_polygons[] = $tmp_new_poly;
		}

		return $intersection_polygons;
	}

	// get overlap polygon
	protected function get_overlap_polys_array($polys) {
		list(
			$this->intersections,
			$this->poly1,
			$this->poly2
		) = $polys;

		return $this->get_overlap_polys();
	}

	/**
	 * Find the center point for a given polygon - exterior ring only
	 * @param $polygon array Vertices for polygon
	 * @return array Long/Lat center point (x,y)
	 */
	protected static function get_polygon_centroid($polygon) {
		$polygon = $polygon[0]; // take only exterior ring
		$A = self::get_area([0 => $polygon]); // reformat array for area (which is interior aware)
		$poly_count = count($polygon);
		$factor = 0;
		$cx = 0;
		$cy = 0;
		$res = array();

		for ($i = 0; $i < $poly_count; $i++) {
			$j = ($i + 1) % $poly_count;
			$factor = ($polygon[$i][0] * $polygon[$j][1] - $polygon[$j][0] * $polygon[$i][1]);
			$cx += ($polygon[$i][0] + $polygon[$j][0]) * $factor;
			$cy += ($polygon[$i][1] + $polygon[$j][1]) * $factor;
		}

		$A *= 6.0;
		$factor = 1 / $A;
		$cx *= $factor;
		$cy *= $factor;
		$res[0] = $cx;
		$res[1] = $cy;

		return $res;
	}

	/**
	 * Tests if a given point ($x, $y) is within a given polygon, tests against inner rings
	 *
	 * @param $polygon_array array Polygon to test point against (in convert_array_to_poly() format)
	 * @param $x float
	 * @param $y float
	 * @return bool
	 */
	protected static function in_range($polygon_array, $x, $y) {
		$in = false;
		$out = false;
		$where = 'out';

		foreach ($polygon_array as $polygon) {
			$j = ($npol = count($polygon) - 1);

			for($i = 0; $i <= $npol; $i++) {
				if ((($polygon[$i][1] > $y) XOR ($polygon[$j][1] > $y)) &&
				($x < ($polygon[$j][0] - $polygon[$i][0]) * ($y - $polygon[$i][1]) / ($polygon[$j][1] - $polygon[$i][1]) + $polygon[$i][0])) {
					${$where} = !(${$where});
				}
				$j = $i;
			}

			if (!$out || $in) {
				return false;
			}

			$where = 'in';
		}

		return true;
	}

	/**
	 * Convert degrees to radians - should be quicker in loops than deg2rad()
	 *
	 * @param $value float Degrees
	 * @return float Radians
	 */
	protected static function degrees_to_radians($value) {
		return ($value * (M_PI / 180));
	}
}