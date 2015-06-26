<?php namespace Mmanos\Search\Index;

use Config;

class Zend extends \Mmanos\Search\Index
{
	/**
	 * Create a new zend instance.
	 *
	 * @param string $name
	 * @param string $driver
	 * 
	 * @return void
	 */
	public function __construct($name, $driver)
	{
		parent::__construct($name, $driver);
		
		\ZendSearch\Lucene\Search\QueryParser::setDefaultEncoding('UTF-8');
	}
	
	/**
	 * Instance of a ZendSearch lucene index.
	 *
	 * @var \ZendSearch\Lucene\Index
	 */
	protected $index;
	
	/**
	 * An array of stored query totals to help reduce subsequent count calls.
	 *
	 * @var array
	 */
	protected $stored_query_totals = array();

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
	 * Get the ZendSearch lucene index instance associated with this instance.
	 *
	 * @return \ZendSearch\Lucene\Index
	 */
	protected function getIndex()
	{
		if (!$this->index) {
			$path = rtrim(Config::get('search.connections.zend.path'), '/') . '/' . $this->name;
			
			try {
				$this->index = \ZendSearch\Lucene\Lucene::open($path);
			} catch (\ZendSearch\Exception\ExceptionInterface $e) {
				$this->index = \ZendSearch\Lucene\Lucene::create($path);
			} catch (\ErrorException $e) {
				if (!file_exists($path)) {
					throw new \Exception(
						"'path' directory does not exist for the 'zend' search driver: '"
						. rtrim(Config::get('search.connections.zend.path'), '/')
						. "'"
					);
				}
				throw $e;
			}
			
			\ZendSearch\Lucene\Analysis\Analyzer\Analyzer::setDefault(
				new \ZendSearch\Lucene\Analysis\Analyzer\Common\Utf8Num\CaseInsensitive()
			);
		}
		
		return $this->index;
	}
	
	/**
	 * Get a new query instance from the driver.
	 *
	 * @return \ZendSearch\Lucene\Search\Query\Boolean
	 */
	public function newQuery()
	{
		return new \ZendSearch\Lucene\Search\Query\Boolean;
	}
	
	/**
	 * Add a search/where clause to the given query based on the given condition.
	 * Return the given $query instance when finished.
	 *
	 * @param \ZendSearch\Lucene\Search\Query\Boolean $query
	 * @param array $condition - field      : name of the field
	 *                         - value      : value to match
	 *                         - required   : must match
	 *                         - prohibited : must not match
	 *                         - phrase     : match as a phrase
	 *                         - filter     : filter results on value
	 *                         - fuzzy      : fuzziness value (0 - 1)
	 * 
	 * @return \ZendSearch\Lucene\Search\Query\Boolean
	 */
	public function addConditionToQuery($query, array $condition)
	{
		if (array_get($condition, 'lat')) {
			return $query;
		}
		
		$value = trim($this->escape(array_get($condition, 'value')));
		if (array_get($condition, 'phrase') || array_get($condition, 'filter')) {
			$value = '"' . $value . '"';
		}
		if (isset($condition['fuzzy']) && false !== $condition['fuzzy']) {
			$fuzziness = '';
			if (is_numeric($condition['fuzzy'])
				&& $condition['fuzzy'] >= 0
				&& $condition['fuzzy'] <= 1
			) {
				$fuzziness = $condition['fuzzy'];
			}
			
			$words = array();
			foreach (explode(' ', $value) as $word) {
				$words[] = $word . '~' . $fuzziness;
			}
			$value = implode(' ', $words);
		}
		
		$sign = null;
		if (!empty($condition['required'])) {
			$sign = true;
		}
		else if (!empty($condition['prohibited'])) {
			$sign = false;
		}
		
		$field = array_get($condition, 'field');
		if (empty($field) || '*' === $field) {
			$field = null;
		}
		
		if (is_array($field)) {
			$values = array();
			foreach ($field as $f) {
				$values[] = trim($f) . ':(' . $value . ')';
			}
			$value = implode(' OR ', $values);
		}
		else if ($field) {
			$value = trim(array_get($condition, 'field')) . ':(' . $value . ')';
		}
		
		$query->addSubquery(\ZendSearch\Lucene\Search\QueryParser::parse($value), $sign);
		
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
	 * @param \ZendSearch\Lucene\Search\Query\Boolean $query
	 * @param array $options - limit  : max # of records to return
	 *                       - offset : # of records to skip
	 * 
	 * @return array
	 */
	public function runQuery($query, array $options = array())
	{
		$response = $this->getIndex()->find($query);
		
		$this->stored_query_totals[md5(serialize($query))] = count($response);
		
		$results = array();
		
		if (!empty($response)) {
			foreach ($response as $hit) {
				$fields = array(
						'id'     => $hit->xref_id,
						'_score' => $hit->score,
				);
				
				foreach ($hit->getDocument()->getFieldNames() as $name) {
					if ($name == 'xref_id') continue;
					
					$fields[$name] = $hit->getDocument()->getFieldValue($name);
				}
				
				$results[] = array_merge(
					$fields,
					json_decode(base64_decode($hit->_parameters), true)
				);
			}
		}
		
		if (isset($options['limit']) && isset($options['offset'])) {
			$results = array_slice($results, $options['offset'], $options['limit']);
		}
		
		return $results;
	}
	
	/**
	 * Execute the given query and return the total number of results.
	 *
	 * @param \ZendSearch\Lucene\Search\Query\Boolean $query
	 * 
	 * @return int
	 */
	public function runCount($query)
	{
		if (isset($this->stored_query_totals[md5(serialize($query))])) {
			return $this->stored_query_totals[md5(serialize($query))];
		}
		
		return count($this->runQuery($query));
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
		// Remove any existing documents.
		$this->delete($id);
		
		// Create new document.
		$doc = new \ZendSearch\Lucene\Document();
		
		// Add id parameters.
		$doc->addField(\ZendSearch\Lucene\Document\Field::keyword('xref_id', $id));
		
		// Add fields to document to be indexed and stored.
		foreach ($fields as $field => $value) {
			if (is_array($value)) {
				$value = implode(' ', $value);
			}
			
			$doc->addField(\ZendSearch\Lucene\Document\Field::text(trim($field), trim($value)));
		}
		
		// Add parameters to document to be stored (but not indexed).
		$doc->addField(\ZendSearch\Lucene\Document\Field::unIndexed('_parameters', base64_encode(json_encode($parameters))));
		
		// Add document to index.
		$this->getIndex()->addDocument($doc);
		
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
		$id = trim($this->escape($id));
		$hits = $this->getIndex()->find("xref_id:\"{$id}\"");
		if (empty($hits)) {
			return false;
		}
		
		foreach ($hits as $hit) {
			$this->getIndex()->delete($hit->id);
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
		$path = rtrim(Config::get('search.connections.zend.path'), '/') . '/' . $this->name;
		if (!file_exists($path) || !is_dir($path)) {
			return false;
		}
		
		$this->rmdir($path);
		$this->index = null;
		
		return true;
	}
	
	/**
	 * Helper method to recursively remove an index directory.
	 *
	 * @param string $dir
	 * 
	 * @return void
	 */
	protected function rmdir($dir)
	{
		foreach (glob($dir . '/*') as $file) {
			if (is_dir($file)) {
				$this->rmdir($file);
			}
			else {
				unlink($file);
			}
		}
		
		rmdir($dir);
	}
	
	/**
	 * Helper method to escape all ZendSearch special characters.
	 *
	 * @param string $str
	 * 
	 * @return string
	 */
	protected function escape($str)
	{
		$str = str_replace('\\', '\\\\', $str);
		$str = str_replace('+', '\+', $str);
		$str = str_replace('-', '\-', $str);
		$str = str_replace('&&', '\&&', $str);
		$str = str_replace('||', '\||', $str);
		$str = str_replace('!', '\!', $str);
		$str = str_replace('(', '\(', $str);
		$str = str_replace(')', '\)', $str);
		$str = str_replace('{', '\{', $str);
		$str = str_replace('}', '\}', $str);
		$str = str_replace('[', '\[', $str);
		$str = str_replace(']', '\]', $str);
		$str = str_replace('^', '\^', $str);
		$str = str_replace('"', '\"', $str);
		$str = str_replace('~', '\~', $str);
		$str = str_replace('*', '\*', $str);
		$str = str_replace('?', '\?', $str);
		$str = str_replace(':', '\:', $str);
		
		$str = str_ireplace(
			array(' and ', ' or ', ' not ', ' to '),
			'',
			$str
		);
		
		return $str;
	}
}
