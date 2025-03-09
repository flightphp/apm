<?php

declare(strict_types=1);

namespace flight\apm;

/**
 * CustomEvent class for Application Performance Monitoring
 * 
 * This class allows tracking custom events with arbitrary data
 * for performance and behavior analysis.
 */
class CustomEvent
{
	/**
	 * The type/identifier of the event
	 * 
	 * @var string
	 */
	public string $type;
	
	/**
	 * Arbitrary data associated with the event
	 * 
	 * @var array
	 */
	public array $data;

	/**
	 * Creates a new custom event instance
	 * 
	 * @param string $type The type/identifier of the event
	 * @param array $data Optional data associated with the event
	 */
	public function __construct(string $type, array $data = [])
	{
		$this->type = $type;
		$this->data = $data;
	}
}