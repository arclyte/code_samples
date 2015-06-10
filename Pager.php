<?php

/**
 * Bare bones pager class
 *
 * @author jalday
 */
class Pager
{
	public $pageCount = 1;
	public $itemCountPerPage = 10;
	public $first = 1;
	public $current = 1;
	public $last = 1;
	public $previous = 1;
	public $next = 1;
	public $pagesInRange;
	public $firstPageInRange;
	public $lastPageInRange;
	
	public function __construct($numTotalResults = null, $currentPage = 1, $resultsPerPage = 20, $pageRange = 5) {
		if ($numTotalResults < 1) return; // No results, no pages!
		if ($resultsPerPage < 1) $resultsPerPage = 1; // 0 just ain't allowed!
		
		$pageCount = (integer) ceil($numTotalResults / $resultsPerPage);
		$this->pageCount = $pageCount;
		if ($pageCount == 1) return; // no point in going on...
			
		$currentPage = (integer) $currentPage;
		$currentPage = $this->_normalizePage($currentPage, $pageCount);
		
		$this->itemCountPerPage = $resultsPerPage;
		$this->current = $currentPage;
		$this->last = $pageCount;
		
		// Previous Page
		if ($currentPage - 1 > 0) {
			$this->previous = $currentPage - 1;
		} else {
			$this->previous = 1;
		}
		
		// Next Page
		if ($currentPage + 1 <= $pageCount) {
			$this->next = $currentPage + 1;
		} else {
			$this->next = $pageCount;
		}
		
		// Page Range
		if ($pageRange > $pageCount) {
			$pageRange = $pageCount;
		}
		
		$delta = ceil($pageRange / 2);
		
		if ($currentPage - $delta > $pageCount - $pageRange) {
			$lowerBound = $pageCount - $pageRange + 1;
			$upperBound = $pageCount;
		} else {
			if ($currentPage - $delta < 0) {
				$delta = $currentPage;
			}
			
			$offset = $currentPage - $delta;
			$lowerBound = $offset + 1;
			$upperBound = $offset + $pageRange;
		}
		
		$lowerBound = $this->_normalizePage($lowerBound, $pageCount);
        $upperBound = $this->_normalizePage($upperBound, $pageCount);

        for ($i = $lowerBound; $i <= $upperBound; $i++) {
            $pagesInRange[$i] = $i;
        }

		$this->pagesInRange = $pagesInRange;
		$this->firstPageInRange = min($this->pagesInRange);
		$this->lastPageInRange = max($this->pagesInRange);
	}
	
	private function _normalizePage($pageNumber, $pageCount) {
		$pageNumber = (integer) $pageNumber;

        if ($pageNumber < 1) {
            $pageNumber = 1;
        }

        if ($pageCount > 0 && $pageNumber > $pageCount) {
            $pageNumber = $pageCount;
        }

        return $pageNumber;
	}
	
	public function displayPages($url = '') {
        $str = '';
		
		if ($this->pageCount > 1) {
			// add trailing slash to url if it's not there already
			if (substr($url, -1, 1) !== '/') $url .= '/';

			// Previous page
			$state = $this->current == 1 ? $state = " inactive" : "";
			$str .= '<a class="cta-prev'.$state.'" href="'.$url . $this->previous . '"><img src="/img/btn-pager-prev.jpg" alt="Previous Page" /></a>';

			// Pages listing
			foreach ($this->pagesInRange as $pageNumber) {
				if ($pageNumber == $this->current) {
					$str .= '<span class="current">'.$pageNumber.'</span>';
				} else {
					$str .= '<a href="' .$url . $pageNumber . '">' . $pageNumber . '</a>';
				}
			}

			// Next page
			$state = $this->current == $this->last ? " inactive" : "";
			$str .= '<a class="cta-next' . $state . '" href="' . $url . $this->next . ' "><img src="/img/btn-pager-next.jpg" alt="Next Page" /></a>';
		}
		
		return $str;
	}
}
