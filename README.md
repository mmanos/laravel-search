Search Package for Laravel 4
============================

This package provides full text search capabilities for Laravel 4 applications.

Installation via Composer
-------------------------

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

Configuration
-------------

Publish the default config file to your application so you can make modifications.

```console
php artisan config:publish mmanos/laravel-search
```

The following dependencies are needed for the listed search drivers:

* ZendSearch: `zendframework/zendsearch`
* Elasticsearch: `elasticsearch/elasticsearch`

Working with the default index
------------------------------

This package provides a convenient syntax for working with the "default" index. Edit the `default_index` field in the config file to change this value.

Add a document with an `id` of "1" to the "default" index:

```php
Search::insert(1, array(
	'title' => 'My title',
	'content' => 'Some random content here...',
));
```

*Note: documents are required to provide a unique `id`. The value may be a string or an integer. This id is used to delete records and is also returned in search results.*

*Note: the index will be created automatically if it does not exist.*

Search the "default" index for documents who's `content` field contains the word 'random':

```php
$results = Search::search('content', 'random')->get();
```

Delete the document with an `id` of "1":

```php
Search::delete(1);
```

Working with multiple indicies
------------------------------

If you need to work with more than one index, you may access all of the same methods mentioned above after you specify the index name.

Add a document to an index called "posts":

```php
Search::index('posts')->insert(1, array(
	'title' => 'My title',
	'content' => 'Some random content here...',
));
```

Add a document to an index called "posts" and store some extra parameters that can be returned from search results:

```php
Search::index('posts')->insert(
	"postID-1",
	array(
		'title' => 'My title',
		'content' => 'Some random content here...',
	),
	array(
		'created_at' => date('Y-m-d H:i:s'),
		'creator'    => 'Mark',
	)
);
```

*Note: extra parameters are not indexed but are stored in the index so they may be retrieved at a later point.*

Search the "posts" index for documents who's `content` field contains the word 'random':

```php
$results = Search::index('posts')->search('content', 'random')->get();
```

Search more than one field:

```php
$results = Search::index('posts')->search(array('title', 'content'), 'random')->get();
```

Search all fields:

```php
$results = Search::index('posts')->search(null, 'random')->get();
```

Apply a filter to your query:

```php
$results = Search::index('posts')->search('content', 'random')
	->where('title', 'My title')
	->get();
```

*Note: filters attempt to match the value you specify as an entire "phrase". It does not guarantee an exact match of the entire field value.*

Limit your result set:

```php
$results = Search::index('posts')->search('content', 'random')
	->where('title', 'My title')
	->limit(10) // Limit 10
	->get();
$results = Search::index('posts')->search('content', 'random')
	->where('title', 'My title')
	->limit(10, 30) // Limit 10, offset 30
	->get();
```

Paginate your result set:

```php
$paginator = Search::index('posts')->search('content', 'random')->paginate(15);
```

*Note: returns a Laravel paginator instance.*

Limit the fields you want back from the response:

```php
$results = Search::index('posts')->select('id', 'created_at')
	->search('content', 'random')
	->get();
```

Delete all documents that match a given query:

```php
Search::index('posts')->search('content', 'random')
	->where('title', 'My title')
	->delete();
```

Chain multiple searches and filters:

```php
$results = Search::index('posts')->select('id', 'created_at')
	->where('title', 'My title')
	->where('creator', 'Mark')
	->search('content', 'random')
	->search('content', 'some')
	->limit(10)
	->get();
```

*Note: chained filters/searches are constructed as boolean searches where each **must** provide a match.*

Delete an entire index:

```php
Search::index('posts')->deleteIndex();
```

Advanced queries
----------------

If you need more control over a search query you may add a callback function which will be called after all conditions have been added to the query but before the query has been executed. You can then make changes to the native query instance and return it to be executed.

```php
$results = Search::index('posts')->select('id', 'created_at')
	->search('content', 'random')
	->addCallback(function ($query) {
		// Make changes to $query...
		return $query;
	})
	->get();
```

Since each driver has it's own native $query object/array, you may only want to execute your callback for one of the drivers:

```php
$results = Search::index('posts')->select('id', 'created_at')
	->search('content', 'random')
	->addCallback(function ($query) {
		// Adjust pagination for an elasticsearch query.
		$query['from'] = 0;
		$query['size'] = 20;
		return $query;
	}, 'elasticsearch')
	->get();
```

*Note: you may also pass an array of drivers as the second parameter.*
