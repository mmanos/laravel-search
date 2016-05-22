<?php namespace Mmanos\Search;

use App, Input;
use Illuminate\Pagination\LengthAwarePaginator;

class Query
{
	/**
	 * The search index instance.
	 *
	 * @var \Mmanos\Search\Index
	 */
	protected $index;
	
	/**
	 * The raw query used by the current search index driver.
	 *
	 * @var mixed
	 */
	protected $query;
	
	/**
	 * The search conditions for the query.
	 *
	 * @var array
	 */
	protected $conditions = array();
	
	/**
	 * The columns that should be returned.
	 *
	 * @var array
	 */
	protected $columns;
	
	/**
	 * The maximum number of records to return.
	 *
	 * @var int
	 */
	protected $limit;
	
	/**
	 * The number of records to skip.
	 *
	 * @var int
	 */
	protected $offset;
	
	/**
	 * Any user defined callback functions to help manipulate the raw
	 * query instance.
	 *
	 * @var array
	 */
	protected $callbacks = array();
	
	/**
	 * Flag to remember if callbacks have already been executed.
	 * Prevents multiple executions.
	 *
	 * @var bool
	 */
	protected $callbacks_executed = false;
	
	/**
	 * Create a new search query builder instance.
	 *
	 * @param \Mmanos\Search\Index $index
	 * 
	 * @return void
	 */
	public function __construct($index)
	{
		$this->index = $index;
		$this->query = $this->index->newQuery();
	}
	
	/**
	 * Add a basic where clause to the query. A where clause filter attemtps
	 * to match the value you specify as an entire "phrase". It does not
	 * guarantee an exact match of the entire field value.
	 *
	 * @param string $field
	 * @param mixed  $value
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function where($field, $value)
	{
		$this->query = $this->index->addConditionToQuery($this->query, array(
			'field'    => $field,
			'value'    => $value,
			'required' => true,
			'filter'   => true,
		));
		
		return $this;
	}
	
	/**
	 * Add a geo distance where clause to the query.
	 *
	 * @param float $lat
	 * @param float $long
	 * @param int   $distance_in_meters
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function whereLocation($lat, $long, $distance_in_meters = 10000)
	{
		$this->query = $this->index->addConditionToQuery($this->query, array(
			'lat'      => $lat,
			'long'     => $long,
			'distance' => $distance_in_meters,
		));
		
		return $this;
	}
	
	/**
	 * Add a basic search clause to the query.
	 *
	 * @param string $field
	 * @param mixed  $value
	 * @param array  $options - required   : requires a match (default)
	 *                        - prohibited : requires a non-match
	 *                        - phrase     : match the $value as a phrase
	 *                        - fuzzy      : perform a fuzzy search (true, or numeric between 0-1)
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function search($field, $value, array $options = array())
	{
		$this->query = $this->index->addConditionToQuery($this->query, array(
			'field'      => $field,
			'value'      => $value,
			'required'   => array_get($options, 'required', true),
			'prohibited' => array_get($options, 'prohibited', false),
			'phrase'     => array_get($options, 'phrase', false),
			'fuzzy'      => array_get($options, 'fuzzy', null),
		));
		
		return $this;
	}
	
	/**
	 * Add a custom callback fn to be called just before the query is executed.
	 *
	 * @param Closure      $callback
	 * @param array|string $driver
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function addCallback($callback, $driver = null)
	{
		if (!empty($driver)) {
			if (is_array($driver)) {
				if (!in_array($this->index->driver, $driver)) {
					return $this;
				}
			}
			else if ($driver != $this->index->driver) {
				return $this;
			}
		}
		
		$this->callbacks[] = $callback;
		
		return $this;
	}
	
	/**
	 * Set the columns to be selected.
	 *
	 * @param array $columns
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function select($columns = array('*'))
	{
		$this->columns = is_array($columns) ? $columns : func_get_args();
		
		return $this;
	}
	
	/**
	 * Set the "limit" and "offset" value of the query.
	 *
	 * @param int $limit
	 * @param int $offset
	 * 
	 * @return \Mmanos\Search\Query
	 */
	public function limit($limit, $offset = 0)
	{
		$this->limit = $limit;
		$this->offset = $offset;
		
		return $this;
	}
	
	/**
	 * Execute the current query and perform delete operations on each
	 * document found.
	 *
	 * @return void
	 */
	public function delete()
	{
		$this->columns = null;
		$results = $this->get();
		
		foreach ($results as $result) {
			$this->index->delete(array_get($result, 'id'));
		}
	}
	
	/**
	 * Execute the current query and return a paginator for the results.
	 *
	 * @param int $num
	 * 
	 * @return \Illuminate\Pagination\LengthAwarePaginator
	 */
	public function paginate($num = 15)
	{
		$page = (int) Input::get('page', 1);
		
		$this->limit($num, ($page - 1) * $num);
		
		return new LengthAwarePaginator($this->get(), $this->count(), $num, $page);
	}
	
	/**
	 * Execute the current query and return the total number of results.
	 *
	 * @return int
	 */
	public function count()
	{
		$this->executeCallbacks();
		
		return $this->index->runCount($this->query);
	}
	
	/**
	 * Execute the current query and return the results.
	 *
	 * @return array
	 */
	public function get()
	{
		$options = array();

		if ($this->columns) {
			$options['columns'] = $this->columns;
		}
		
		if ($this->limit) {
			$options['limit'] = $this->limit;
			$options['offset'] = $this->offset;
		}
		
		$this->executeCallbacks();
		
		$results = $this->index->runQuery($this->query, $options);
		
		if ($this->columns && !in_array('*', $this->columns)) {
			$new_results = array();
			foreach ($results as $result) {
				$new_result = array();
				foreach ($this->columns as $field) {
					if (array_key_exists($field, $result)) {
						$new_result[$field] = $result[$field];
					}
				}
				$new_results[] = $new_result;
			}
			$results = $new_results;
		}
		
		return $results;
	}
	
	/**
	 * Execute any callback functions. Only execute once.
	 *
	 * @return void
	 */
	protected function executeCallbacks()
	{
		if ($this->callbacks_executed) {
			return;
		}
		
		$this->callbacks_executed = true;
		
		foreach ($this->callbacks as $callback) {
			if ($q = call_user_func($callback, $this->query)) {
				$this->query = $q;
			}
		}
	}
}
