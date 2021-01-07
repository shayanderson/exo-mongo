# Exo Mongo

## Options
```php
use Exo2\Mongo\Options;
Options::getInstance()->set([
	// with port '127.0.0.1:27017'
	Options::KEY_HOSTS => ['127.0.0.1'],
	Options::KEY_USERNAME => 'user',
	Options::KEY_PASSWORD => 'secrect',
	// default database
	Options::KEY_DB => 'test',
	// set default limit for find queries to restrict memory usage (default is zero for no limit)
	Options::KEY_DEFAULT_LIMIT => 10000,

	// only allow specific collections
	// syntax: "[db].[collection]"
	// DB wildcards supported like: "test.*"
	Options::KEY_COLLECTIONS => [
		'test.users'
	]
]);
```
### Auto ID Conversion Option
The `KEY_AUTO_ID` option can be set to `true` to automatically convert the `_id` field value from `MongoDB\BSON\ObjectId` to `string` and add a `_ts` field with a timestamp.
```
// with option
Array
(
	[_id] => 5eebd8a63b6eaz21c907a157,
	[name] => Shay,
	[_ts] => 1512544816
)
// without option
Array
(
	[_id] => MongoDB\BSON\ObjectId Object
		(
			[oid] => 5eebd8a63b6eaz21c907a157
		)
	[name] => Shay
)
```
#### Auto ID Conversion Option With Field Mapper
> Both of the options below require the `KEY_AUTO_ID` option to be set to `true`.

The `KEY_AUTO_ID_MAP_ID` option can be set to a string to automatically convert the `_id` field name to another name, like `id`.
The `KEY_AUTO_ID_MAP_TIMESTAMP` option can be set to a string to automatically convert the `_ts` field name to another name, like `createdAt`.
Both of the options support both input and output.
```php
use Exo2\Mongo\Options;
use Exo2\Mongo\Store;
Options::getInstance()->set([
	Options::KEY_AUTO_ID => true,
	Options::KEY_AUTO_ID_MAP_ID => 'id',
	Options::KEY_AUTO_ID_MAP_TIMESTAMP => 'createdAt',
]);

$store = new Store;
// input example (using 'id' instead of '_id')
$user = $store->users->findOne(['id' => $store->objectId('5eebd8a63b6eaz21c907a157')]);
```
Output `$user` example using `id` and `createdAt` instead of `_id` and `_ts`:
```
Array
(
	[id] => 5eebd8a63b6eaz21c907a157
	[name] => Shay
	[createdAt] => 1512544816
)
```

## Exception Handling
All `Exo2\Mongo\Store` methods have useful debugging info that can be used during runtime:
```php
try
{
	(new \Exo2\Mongo\Store)->users->insertMany([
		['_id' => 5],
		['_id' => 5]
	]);
}
catch(Exception $ex)
{
	// handle exception, or continue
}
```



## Store Hooks
These hook methods are available:
- `beforeInsertMany(array $documents)`
- `beforeInsertOne($document)`
- `beforeReplace($document)`
- `beforeReplaceBulk(array $documents)`
- `beforeUpdate($update)`
- `beforeUpdateBulk(array $documents)`

These methods can be overridden to modify documents during operations. Here is an example setting a `updatedAt` property for all documents:
```php
class MyStore extends \Exo2\Mongo\Store
{
	protected function beforeInsertMany(array $documents): array
	{
		foreach($documents as &$doc)
		{
			$doc = $this->beforeInsertOne((array)$doc);
		}
		return $documents;
	}

	protected function beforeInsertOne($document)
	{
		$document['updatedAt'] = null;
		return $document;
	}

	protected function beforeReplace($document)
	{
		return $this->beforeUpdate($document);
	}

	protected function beforeReplaceBulk(array $documents): array
	{
		foreach($documents as &$doc)
		{
			$doc = $this->beforeReplace((array)$doc);
		}
		return $documents;
	}

	protected function beforeUpdate($update)
	{
		$update['updatedAt'] = time();
		return $update;
	}

	protected function beforeUpdateBulk(array $documents): array
	{
		foreach($documents as &$doc)
		{
			$doc = $this->beforeUpdate((array)$doc);
		}
		return $documents;
	}
}
```



# Methods
All the method examples below assume usage of:
```php
use Exo2\Mongo\Store;
// usage:
// (new Store)->[collection]->[method]();
```

## `count(): int`
```php
$count = (new Store)->users->count(['username' => 'firstuser']);
```

## `deleteAll(): int`
```php
$affected = (new Store)->users->deleteAll();
```

## `deleteById(): int`
```php
$affected = (new Store)->users->deleteById(5);
```

## `deleteByIds(): int`
```php
$affected = (new Store)->users->deleteByIds([9, 10]);
```

## `deleteMany(): int`
> This method does not allow an empty filter of `[]` to delete all documents. To delete all documents use the `deleteAll()` method.
```php
$affected = (new Store)->users->deleteMany(['isDisabled' => 1]);
```

## `deleteOne(): int`
```php
$affected = (new Store)->users->deleteOne(['username' => 'firstuser']);
```

## `find(): array`
```php
$docs = (new Store)->users->find(['isAdmin' => true]);
```

## `findById(): ?array`
```php
$doc = (new Store)->users->findById(5);
```

## `findOne(): ?array`
```php
$doc = (new Store)->users->findOne(['username' => 'firstuser']);
```

## `has(): bool`
```php
$hasUser = (new Store)->users->has(['username' => 'firstuser']);
```

## `hasId(): bool`
```php
$hasUser = (new Store)->users->hasId(5);
```

## `insertMany(): array`
```php
$userIds = (new Store)->users->insertMany([[
	'name' => 'Shay',
	'username' => 'seconduser',
	'isAdmin' => true
], [
	'name' => 'Bob',
	'username' => 'thirduser',
	'isAdmin' => false
]]);
```

## `insertOne(): ?string`
```php
$userId = (new Store)->users->insertOne([
	'name' => 'Shay',
	'username' => 'seconduser',
	'isAdmin' => true
]);
```

## `objectId()`
```php
$store = new Store;
$doc = $store->users->findOne([
	'_id' => $store->objectId('5ed80b2b2zd8bf682e302f53'),
	'isAdmin' => true
]);
```

## `replaceBulk(): int`
> This method requires all documents to have an ID.
```php
$affected = (new Store)->users->replaceBulk([[
	'_id' => 5,
	'name' => 'Shay',
	'username' => 'seconduser',
	'isAdmin' => false
], [
	'_id' => 6,
	'name' => 'Bob',
	'username' => 'thirduser',
	'isAdmin' => true
]]);
```

## `replaceById(): int`
```php
$affected = (new Store)->users->replaceById(5, [
	'name' => 'Shay',
	'username' => 'seconduser',
	'isAdmin' => false
]);
```

## `replaceOne(): int`
```php
$affected = (new Store)->users->replaceOne(['_id' => 1], [
	'name' => 'Shay',
	'username' => 'seconduser',
	'isAdmin' => false
]);
```

## `updateOne(): int`
```php
// when username:"someuser" set isAdmin:false
$affected = (new Store)->users->updateOne(['username' => 'someuser'], ['isAdmin' => false]);
```

## `updateBulk(): int`
> This method requires all documents to have an ID.
```php
$affected = (new Store)->users->updateBulk([[
	'_id' => 5,
	'name' => 'Shay',
	'username' => 'seconduser',
	'isAdmin' => false
], [
	'_id' => 6,
	'name' => 'Bob',
	'username' => 'thirduser',
	'isAdmin' => true
]]);
```

## `updateById(): int`
```php
$affected = (new Store)->users->updateById(5, ['isAdmin' => false]);
```

## `updateMany(): int`
```php
// when isDisabled:true set isAdmin:false
$affected = (new Store)->users->updateMany(['isDisabled' => true], ['isAdmin' => false]);
```

## `updateOne(): int`
```php
// when username:"someuser" set isAdmin:false
$affected = (new Store)->users->updateOne(['username' => 'someuser'], ['isAdmin' => false]);
```

## `value()`
```php
$isAdmin = (new Store)->users->value('isAdmin', ['username' => 'someuser']);
```

## `valueById()`
```php
$username = (new Store)->users->valueById(5, 'username');
```



# MongoDB Library Methods
## `collection(): \MongoDB\Collection`
Access collection object directly:
```php
$indexes = (new \Exo2\Mongo\Store)->users->collection()->listIndexes();
print_r(iterator_to_array($indexes));
```
## `database(): \MongoDB\Database`
Access database object directly:
```php
$dbName = (new \Exo2\Mongo\Store)->database()->getDatabaseName();
echo $dbName;
```




# Helper Function
Helper function example:
```php
function mongo(string $database = ''): \Exo2\Mongo\Store
{
	return new \Exo2\Mongo\Store($database);
}
$user = mongo()->users->findById(5);
```