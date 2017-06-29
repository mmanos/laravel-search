<?php namespace Mmanos\Search\Index;

use Config;

class Elasticsearch extends \Mmanos\Search\Index
{
	/**
	 * The value to use as the default type for documents.
	 *
	 * @var string
	 */
	public static $default_type = 'default';
	
	/**
	 * The Elasticsearch client shared by all instances.
	 *
	 * @var \Elasticsearch\Client
	 */
	protected static $client;
	
	/**
	 * An array of stored query totals to help reduce subsequent count calls.
	 *
	 * @var array
	 */
	protected $stored_query_totals = array();
	
	/**
	 * Get the Elasticsearch client associated with this instance.
	 *
	 * @return \Elasticsearch\Client
	 */
	protected function getClient()
	{
		if (!static::$client) {
			static::$client = new \Elasticsearch\Client(
				Config::get('search.connections.elasticsearch.config', array())
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
		$properties = array('_geoloc' => array('type' => 'geo_point'));
		
		foreach ($fields as $field) {
			$properties[$field] = array('type' => 'string');
		}
		
		$body['mappings'][static::$default_type]['properties'] = $properties;
		
		$this->getClient()->indices()->create(array(
			'index' => $this->name,
			'body'  => $body,
		));
		
		return true;
	}
	
	/**
	 * Get a new query instance from the driver.
	 *
	 * @return array
	 */
	public function newQuery()
	{
		return array(
			'index' => $this->name,
			'body'  => array('query' => array()),
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
		$field = array_get($condition, 'field', '_all');
		
		if ($field && 'xref_id' == $field) {
			$query['id'] = $value;
			return $query;
		}
		
		if (empty($field) || '*' === $field) {
			$field = '_all';
		}
		$field = (array) $field;
		
		$occur = empty($condition['required']) ? 'should' : 'must';
		$occur = empty($condition['prohibited']) ? $occur : 'must_not';
		
		if (isset($condition['fuzzy']) && false !== $condition['fuzzy']) {
			$fuzziness = .5;
			if (is_numeric($condition['fuzzy'])
				&& $condition['fuzzy'] >= 0
				&& $condition['fuzzy'] <= 1
			) {
				$fuzziness = $condition['fuzzy'];
			}
			$match_type = 'multi_match';
			$definition = array(
				'query'         => $value,
				'fields'        => $field,
				'prefix_length' => 2,
				'fuzziness'     => $fuzziness,
			);
		}
		elseif (array_get($condition, 'lat')) {
			$definition = array(
				'distance' => $condition['distance'].'m',
				'_geoloc' => array(
					'lat' => $condition['lat'],
					'lon' => $condition['long'],
				),
			);

			$query['body']['query']['filtered']['filter']['geo_distance'] = $definition;

			return $query;
		}
		else {
			$is_phrase = (!empty($condition['phrase']) || !empty($condition['filter']));
			$match_type = 'multi_match';
			$definition = array(
				'query'  => $value,
				'fields' => $field,
				'type'   => $is_phrase ? 'phrase' : 'best_fields',
			);
		}
		
		$query['body']['query']['filtered']['query']['bool'][$occur][][$match_type] = $definition;
		
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

		if (isset($options['columns']) && !in_array('*', $options['columns'])) {
			$query['_source'] = $options['columns'];
		}
		
		if (isset($options['limit']) && isset($options['offset'])) {
			$query['from'] = $options['offset'];
			$query['size'] = $options['limit'];
		}
		
		if (isset($query['id'])) {
			try {
				$response = $this->getClient()->get(array(
					'index' => array_get($query, 'index'),
					'type'  => static::$default_type,
					'id'    => array_get($query, 'id'),
				));
			} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
				$response = array();
			}
			
			if (empty($response)) {
				$this->stored_query_totals[md5(serialize($original_query))] = 0;
				return array();
			}
			
			$this->stored_query_totals[md5(serialize($original_query))] = 1;
			
			$parameters = array_get($response, '_source._parameters');
			
			if (!empty($parameters)) {
				$parameters = json_decode(base64_decode($parameters), true);
			}
			else {
				$parameters = array();
			}
			
			return array(array_merge(
				array(
					'id' => array_get($response, '_id'),
				),
				$parameters
			));
		}
		
		try {
			$response = $this->getClient()->search($query);
			$this->stored_query_totals[md5(serialize($original_query))] = array_get($response, 'hits.total');
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			$response = array();
		}
		
		$results = array();
		
		if (array_get($response, 'hits.hits')) {
			foreach (array_get($response, 'hits.hits') as $hit) {
				$fields = array(
					'id'     => array_get($hit, '_id'),
					'_score' => array_get($hit, '_score'),
				);
				$source = array_get($hit, '_source', array());

				foreach ($source as $name => $value) {
					$fields[$name] = $value;
				}

				$parameters = array_get($hit, '_source._parameters');
				
				if (!empty($parameters)) {
					$parameters = json_decode(base64_decode($parameters), true);
				}
				else {
					$parameters = array();
				}
				
				$results[] = array_merge($fields, $parameters);
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
		
		try {
			return array_get($this->getClient()->search($query), 'hits.total');
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
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
		try {
			$this->delete($id);
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {}
		
		if (!empty($parameters)) {
			$fields['_parameters'] = base64_encode(json_encode($parameters));
		}
		
		$this->getClient()->index(array(
			'index' => $this->name,
			'type'  => static::$default_type,
			'id'    => $id,
			'body'  => $fields,
		));
		
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
			$this->getClient()->get(array(
				'index' => $this->name,
				'type'  => static::$default_type,
				'id'    => $id,
			));
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			return false;
		}
		
		$doc = $this->getClient()->delete(array(
			'index' => $this->name,
			'type'  => static::$default_type,
			'id'    => $id,
		));
		
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
			$this->getClient()->indices()->delete(array(
				'index' => $this->name,
			));
		} catch (\Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			return false;
		}
		
		return true;
	}
}
