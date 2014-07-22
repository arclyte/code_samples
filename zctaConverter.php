<?php
/**
 * @name: zcta_conversion.php
 * @abstract: Converts cartographic boundary files for 5-Digit ZIP Code Tabulation Areas (ZCTA)
 * from the U.S. Census Bureau into a table format similar to DELIVERY_MAP_POINTS
 * Data source: http://www.census.gov/geo/www/cob/z52000.html
 * @author: James Alday
 * @since: 11/03/08
 */

/**
 * Zip Code Tabulation Area (ZCTA):
 * 	A statistical geographic entity that approximates the U.S. Postal Service delivery area for 5-digit zip codes.
 *  These are used for census data and are not precise and do not contain all zip codes used for mail delivery.
 *  The data used at the time of this writing are from the 2000 Census.
 *
 * ZCTA File naming conventions:
 * 	zt##_d##(a).dat
 *  The numbers after zt are for the state, numbered 01-72
 *  The numbers after d are the two digit year, always 00 for this run
 *  The "a" marks the attribute file, the file name without the "a" is the polygon file.
 *
 * The ZCTA files are split into two:
 *  - Polygon Attribute File - contains zip codes associated to the polygons, grouped by ID
 * 	  The format for the attribute file is:
 * 		1 ID
 * 		2 FIPS Code(s) (zip codes)
 * 		3 NAME
 * 		4 LSAD
 * 		5 LSAD Translation
 *
 * 		example data:
 * 		 1766
 *		 "10511"
 *		 "10511"
 *		 "Z5"
 *		 "5-Digit ZCTA"
 *
 * 	  For our purposes, we only need the ID and FIPS from this file.
 *
 *  - Polygon Coordinate File - contains LON/LAT coordinates for the polygon of the zip
 * 	  The format for the polygon file is:
 * 		ID LON1	LAT1
 * 		   LON2 LAT2
 * 		   ...	...
 * 		   LONx LATx
 * 		END
 * 		ID LON1 LAT1
 * 		   ...	...
 * 		END
 * 		END
 *
 * 	  - The first line, with the ID, is the center of the polygon and can be skipped
 *    - Lines with the ID of -9999 are modifiers or exclusions from the previous ID, not sure what we're doing with these just yet...
 *    - LON/LAT are in decimal degrees, LON -180 to 180, LAT -90 to 90
 * 	  - Origin is the intersect of the Greenwich Prime Meridian and the Equator
 * 	  - Signs determine the quadrant:
 * 			NE = + LON / + LAT
 * 			NW = - LON / + LAT
 * 			SE = + LON / - LAT
 * 			SW = - LON / - LAT
 */

ini_set('memory_limit', -1);

require_once('geometry.php');
require_once('polylineEncoder.php');

class zctaConverter
{
	public $dir;
	public $year;
	public $ext;
	
	public $zip_id_map = [];

	public $poly_count = [
		'total' => 0,
		'good' => 0,
		'bad' => 0,
	];

	private $errors;
	private $file_prefix;
	private $poly_id;
	private $polygon = [];

	public function __construct($dir = '/home/zips/files', $year = '00', $ext = '.dat') {
		$this->dir = $dir;
		$this->year = $year;
		$this->ext = $ext;

		$this->line_number = 1;
		$this->ring = 0;

		$this->Geometry = new geometry();
		$this->Encoder = new encodePolygon();

		// Start processing files
		$this->main();
	}

	private function main() {
		for ($st = 0; $st < 73; $st++) {
			$st = str_pad($st, 2, "0", STR_PAD_LEFT); // left pad numbers less than 10 with a zero

			$this->file_prefix = 'zt' . $st . '_d' . $this->year;

			if (file_exists($this->dir . $this->file_prefix . $this->ext)) {
				$this->process_attributes();
				$this->process_zips();
			}
			
		}
	}

	/**
	 * Processes the Polygon Attribute File in order to match Zip Codes to their ID numbers
	 */
	private function process_attributes() {
		$paf = file_get_contents($this->dir . $this->file_prefix . 'a' . $this->ext);

		// Match ID and ZIP lines
		preg_match_all('!(\d+)\s+"(\d{5})"!', $paf, $zips);

		// Assemble matches into a single array with ID and ZIP
		foreach ($zips[1] as $key => $id) {
			if (preg_match('!\d{5}!', $zips[2][$key])) {
				$this->zip_id_map[$id] = $zips[2][$key];
			}
		}
		unset($zips); // free up memory
	}

	/**
	 * Once we have all of the Zips matched to their IDs we can process the Polygon Coordinate File
	 * and match Zips to polygons via their unique IDs
	 */
	private function process_zips() {
		// These are much larger files, so we go line by line
		$fp = fopen($this->dir . $this->file_prefix . $this->ext, "r");

		if ($fp) {
			$this->poly_id = 0;

			while (!feof($fp)) {
				$line = fgets($fp);
				// space delimited file, split by spaces
				$line_array = preg_split('!\s+!', $line, -1, PREG_SPLIT_NO_EMPTY);
				// the number of elements found determines the type of line we've found
				$line_count = count($line_array);

				switch($line_count) {
					case 3:
						// First Line of polygon - ID and center point
						if ($poly_id > 0) {
							$this->process_polygon();
						}

						// reset variables
						$this->polygon = [];
						$this->poly_id = $line_array[0];
						$this->poly_center[0] = (float) $line_array[1]; // x coord
						$this->poly_center[1] = (float) $line_array[2]; // y coord
						$this->ring = 0;

						$this->poly_count['total']++;

						break;

					case 2:
						// Poly ID must be set to process this polygon
						if ($poly_id > 0) {
							// check that we have a valid zip code
							if (strlen($this->zip_id_map[$poly_id]) === 5) {
								$points = [
									(float) $line_array[1], // Polygon Latitude point
									(float) $line_array[0], // Polygon Longitude point
								];

								// check if we already have these points
								$key = false;

								if ($this->polygon[$this->ring]) {
									$key = array_search($points, $this->polgyon[$this->ring]);
								}

								// only add new point if it's not a duplicate
								if (empty($key)) {
									$this->polygon[$this->ring][] = $points;
								}
							} else {
								$this->poly_id = 0;
								$this->poly_count['bad']++;
							}
						}

						break;

					case 1:
						// Make sure this is an END line before processing
						if ($line_array[0] !== "END" && $line_array[0] !== "-99999") {
							$this->errors[$this->file_prefix][$this->poly_id] = [
								"error" => $this->line_number . " is not an END line.",
								"line_array" => $line_array,
								"line" => $line,
							];
						} else {
							if ($line_array[0] == "-99999") {
								$this->ring++;
							}
						}

						break;

					case 0:
						// do nothing
						break;

					default:
						$this->errors[$this->file_prefix][$this->poly_id] = [
							"error" => "Line Count Error on line " . $this->line_number,
							"line_array" => $line_array,
							"line" => $line,
						];

						break;
				}

				$this->line_number++;
			}

			// Process final polygon in the file
			if ($this->poly_id > 0) {
				$this->process_polygon();
			}
		}
	}

	private function process_polygon() {
		// if we have an ID already set, this is a new polygon and we have a previous polygon to process
		if (empty($this->polygon[0])) {
			$this->errors[$this->file_prefix][$this->poly_id] = [
				"error" => "Bad Polygon",
				"polygon" => $this->polygon,
			];
		} else {
			// convert to SQL insertable polygon string
			$poly_sql = $this->Geometry->convert_array_to_poly($this->polygon);
			// encode polygon
			$polyencoded = $this->Encoder->encode_polygon($this->polygon);
			//Format centroid point for mysql insert
			$zip_center = "(PointFromText('POINT(".$this->poly_center[1].' '.$this->poly_center[0].")'))";

			// Validate that this is a valid polygon using MySQL GIS tools
			// XXX: Without the DB lib, consider this psuedo-code to be replaced with whatever DB/ORM tools you're using
			$sql = "SELECT " . $poly_sql;
			$result = $db->query($sql);

			if (empty($result)) {
				$this->poly_count['bad']++;
				$this->errors[$this->file_prefix][$this->poly_id] = [
					"error" => $result,
					"polygon" => $this->polygon,
				];
			} else {
				// XXX: Insert into DB?
				$this->poly_count['good']++;
			}
		}
	}
}