<?php namespace Mmanos\Search\Index;

use Config;

class Algolia extends \Mmanos\Search\Index
{
	/**
	 * The Algolia client shared by all instances.
	 *
	 * @var \AlgoliaSearch\Client
	 */
	protected static $client;
	
	/**
	 * Index instance.
	 *
	 * @var \AlgoliaSearch\Index
	 */
	protected $index;
	
	/**
	 * An array of stored query totals to help reduce subsequent count calls.
	 *
	 * @var array
	 */
	protected $stored_query_totals = array();
	
	/**
	 * Get the Algolia client associated with this instance.
	 *
	 * @return \AlgoliaSearch\Client
	 */
	protected function getClient()
	{
		if (!static::$client) {
			static::$client = new \AlgoliaSearch\Client(
				Config::get('search.connections.algolia.config.application_id'),
				Config::get('search.connections.algolia.config.admin_api_key')
			);
		}
		
		return static::$client;
	}
	
	/**
	 * Create the index.
	 *
	 * @param array $fields
	 *
	 * @return bool
	 */
	public function createIndex(array $fields = array())
	{
		return false;
	}
	
	/**
	 * Get the Algolia index instance associated with this instance.
	 *
	 * @return \AlgoliaSearch\Index
	 */
	protected function getIndex()
	{
		if (!$this->index) {
			$this->index = $this->getClient()->initIndex($this->name);
		}
		
		return $this->index;
	}
	
	/**
	 * Get a new query instance from the driver.
	 *
	 * @return array
	 */
	public function newQuery()
	{
		return array(
			'terms' => '',
			'query' => array(
				'facets' => '*',
			),
		);
	}
	
	/**
	 * Add a search/where clause to the given query based on the given condition.
	 * Return the given $query instance when finished.
	 *
	 * @param array $query
	 * @param array $condition - field      : name of the field
	 *                         - value      : value to match
	 *                         - required   : must match
	 *                         - prohibited : must not match
	 *                         - phrase     : match as a phrase
	 *                         - filter     : filter results on value
	 *                         - fuzzy      : fuzziness value (0 - 1)
	 * 
	 * @return array
	 */
	public function addConditionToQuery($query, array $condition)
	{
		$value = trim(array_get($condition, 'value'));
		$field = array_get($condition, 'field');
		
		if ('xref_id' == $field) {
			$field = 'objectID';
		}
		
		if (array_get($condition, 'filter')) {
			if (is_numeric($value)) {
				$query['query']['numericFilters'][] = "{$field}={$value}";
			}
			else {
				$query['query']['facetFilters'][] = "{$field}:{$value}";
			}
		}
		else if (array_get($condition, 'lat')) {
			$query['query']['aroundLatLng'] = array_get($condition, 'lat') . ',' . array_get($condition, 'long');
			$query['query']['aroundRadius'] = array_get($condition, 'distance');
		}
		else {
			$query['terms'] .= ' ' . $value;
			
			if (!empty($field) && '*' !== $field) {
				$field = is_array($field) ? $field : array($field);
				$query['query']['restrictSearchableAttributes'] = implode(',', $field);
			}
		}
		
		return $query;
	}
	
	/**
	 * Execute the given query and return the results.
	 * Return an array of records where each record is an array
	 * containing:
	 * - the record 'id'
	 * - all parameters stored in the index
	 * - an optional '_score' value
	 *
	 * @param array $query
	 * @param array $options - limit  : max # of records to return
	 *                       - offset : # of records to skip
	 * 
	 * @return array
	 */
	public function runQuery($query, array $options = array())
	{
		$original_query = $query;
		
		if (isset($options['limit']) && isset($options['offset'])) {
			$query['query']['page'] = ($options['offset'] / $options['limit']);
			$query['query']['hitsPerPage'] = $options['limit'];
		}
		
		$query['terms'] = trim($query['terms']);
		if (isset($query['query']['numericFilters'])) {
			$query['query']['numericFilters'] = implode(',', $query['query']['numericFilters']);
		}
		
		try {
			$response = $this->getIndex()->search(array_get($query, 'terms'), array_get($query, 'query'));
			$this->stored_query_totals[md5(serialize($original_query))] = array_get($response, 'nbHits');
		} catch (\Exception $e) {
			$response = array();
		}
		
		$results = array();
		
		if (array_get($response, 'hits')) {
			foreach (array_get($response, 'hits') as $hit) {
				$hit['id'] = array_get($hit, 'objectID');
				$hit['_score'] = 1;
				$results[] = $hit;
			}
		}
		
		return $results;
	}
	
	/**
	 * Execute the given query and return the total number of results.
	 *
	 * @param array $query
	 * 
	 * @return int
	 */
	public function runCount($query)
	{
		if (isset($this->stored_query_totals[md5(serialize($query))])) {
			return $this->stored_query_totals[md5(serialize($query))];
		}
		
		$query['terms'] = trim($query['terms']);
		if (isset($query['numericFilters'])) {
			$query['numericFilters'] = implode(',', $query['numericFilters']);
		}
		if (isset($query['facets'])) {
			$query['facets'] = implode(',', $query['facets']);
		}
		
		try {
			return array_get($this->getIndex()->search(array_get($query, 'terms'), array_get($query, 'query')), 'nbHits');
		} catch (\Exception $e) {
			return 0;
		}
	}
	
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
	public function insert($id, array $fields, array $parameters = array())
	{
		$fields['objectID'] = $id;
		
		$this->getIndex()->saveObject(array_merge($parameters, $fields));
		
		return true;
	}
	
	/**
	 * Delete the document from the index associated with the given $id.
	 *
	 * @param mixed $id
	 * 
	 * @return bool
	 */
	public function delete($id)
	{
		try {
			$this->getIndex()->deleteObject($id);
		} catch (\Exception $e) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Delete the entire index.
	 *
	 * @return bool
	 */
	public function deleteIndex()
	{
		try {
			$this->getIndex()->clearIndex();
		} catch (\Exception $e) {
			return false;
		}
		
		return true;
	}
}
