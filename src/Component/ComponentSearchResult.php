<?php

/**
 * Created by PhpStorm.
 * User: keanor
 * Date: 29.07.15
 * Time: 13:12.
 */

namespace JiraRestApi\Component;

/**
 * Component search result.
 */
class ComponentSearchResult
{
	/**
	 * @var bool
	 */
	public $isLast;

	/**
	 * @var int
	 */
	public $startAt;

	/**
	 * @var int
	 */
	public $maxResults;

	/**
	 * @var int
	 */
	public $total;

	/**
	 * @var \JiraRestApi\Issue\Component[]
	 */
	public $values;

	/**
	 * @return int
	 */
	public function getStartAt()
	{
		return $this->startAt;
	}

	/**
	 * @param int $startAt
	 */
	public function setStartAt($startAt)
	{
		$this->startAt = $startAt;
	}

	/**
	 * @return int
	 */
	public function getMaxResults()
	{
		return $this->maxResults;
	}

	/**
	 * @param int $maxResults
	 */
	public function setMaxResults($maxResults)
	{
		$this->maxResults = $maxResults;
	}

	/**
	 * @return int
	 */
	public function getTotal()
	{
		return $this->total;
	}

	/**
	 * @param int $total
	 */
	public function setTotal($total)
	{
		$this->total = $total;
	}

	/**
	 * @return \JiraRestApi\Issue\Component[]
	 */
	public function getValues()
	{
		return $this->values;
	}

	/**
	 * @param \JiraRestApi\Issue\Component[] $values
	 */
	public function setValues($values)
	{
		$this->values = $values;
	}
}
