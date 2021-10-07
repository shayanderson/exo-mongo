<?php
/**
 * Exo Mongo
 *
 * @package Exo
 * @copyright 2015-2021 Shay Anderson <https://www.shayanderson.com>
 * @license MIT License <https://github.com/shayanderson/exo-mongo/blob/master/LICENSE>
 * @link <https://github.com/shayanderson/exo-mongo>
 */
declare(strict_types=1);

namespace Exo2\Mongo;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Command;

/**
 * Store
 *
 * @author Shay Anderson
 */
abstract class Store
{
	/**
	 * Logger channel
	 */
	const LOG_CHANNEL = 'exo.mongo';

	/**
	 * MongoDB client object
	 *
	 * @var \MongoDB\Client
	 */
	private $client;

	/**
	 * Collection name
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Database name
	 *
	 * @var string
	 */
	private $database = '';

	/**
	 * Init
	 *
	 * @param string $database
	 */
	public function __construct(string $database = '')
	{
		$this->setDatabaseName($database);
	}

	/**
	 * Collection setter
	 *
	 * @param string $collection
	 * @return \Exo2\Mongo\Store
	 * @throws Exception
	 */
	public function __get(string $collection): \Exo2\Mongo\Store
	{
		if (!$this->isCollectionAllowed($collection))
		{
			throw new Exception('Collection "' . $this->getDatabaseName() . '.' . $collection
				. '" is not allowed');
		}

		$this->collection = $collection;
		return $this;
	}

	/**
	 * Aggregate
	 *
	 * @param array $pipeline
	 * @param array $options
	 * @return array
	 */
	public function aggregate(array $pipeline, array $options = []): array
	{
		$this->log(__METHOD__, [
			'pipeline' => $pipeline,
			'options' => $options

		]);
		return self::convertCursorToArray(
			$this->collection()->aggregate($pipeline, $options)
		);
	}

	/**
	 * Collection allocation helper
	 *
	 * @param string $collection
	 * @param mixed $id
	 * @return self
	 */
	public function allocate(string $collection, $id): self
	{
		$this->collection = $collection . '__' . substr(md5($id), 0, 2);
		return $this;
	}

	/**
	 * Auto ID mapper input
	 *
	 * @param array|object $document
	 * @return void
	 */
	final protected static function autoIdMapperInput(&$document): void
	{
		if (!self::options()->get(Options::KEY_AUTO_ID))
		{
			return;
		}

		if ($document === null || is_scalar($document))
		{
			return;
		}

		// replace [id] => _id
		if (self::options()->has(Options::KEY_AUTO_ID_MAP_ID))
		{
			if (is_object($document))
			{
				if (property_exists($document, self::options()->get(Options::KEY_AUTO_ID_MAP_ID)))
				{
					$document->_id = $document->{self::options()->get(Options::KEY_AUTO_ID_MAP_ID)};
					unset($document->{self::options()->get(Options::KEY_AUTO_ID_MAP_ID)});
				}
			}
			else
			{
				if (array_key_exists(self::options()->get(Options::KEY_AUTO_ID_MAP_ID), $document))
				{
					$document['_id'] = $document[self::options()->get(Options::KEY_AUTO_ID_MAP_ID)];
					unset($document[self::options()->get(Options::KEY_AUTO_ID_MAP_ID)]);
				}
			}
		}

		// rm auto timestamp if exists
		if (self::options()->has(Options::KEY_AUTO_ID_MAP_TIMESTAMP))
		{
			if (is_object($document))
			{
				if (property_exists($document, self::options()->get(Options::KEY_AUTO_ID_MAP_TIMESTAMP)))
				{
					unset($document->{self::options()->get(Options::KEY_AUTO_ID_MAP_TIMESTAMP)});
				}
			}
			else
			{
				if (array_key_exists(self::options()->get(Options::KEY_AUTO_ID_MAP_TIMESTAMP), $document))
				{
					unset($document[self::options()->get(Options::KEY_AUTO_ID_MAP_TIMESTAMP)]);
				}
			}
		}
	}

	/**
	 * Auto ID mapper input array
	 *
	 * @param array $documents
	 * @return void
	 */
	final protected static function autoIdMapperInputArray(array &$documents): void
	{
		foreach ($documents as &$doc)
		{
			self::autoIdMapperInput($doc);
		}
	}

	/**
	 * Before insert many trigger
	 *
	 * @param array $documents
	 * @return array
	 */
	protected function beforeInsertMany(array $documents)
	{
		return $documents;
	}

	/**
	 * Before insert one trigger
	 *
	 * @param array|object $document
	 * @return array|object
	 */
	protected function beforeInsertOne($document)
	{
		return $document;
	}

	/**
	 * Before replace trigger
	 *
	 * @param array|object $document
	 * @return array|object
	 */
	protected function beforeReplace($document)
	{
		return $document;
	}

	/**
	 * Before replace bulk trigger
	 *
	 * @param array $documents
	 * @return array
	 */
	protected function beforeReplaceBulk(array $documents)
	{
		return $documents;
	}

	/**
	 * Before update trigger
	 *
	 * @param array|object $update
	 * @return array|object
	 */
	protected function beforeUpdate($update)
	{
		return $update;
	}

	/**
	 * Before update bulk trigger
	 *
	 * @param array $documents
	 * @return array
	 */
	protected function beforeUpdateBulk(array $documents)
	{
		return $documents;
	}

	/**
	 * Bulk write
	 *
	 * @param string $operation
	 * @param string $method
	 * @param array $documents
	 * @param array $writeOptions
	 * @param array $options
	 * @return int (affected)
	 * @throws Exception
	 */
	protected function bulkWrite(
		string $operation,
		string $method,
		array $documents,
		array $writeOptions,
		array $options
	): int
	{
		self::autoIdMapperInputArray($documents);
		$ops = [];

		foreach ($documents as $doc)
		{
			if (is_object($doc))
			{
				$doc = (array)$doc;
			}

			if (!isset($doc['_id']))
			{
				throw new Exception(
					'Method ' . $method . '() requires all documents to have an ID'
				);
			}

			$id = $doc['_id'];
			unset($doc['_id']); // auto rm for operation

			$ops[] = [
				$operation => [
					['_id' => $this->objectId($id)], // filter
					($operation == 'updateOne'
						? ['$set' => $doc] // update
						: $doc // other
					),
					$options
				]
			];
		}

		$this->log($method, [
			'operations' => $ops,
			'options' => $options,
			'writeOptions' => $writeOptions,
			'documents' => $documents
		]);

		return $this->collection()->bulkWrite($ops, $writeOptions)
			->getModifiedCount();
	}

	/**
	 * Client getter
	 *
	 * @return \MongoDB\Client
	 */
	final protected function client(): \MongoDB\Client
	{
		if (!$this->client)
		{
			$options = [
				'username' => self::options()->get(Options::KEY_USERNAME),
				'password' => self::options()->get(Options::KEY_PASSWORD),
			];

			if (self::options()->get(Options::KEY_REPLICA_SET))
			{
				$options['replicaSet'] = self::options()->get(Options::KEY_REPLICA_SET);
			}

			$this->client = new \MongoDB\Client('mongodb://' . implode(
				',',
				self::options()->get(Options::KEY_HOSTS)
			), $options);
		}

		return $this->client;
	}

	/**
	 * Collection getter
	 *
	 * @return \MongoDB\Collection
	 * @throws Exception
	 */
	final public function collection(): \MongoDB\Collection
	{
		if (!$this->collection)
		{
			throw new Exception('Collection name has not been set');
		}

		return $this->database()->{$this->collection};
	}

	/**
	 * Convert BSON document to array
	 *
	 * @param \MongoDB\Model\BSONDocument|null $doc
	 * @return mixed
	 */
	protected static function &convertBsonDocToArray(?\MongoDB\Model\BSONDocument $doc)
	{
		if (!$doc)
		{
			$doc = null;
			return $doc;
		}

		$doc = $doc->getArrayCopy();

		if (self::options()->get(Options::KEY_AUTO_ID) && isset($doc['_id']))
		{
			$isObjectId = $doc['_id'] instanceof \MongoDB\BSON\ObjectId;

			if ($isObjectId)
			{
				if (self::options()->has(Options::KEY_AUTO_ID_MAP_TIMESTAMP))
				{
					$doc[self::options()->get(Options::KEY_AUTO_ID_MAP_TIMESTAMP)]
						= $doc['_id']->getTimestamp();
				}
				else
				{
					$doc['_ts'] = $doc['_id']->getTimestamp();
				}
			}

			if (self::options()->has(Options::KEY_AUTO_ID_MAP_ID))
			{
				if ($isObjectId)
				{
					$doc = [self::options()->get(Options::KEY_AUTO_ID_MAP_ID) => $doc['_id']->__toString()]
						+ $doc;
				}
				else
				{
					$doc = [self::options()->get(Options::KEY_AUTO_ID_MAP_ID) => $doc['_id']] + $doc;
				}
				unset($doc['_id']);
			}
			else
			{
				if ($isObjectId)
				{
					$doc = ['_id' => $doc['_id']->__toString()] + $doc;
				}
			}
		}

		if (self::options()->get(Options::KEY_RETURN_OBJECTS))
		{
			$doc = (object)$doc;
		}

		return $doc;
	}

	/**
	 * Convert BSON ObjectId to string
	 *
	 * @param ObjectId $objectId
	 * @return string|null
	 */
	protected static function convertBsonObjectIdToString($objectId): ?string
	{
		if ($objectId instanceof \MongoDB\BSON\ObjectId)
		{
			return $objectId->__toString();
		}

		if (is_scalar($objectId))
		{
			return (string)$objectId;
		}

		return null;
	}

	/**
	 * Convert cursor to array
	 *
	 * @param \MongoDB\Driver\Cursor $cursor
	 * @return array
	 */
	protected static function &convertCursorToArray(\MongoDB\Driver\Cursor $cursor): array
	{
		$r = [];

		foreach ($cursor as $o)
		{
			$r[] = &self::convertBsonDocToArray($o);
		}

		return $r;
	}

	/**
	 * Convert IDs to ObjectIds
	 *
	 * @param array $ids
	 * @return array
	 */
	public function &convertIdsToObjectIds(array &$ids): array
	{
		foreach ($ids as &$id)
		{
			$id = $this->objectId($id);
		}

		return $ids;
	}

	/**
	 * Count
	 *
	 * @param array $filter
	 * @param array $options
	 * @return int
	 */
	public function count(array $filter = [], array $options = []): int
	{
		self::autoIdMapperInput($filter);
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		return $this->collection()->count($filter, $options);
	}

	/**
	 * Database getter
	 *
	 * @return \MongoDB\Database
	 */
	final public function database(): \MongoDB\Database
	{
		return $this->client()->{$this->getDatabaseName()};
	}

	/**
	 * Distinct values for property in collection
	 *
	 * @param string $property
	 * @return array
	 */
	final public function distinct(string $property): array
	{
		return $this->collection()->distinct($property);
	}

	/**
	 * Distinct fields for collection getter
	 *
	 * @return array
	 */
	final public function &distinctFields(): array
	{
		$a = $this->aggregate([
			[
				'$project' => [
					'arr' => [
						'$objectToArray' => '$$ROOT'
					]
				]
			],
			[
				'$unwind' => '$arr'
			],
			[
				'$group' => [
					'_id' => null,
					'fields' => [
						'$addToSet' => '$arr.k'
					]
				]
			]
		]);

		if (isset($a[0]->fields) && $a[0]->fields instanceof \MongoDB\Model\BSONArray)
		{
			$a = $a[0]->fields->getArrayCopy();
			sort($a);
			return $a;
		}

		return [];
	}

	/**
	 * Default find options setter
	 *
	 * @param array $options
	 * @return void
	 */
	private static function defaultFindOptions(array &$options): void
	{
		if (($limit = self::options()->get(Options::KEY_DEFAULT_LIMIT)) > 0 && !isset($options['limit']))
		{
			$options['limit'] = $limit;
		}
	}

	/**
	 * Delete all
	 *
	 * @param array $options
	 * @return int
	 */
	public function deleteAll(array $options = []): int
	{
		$this->log(__METHOD__, [
			'options' => $options
		]);

		if (($res = $this->collection()->deleteMany([], $options)))
		{
			return (int)$res->getDeletedCount();
		}

		return 0;
	}

	/**
	 * Delete by ID
	 *
	 * @param int|string $id
	 * @param array $options
	 * @return int (affected)
	 */
	public function deleteById($id, array $options = []): int
	{
		$this->log(__METHOD__, [
			'id' => $id,
			'options' => $options
		]);

		return $this->deleteOne(['_id' => $this->objectId($id)], $options);
	}

	/**
	 * Delete by IDs
	 *
	 * @param array $ids
	 * @param array $options
	 * @return int (affected)
	 */
	public function deleteByIds(array $ids, array $options = []): int
	{
		$this->log(__METHOD__, [
			'ids' => $ids,
			'options' => $options
		]);

		return $this->deleteMany([
			'_id' => [
				'$in' => $this->convertIdsToObjectIds($ids)
			]
		], $options);
	}

	/**
	 * Delete many
	 *
	 * @param array $filter
	 * @param array $options
	 * @return int (affected)
	 */
	public function deleteMany(array $filter, array $options = []): int
	{
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		if (empty($filter)) // do not allow delete all
		{
			return 0;
		}

		self::autoIdMapperInput($filter);
		if (($res = $this->collection()->deleteMany($filter, $options)))
		{
			return (int)$res->getDeletedCount();
		}

		return 0;
	}

	/**
	 * Delete one
	 *
	 * @param array $filter
	 * @param array $options
	 * @return int (affected)
	 */
	public function deleteOne(array $filter, array $options = []): int
	{
		self::autoIdMapperInput($filter);
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		if (($res = $this->collection()->deleteOne($filter, $options)))
		{
			return (int)$res->getDeletedCount();
		}

		return 0;
	}

	/**
	 * Execute command
	 *
	 * @param Command $command
	 * @param string $databaseName
	 * @return \MongoDB\Driver\Cursor
	 */
	public function executeCommand(\MongoDB\Driver\Command $command, string $databaseName = null)
	{
		return $this->client()->getManager()->executeCommand(
			$databaseName ? $databaseName : $this->getDatabaseName(),
			$command
		);
	}

	/**
	 * Find
	 *
	 * @param array $filter
	 * @param array $options
	 * @return array
	 */
	public function find(array $filter = [], array $options = []): array
	{
		self::defaultFindOptions($options);
		self::autoIdMapperInput($filter);

		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		return self::convertCursorToArray(
			$this->collection()->find($filter, $options)
		);
	}

	/**
	 * Find by ID
	 *
	 * @param int|string $id
	 * @param array $options
	 * @return array|object|null
	 */
	public function findById($id, array $options = [])
	{
		$this->log(__METHOD__, [
			'id' => $id,
			'options' => $options
		]);

		return $this->findOne(['_id' => $this->objectId($id)], $options);
	}

	/**
	 * Find by IDs
	 *
	 * @param array $ids
	 * @param array $options
	 * @return array
	 */
	public function findByIds(array $ids, array $options = []): array
	{
		$this->log(__METHOD__, [
			'ids' => $ids,
			'options' => $options
		]);

		if (count($ids) === 1) // auto use single find
		{
			$doc = $this->findById(array_pop($ids), $options);
			return $doc ? [$doc] : [];
		}

		return $this->find([
			'_id' => [
				'$in' => $this->convertIdsToObjectIds($ids)
			]
		], $options);
	}

	/**
	 * Find one
	 *
	 * @param array $filter
	 * @param array $options
	 * @return array|object|null
	 */
	public function findOne(array $filter = [], array $options = [])
	{
		self::defaultFindOptions($options);
		self::autoIdMapperInput($filter);

		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		return self::convertBsonDocToArray(
			$this->collection()->findOne($filter, $options)
		);
	}

	/**
	 * Collection names getter
	 *
	 * @return array
	 */
	public function &getCollections(): array
	{
		$collections = [];

		// workaround for bug in db->listCollections()
		$cursor = $this->executeCommand(
			new Command([
				'listCollections' => 1,
				'nameOnly' => 1,
				'authorizedCollections' => 1
			])
		);

		foreach ($cursor->toArray() as $a)
		{
			$collections[] = $a->name;
		}

		return $collections;
	}

	/**
	 * Database name getter
	 *
	 * @return string
	 * @throws Exception
	 */
	public function getDatabaseName(): string
	{
		if (!$this->database)
		{
			if (!self::options()->has(Options::KEY_DB))
			{
				throw new Exception('No database set in ' . static::class . ' constructor'
					. ' and missing default DB in options');
			}

			$this->setDatabaseName((string)self::options()->get(Options::KEY_DB));
		}

		return $this->database;
	}

	/**
	 * Database names getter
	 *
	 * @return array
	 */
	public function &getDatabases(): array
	{
		$dbs = [];

		/* @var $o \MongoDB\Model\DatabaseInfo */
		foreach ($this->client()->listDatabases() as $o)
		{
			$dbs[] = $o->getName();
		}

		return $dbs;
	}

	/**
	 * DB stats getter
	 *
	 * @return array
	 */
	public function getDbStats(): array
	{
		return (array)iterator_to_array($this->executeCommand(
			new Command([
				'dbStats' => 1
			])
		))[0] ?? [];
	}

	/**
	 * Indexes getter
	 *
	 * @return array
	 */
	public function &getIndexes(): array
	{
		$r = [];

		$indexSizes = (array)($this->getStats()[0]->indexSizes ?? []);

		foreach ((array)iterator_to_array(
			$this->collection()->listIndexes()
		) as $o)
		{
			$size = $indexSizes[$o->getName()] ?? 0;
			$r[] = [
				'name' => $o->getName(),
				'key' => $o->getKey(),
				'isSparse' => $o->isSparse(),
				'isTtl' => $o->isTtl(),
				'isUnique' => $o->isUnique(),
				'size' => $size
			];
		}

		usort($r, function ($a, $b)
		{
			return $a['name'] <=> $b['name'];
		});

		return $r;
	}

	/**
	 * Server build info getter
	 *
	 * @return \MongoDB\Driver\Cursor
	 */
	public function getServerBuildInfo(): \MongoDB\Driver\Cursor
	{
		return $this->executeCommand(
			new Command([
				'buildinfo' => 1
			])
		);
	}

	/**
	 * Server replica set status getter
	 *
	 * @return \MongoDB\Driver\Cursor
	 */
	public function getServerReplicaSetStatus(): \MongoDB\Driver\Cursor
	{
		return $this->executeCommand(
			new Command([
				'replSetGetStatus' => 1
			]),
			'admin'
		);
	}

	/**
	 * Server status getter
	 *
	 * @return \MongoDB\Driver\Cursor
	 */
	public function getServerStatus(): \MongoDB\Driver\Cursor
	{
		return $this->executeCommand(
			new Command([
				'serverStatus' => 1
			])
		);
	}

	/**
	 * Server version getter
	 *
	 * @return string
	 */
	public function getServerVersion(): string
	{
		return $this->getServerBuildInfo()->toArray()[0]->version ?? '';
	}

	/**
	 * Servers getter
	 *
	 * @return array
	 */
	public function getServers(): array
	{
		return $this->client()->getManager()->getServers();
	}

	/**
	 * Collection stats getter
	 *
	 * @return array
	 */
	public function getStats(): array
	{
		return (array)iterator_to_array($this->executeCommand(
			new Command([
				'collStats' => $this->collection
			])
		));
	}

	/**
	 * Check if exists
	 *
	 * @param array $filter
	 * @param array $options
	 * @return bool
	 */
	public function has(array $filter, array $options = []): bool
	{
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		return $this->count($filter, $options) > 0;
	}

	/**
	 * Check if ID exists
	 *
	 * @param int|string $id
	 * @return bool
	 */
	public function hasId($id): bool
	{
		$this->log(__METHOD__, [
			'id' => $id
		]);

		return $this->has(['_id' => $this->objectId($id)]);
	}

	/**
	 * Insert many
	 *
	 * @param array $documents
	 * @param array $options
	 * @return array (IDs)
	 */
	public function insertMany(array $documents, array $options = []): array
	{
		$this->log(__METHOD__, [
			'documents' => $documents,
			'options' => $options
		]);

		if (empty($documents)) // avoid MongoDB exception "$documents is empty"
		{
			return [];
		}

		$documents = $this->beforeInsertMany($documents);
		self::autoIdMapperInputArray($documents);
		$r = [];

		if (($res = $this->collection()->insertMany($documents, $options))
			&& ($res = $res->getInsertedIds())
		)
		{
			foreach ($res as $objectId)
			{
				$r[] = self::convertBsonObjectIdToString($objectId);
			}
		}

		return $r;
	}

	/**
	 * Insert one
	 *
	 * @param array|object $document
	 * @param array $options
	 * @return int|string|null (ID)
	 */
	public function insertOne($document, array $options = []): ?string
	{
		$this->log(__METHOD__, [
			'document' => $document,
			'options' => $options
		]);

		$document = $this->beforeInsertOne($document);
		self::autoIdMapperInput($document);
		if (($res = $this->collection()->insertOne($document, $options)))
		{
			return self::convertBsonObjectIdToString($res->getInsertedId());
		}

		return null;
	}

	/**
	 * Check if collection is allowed
	 *
	 * @staticvar array $dbAllowAll
	 * @param string $collection
	 * @return bool
	 */
	private function isCollectionAllowed(string $collection): bool
	{
		if (!count(self::options()->get(Options::KEY_COLLECTIONS))) // allow all, no collection restrictions
		{
			return true;
		}

		static $dbAllowAll;

		if ($dbAllowAll === null)
		{
			$dbAllowAll = [];
			foreach (self::options()->get(Options::KEY_COLLECTIONS) as $rule)
			{
				if (($pos = strpos($rule, '*')) !== false)
				{
					$dbAllowAll[] = substr($rule, 0, $pos - 1);
				}
			}
		}

		$db = $this->getDatabaseName();

		if ($dbAllowAll && in_array($db, $dbAllowAll)) // allow all in DB
		{
			return true;
		}

		return in_array(
			$db . '.' . $collection,
			self::options()->get(Options::KEY_COLLECTIONS)
		);
	}

	/**
	 * Log debug
	 *
	 * @param string $message
	 * @param array $context
	 * @return void
	 */
	private function log(string $message, array $context): void
	{
		$context = [
			'db' => $this->getDatabaseName(),
			'collection' => $this->collection
		] + $context;

		\Exo\Factory::getInstance()->logger(self::LOG_CHANNEL)->debug($message, $context);
	}

	/**
	 * ID to ObjectId
	 *
	 * @param mixed $id
	 * @return ObjectId
	 */
	public function objectId($id)
	{
		if (!is_string($id) || strlen($id) !== 24)
		{
			return $id;
		}

		return new ObjectId($id);
	}

	/**
	 * Options object getter
	 *
	 * @return \Exo2\Mongo\Options
	 */
	private static function options(): \Exo2\Mongo\Options
	{
		static $options;

		if (!$options)
		{
			$options = Options::getInstance();
		}

		return $options;
	}

	/**
	 * Ping
	 *
	 * @return bool
	 */
	public function ping(): bool
	{
		try
		{
			$cur = $this->executeCommand(
				new Command(['ping' => 1]),
				'admin'
			);

			return (int)$cur->toArray()[0]->ok === 1;
		}
		catch (\MongoDB\Driver\Exception\ConnectionTimeoutException $ex)
		{
			// connection failed
			$this->log(__METHOD__, [
				'connectionTimeout' => true,
				'exceptionMessage' => $ex->getMessage()
			]);
		}

		return false;
	}

	/**
	 * Bulk replace
	 *
	 * @param array $documents
	 * @param array $writeOptions
	 * @param array $options
	 * @return int (affected)
	 */
	public function replaceBulk(
		array $documents,
		array $writeOptions = ['ordered' => true],
		array $options = []
	): int
	{
		$this->log(__METHOD__, []);
		$documents = $this->beforeReplaceBulk($documents);
		return $this->bulkWrite('replaceOne', __METHOD__, $documents, $writeOptions, $options);
	}

	/**
	 * Replace by ID
	 *
	 * @param int|string $id
	 * @param array|object $document
	 * @return int (affected)
	 */
	public function replaceById($id, $document): int
	{
		$this->log(__METHOD__, [
			'id' => $id
		]);

		return $this->replaceOne(['_id' => $this->objectId($id)], $document);
	}

	/**
	 * Replace one
	 *
	 * @param array $filter
	 * @param array|object $document
	 * @param array $options
	 * @return int (affected)
	 */
	public function replaceOne(array $filter, $document, array $options = []): int
	{
		self::autoIdMapperInput($filter);
		$this->log(__METHOD__, [
			'filter' => $filter,
			'document' => $document,
			'options' => $options
		]);

		$document = $this->beforeReplace($document);
		self::autoIdMapperInput($document);
		if (($res = $this->collection()->replaceOne($filter, $document, $options)))
		{
			return (int)$res->getModifiedCount();
		}

		return 0;
	}

	/**
	 * Set database name
	 *
	 * @param string $database
	 * @return void
	 */
	public function setDatabaseName(string $database): void
	{
		$this->database = $database;
	}

	/**
	 * Bulk update
	 *
	 * @param array $documents
	 * @param array $writeOptions
	 * @param array $options
	 * @return int (affected)
	 */
	public function updateBulk(
		array $documents,
		array $writeOptions = ['ordered' => true],
		array $options = []
	): int
	{
		$this->log(__METHOD__, []);
		$documents = $this->beforeUpdateBulk($documents);
		return $this->bulkWrite('updateOne', __METHOD__, $documents, $writeOptions, $options);
	}

	/**
	 * Update by ID
	 *
	 * @param int|string $id
	 * @param array|object $update
	 * @return int (affected)
	 */
	public function updateById($id, $update): int
	{
		$this->log(__METHOD__, [
			'id' => $id
		]);

		return $this->updateOne(['_id' => $this->objectId($id)], $update);
	}

	/**
	 * Update many
	 *
	 * @param array $filter
	 * @param array|object $update
	 * @param array $options
	 * @return int (affected)
	 */
	public function updateMany(array $filter, $update, array $options = []): int
	{
		self::autoIdMapperInput($filter);
		$this->log(__METHOD__, [
			'filter' => $filter,
			'update' => $update,
			'options' => $options
		]);

		$update = $this->beforeUpdate($update);
		if (($res = $this->collection()->updateMany($filter, [
			'$set' => $update
		], $options)))
		{
			return (int)$res->getModifiedCount();
		}
	}

	/**
	 * Update one
	 *
	 * @param array $filter
	 * @param array|object $update
	 * @param array $options
	 * @return int (affected)
	 */
	public function updateOne(array $filter, $update, array $options = []): int
	{
		self::autoIdMapperInput($filter);
		$this->log(__METHOD__, [
			'filter' => $filter,
			'update' => $update,
			'options' => $options
		]);

		$update = $this->beforeUpdate($update);
		if (($res = $this->collection()->updateOne($filter, [
			'$set' => $update
		], $options)))
		{
			return (int)$res->getModifiedCount();
		}

		return 0;
	}

	/**
	 * Property value by filter getter
	 *
	 * @param string $property
	 * @param array $filter
	 * @return mixed
	 */
	public function value(string $property, array $filter)
	{
		$this->log(__METHOD__, [
			'property' => $property,
			'filter' => $filter
		]);

		$a = $this->findOne($filter);

		if ($a)
		{
			if (is_object($a))
			{
				if (property_exists($a, $property))
				{
					return $a->{$property};
				}
			}
			else
			{
				if (array_key_exists($property, $a))
				{
					return $a[$property];
				}
			}
		}

		return null;
	}

	/**
	 * Property value by ID getter
	 *
	 * @param int|string $id
	 * @param string $property
	 * @return mixed
	 */
	public function valueById($id, string $property)
	{
		$this->log(__METHOD__, [
			'id' => $id,
			'property' => $property
		]);

		return $this->value($property, ['_id' => $this->objectId($id)]);
	}
}
