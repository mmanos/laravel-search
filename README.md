# Search Package for Laravel 5

This package provides a unified API across a variety of different full text search services. It currently supports drivers for [Elasticsearch](http://www.elasticsearch.org/), [Algolia](https://www.algolia.com/), and [ZendSearch](https://github.com/zendframework/ZendSearch) (good for local use).

## Installation Via Composer

Add this to you composer.json file, in the require object:

```javascript
"mmanos/laravel-search": "dev-master"
```

After that, run composer install to install the package.

Add the service provider to `app/config/app.php`, within the `providers` array.

```php
'providers' => array(
	// ...
	'Mmanos\Search\SearchServiceProvider',
)
```

Add a class alias to `app/config/app.php`, within the `aliases` array.

```php
'aliases' => array(
	// ...
	'Search' => 'Mmanos\Search\Facade',
)
```

## Laravel 4

Use the `0.0` branch or the `v0.*` tags for Laravel 4 support.

## Configuration

Publish the default config file to your application so you can make modifications.

```console
$ php artisan vendor:publish
```

#### Dependencies

The following dependencies are needed for the listed search drivers:

* ZendSearch: `zendframework/zendsearch`
* Elasticsearch: `elasticsearch/elasticsearch`
* Algolia: `algolia/algoliasearch-client-php`

#### Default Index

This package provides a convenient syntax for working with a "default" index. Edit the `default_index` field in the config file to change this value. If you need to work with more than one index, see *Working With Multiple Indicies* below.

## Indexing Operations

Indexing is very easy with this package. Simply provide a unique identifier for the document and an associative array of fields to index.

The index will be **created automatically** if it does not exist the first time you access it.

#### Index A Document

Add a document to the "default" index with an `id` of "1".

```php
Search::insert(1, array(
	'title' => 'My title',
	'content' => 'The quick brown fox...',
	'status' => 'published',
));
```

> **Note:** `id` may be a string or an integer. This id is used to delete records and is also returned in search results.

#### Store Extra Parameters With A Document

You may store extra parameters with a document so they can be retrieved at a later point from search results. This can be useful for referencing timestamps or other record identifiers.

```php
Search::insert(
	"post-1",
	array(
		'title' => 'My title',
		'content' => 'The quick brown fox...',
		'status' => 'published',
	),
	array(
		'created_at' => time(),
		'creator_id' => 5,
	)
);
```

> **Note:** Extra parameters are not indexed but are stored in the index for future retrieval.

#### Delete A Document

Delete a document from the "default" index with an `id` of "1":

```php
Search::delete(1);
```

#### Delete An Index

```php
Search::deleteIndex();
```

## Search Operations

#### Search For A Document

Search the "default" index for documents who's `content` field contains the word "fox":

```php
$results = Search::search('content', 'fox')->get();
```

#### Search More Than One Field

```php
$results = Search::search(array('title', 'content'), 'fox')->get();
```

#### Search All Fields

```php
$results = Search::search(null, 'fox')->get();
```

#### Perform A Fuzzy Search

Perform a fuzzy search to find results with similar, but not exact, spelling. For example, you want to return documents containing the word "updates" by searching for the word "update":

```php
$results = Search::search('content', 'update', array('fuzzy'=>true))->get();
```

> **Note:** You may also pass a numeric value between 0 and 1 for the fuzzy parameter, where a value closer to 1 requires a higher similarity. Defaults to 0.5.

#### Apply A Filter To Your Query

You can apply filters to your search queries as well. Filters attempt to match the value you specify as an entire "phrase". 

```php
$results = Search::search('content', 'fox')
	->where('status', 'published')
	->get();
```

> **Note:** Filters do not guarantee an exact match of the entire field value if the value contains multiple words.

#### Geo-Search

Some drivers support location-aware searching:

```php
$results = Search::search('content', 'fox')
	->whereLocation(36.16781, -96.023561, 10000)
	->get();
```

Where the parameters are `latitude`, `longitude`, and `distance` (in meters).

> **Note:** Currently, only the `algolia` driver supports geo-searching. Ensure each indexed record contains the location information: `_geoloc => ['lat' => 1.23, 'lng' => 1.23]`.

#### Limit Your Result Set

```php
$results = Search::search('content', 'fox')
	->where('status', 'published')
	->limit(10) // Limit 10
	->get();

$results = Search::search('content', 'fox')
	->where('status', 'published')
	->limit(10, 30) // Limit 10, offset 30
	->get();
```

#### Paginate Your Result Set

You can also paginate your result set using a Laravel paginator instance.

```php
$paginator = Search::search('content', 'fox')->paginate(15);
```

#### Limit The Fields You Want Back From The Response

```php
$results = Search::select('id', 'created_at')
	->search('content', 'fox')
	->get();
```

#### Chain Multiple Searches And Filters

```php
$results = Search::select('id', 'created_at')
	->where('title', 'My title')
	->where('status', 'published')
	->search('content', 'fox')
	->search('content', 'quick')
	->limit(10)
	->get();
```

> **Note:** Chained filters/searches are constructed as boolean queries where each **must** provide a match.

#### Delete All Documents That Match A Query

```php
Search::search('content', 'fox')->delete();
```

## Working With Multiple Indicies

If you need to work with more than one index, you may access all of the same methods mentioned above after you specify the index name.

Add a document to an index called "posts":

```php
Search::index('posts')->insert(1, array(
	'title' => 'My title',
	'content' => 'The quick brown fox...',
	'status' => 'published',
));
```

Search the "posts" index for documents who's `content` field contains the word "fox" and who's `status` is "published":

```php
$results = Search::index('posts')->search('content', 'fox')
	->where('status', 'published')
	->get();
```

Delete a document from the "posts" index with an `id` of "1":

```php
Search::index('posts')->delete(1);
```

Delete the entire "posts" index:

```php
Search::index('posts')->deleteIndex();
```

## Advanced Query Callbacks

If you need more control over a search query you may add a callback function which will be called after all conditions have been added to the query but before the query has been executed. You can then make changes to the native query instance and return it to be executed.

```php
$results = Search::index('posts')->select('id', 'created_at')
	->search('content', 'fox')
	->addCallback(function ($query) {
		// Make changes to $query...
		return $query;
	})
	->get();
```

Since each driver has it's own native `$query` object/array, you may only want to execute your callback for one of the drivers:

```php
$results = Search::index('posts')->select('id', 'created_at')
	->search('content', 'fox')
	->addCallback(function ($query) {
		// Adjust pagination for an elasticsearch query array.
		$query['from'] = 0;
		$query['size'] = 20;
		return $query;
	}, 'elasticsearch')
	->get();
```

> **Note:** You may also pass an array of drivers as the second parameter.
