<?php namespace Mmanos\Search;

use Config;

abstract class Index
{
	/**
	 * The name of the index associated with this instance.
	 *
	 * @var string
	 */
	protected $name;
	
	/**
	 * The name of this driver instance.
	 *
	 * @var string
	 */
	public $driver;
	
	/**
	 * Create a new search index instance.
	 *
	 * @param string $name
	 * @param string $driver
	 * 
	 * @return void
	 */
	public function __construct($name, $driver)
	{
		$this->name = $name;
		$this->driver = $driver;
	}
	
	/**
	 * Return an instance of the correct index driver for the
	 * given index name.
	 *
	 * @param string $index
	 * @param string $driver
	 * 
	 * @return \Mmanos\Search\Index
	 */
	public static function factory($index, $driver = null)
	{
		if (null === $driver) {
			$driver = Config::get('search.default', 'zend');
		}
		
		switch ($driver) {
			case 'algolia':
				return new Index\Algolia($index, 'algolia');
				
			case 'elasticsearch':
				return new Index\Elasticsearch($index, 'elasticsearch');
				
			case 'zend':
			default:
				return new Index\Zend($index, 'zend');
		}
	}
	
	/**
	 * Initialize and return a new Query instance on this index.
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function query()
	{
		return new Query($this);
	}
	
	/**
	 * Initialize and return a new Query instance on this index
	 * with the requested where condition.
	 *
	 * @param string $field
	 * @param mixed  $value
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function where($field, $value)
	{
		return $this->query()->where($field, $value);
	}
	
	/**
	 * Initialize and return a new Query instance on this index
	 * with the requested geo distance where clause.
	 *
	 * @param float $lat
	 * @param float $long
	 * @param int   $distance_in_meters
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function whereLocation($lat, $long, $distance_in_meters = 10000)
	{
		return $this->query()->whereLocation($lat, $long, $distance_in_meters);
	}
	
	/**
	 * Initialize and return a new Query instance on this index
	 * with the requested search condition.
	 *
	 * @param string $field
	 * @param mixed  $value
	 * @param array  $options - required   : requires a match (default)
	 *                        - prohibited : requires a non-match
	 *                        - phrase     : match the $value as a phrase
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function search($field, $value, array $options = array())
	{
		return $this->query()->search($field, $value, $options);
	}
	
	/**
	 * Initialize and return a new Query instance on this index
	 * with the requested select condition.
	 *
	 * @param array $columns
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function select($columns = array('*'))
	{
		return $this->query()->select(is_array($columns) ? $columns : func_get_args());
	}
	
	/**
	 * Create the index.
	 *
	 * @param array $fields
	 *
	 * @return bool
	 */
	abstract public function createIndex(array $fields = array());
	
	/**
	 * Get a new query instance from the driver.
	 *
	 * @return mixed
	 */
	abstract public function newQuery();
	
	/**
	 * Add a search/where clause to the given query based on the given condition.
	 * Return the given $query instance when finished.
	 *
	 * @param mixed $query
	 * @param array $condition - field      : name of the field
	 *                         - value      : value to match
	 *                         - required   : must match
	 *                         - prohibited : must not match
	 *                         - phrase     : match as a phrase
	 *                         - filter     : filter results on value
	 *                         - fuzzy      : fuzziness value (0 - 1)
	 * 
	 * @return mixed
	 */
	abstract public function addConditionToQuery($query, array $condition);
	
	/**
	 * Execute the given query and return the results.
	 * Return an array of records where each record is an array
	 * containing:
	 * - the record 'id'
	 * - all parameters stored in the index
	 * - an optional '_score' value
	 *
	 * @param mixed $query
	 * @param array $options - limit  : max # of records to return
	 *                       - offset : # of records to skip
	 * 
	 * @return array
	 */
	abstract public function runQuery($query, array $options = array());
	
	/**
	 * Execute the given query and return the total number of results.
	 *
	 * @param mixed $query
	 * 
	 * @return int
	 */
	abstract public function runCount($query);
	
	/**
	 * Add a new document to the index.
	 * Any existing document with the given $id should be deleted first.
	 * $fields should be indexed but not necessarily stored in the index.
	 * $parameters should be stored in the index but not necessarily indexed.
	 *
	 * @param mixed $id
	 * @param array $fields
	 * @param array $parameters
	 * 
	 * @return bool
	 */
	abstract public function insert($id, array $fields, array $parameters = array());
	
	/**
	 * Delete the document from the index associated with the given $id.
	 *
	 * @param mixed $id
	 * 
	 * @return bool
	 */
	abstract public function delete($id);
	
	/**
	 * Delete the entire index.
	 *
	 * @return bool
	 */
	abstract public function deleteIndex();
}
