<?php

/**
 * Created by PhpStorm.
 * User: keanor
 * Date: 14.08.15
 * Time: 20:53.
 */

namespace JiraRestApi;

/**
 * Class JiraException.
 */
class JiraException extends \Exception
{
	/**
	 * Create a new Jira exception instance.
	 */
	public function __construct(
		?string $message = null,
		int $code = 0,
		?\Throwable $previous = null,
		/**
		 * Response returned by Jira.
		 */ protected ?string $response = null,
	) {
		parent::__construct($message, $code, $previous);
	}

	/**
	 * Get error response.
	 */
	public function getResponse(): ?string
	{
		return $this->response;
	}
}
