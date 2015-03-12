<?php namespace Mmanos\Search;

use Config;

class Search
{
	/**
	 * The name of the driver to used.
	 *
	 * @var string
	 */
	protected $driver = null;
	
	/**
	 * The array of index instances used by this instance.
	 *
	 * @var array
	 */
	protected $indexes = array();
	
	/**
	 * Create a new search instance.
	 *
	 * @param string $driver
	 * 
	 * @return void
	 */
	public function __construct($driver = null)
	{
		if (null === $driver) {
			$driver = Config::get('search.default', 'zend');
		}
		
		$this->driver = $driver;
	}
	
	/**
	 * Return the instance associated with the requested index name.
	 * Will create one if needed.
	 *
	 * @param string $index
	 * 
	 * @return \Mmanos\Search\Index
	 */
	public function index($index = null)
	{
		if (null === $index) {
			$index = Config::get('search.default_index', 'default');
		}
		
		if (!isset($this->indexes[$index])) {
			$this->indexes[$index] = Index::factory($index, $this->driver);
		}
		
		return $this->indexes[$index];
	}
	
	/**
	 * Provide convenient access to methods on the "default_index".
	 *
	 * @param string $method
	 * @param array  $parameters
	 * 
	 * @return mixed
	 */
	public function __call($method, array $parameters)
	{
		return call_user_func_array(array($this->index(), $method), $parameters);
	}
}
