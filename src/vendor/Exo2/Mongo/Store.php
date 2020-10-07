<?php
/**
 * Exo Mongo
 *
 * @package Exo
 * @copyright 2015-2020 Shay Anderson <https://www.shayanderson.com>
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

	public function __get(string $collection): \Exo2\Mongo\Store
	{
		if(!$this->isCollectionAllowed($collection))
		{
			throw new Exception('Collection "' . $this->getDatabaseName() . '.' . $collection
				. '" is not allowed');
		}

		$this->collection = $collection;
		return $this;
	}

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

	final protected static function autoIdMapperInput(&$document): void
	{
		if(!Options::get(Options::AUTO_ID))
		{
			return;
		}

		if($document === null || is_scalar($document))
		{
			return;
		}

		// replace [id] => _id
		if(Options::has(Options::AUTO_ID_MAP_ID))
		{
			if(is_object($document))
			{
				if(property_exists($document, Options::get(Options::AUTO_ID_MAP_ID)))
				{
					$document->_id = $document->{Options::get(Options::AUTO_ID_MAP_ID)};
					unset($document->{Options::get(Options::AUTO_ID_MAP_ID)});
				}
			}
			else
			{
				if(array_key_exists(Options::get(Options::AUTO_ID_MAP_ID), $document))
				{
					$document['_id'] = $document[Options::get(Options::AUTO_ID_MAP_ID)];
					unset($document[Options::get(Options::AUTO_ID_MAP_ID)]);
				}
			}
		}

		// rm auto timestamp if exists
		if(Options::has(Options::AUTO_ID_MAP_TIMESTAMP))
		{
			if(is_object($document))
			{
				if(property_exists($document, Options::get(Options::AUTO_ID_MAP_TIMESTAMP)))
				{
					unset($document->{Options::get(Options::AUTO_ID_MAP_TIMESTAMP)});
				}
			}
			else
			{
				if(array_key_exists(Options::get(Options::AUTO_ID_MAP_TIMESTAMP), $document))
				{
					unset($document[Options::get(Options::AUTO_ID_MAP_TIMESTAMP)]);
				}
			}
		}
	}

	final protected static function autoIdMapperInputArray(array &$documents): void
	{
		foreach($documents as &$doc)
		{
			self::autoIdMapperInput($doc);
		}
	}

	protected function beforeInsertMany(array $documents)
	{
		return $documents;
	}

	protected function beforeInsertOne($document)
	{
		return $document;
	}

	protected function beforeReplace($document)
	{
		return $document;
	}

	protected function beforeReplaceBulk(array $documents)
	{
		return $documents;
	}

	protected function beforeUpdate($update)
	{
		return $update;
	}

	protected function beforeUpdateBulk(array $documents)
	{
		return $documents;
	}

	/**
	 * @return int (affected)
	 */
	protected function bulkWrite(string $operation, string $method, array $documents,
		array $writeOptions, array $options): int
	{
		self::autoIdMapperInputArray($documents);
		$ops = [];

		foreach($documents as $doc)
		{
			if(is_object($doc))
			{
				$doc = (array)$doc;
			}

			if(!isset($doc['_id']))
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
					(
						$operation == 'updateOne'
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

	final protected function client(): \MongoDB\Client
	{
		if(!$this->client)
		{
			$this->client = new \MongoDB\Client('mongodb://' . implode(',',
				Options::get(Options::HOSTS)), [
				'username' => Options::get(Options::USERNAME),
				'password' => Options::get(Options::PASSWORD)
			]);
		}

		return $this->client;
	}

	final public function collection(): \MongoDB\Collection
	{
		if(!$this->collection)
		{
			throw new Exception('Collection name has not been set');
		}

		return $this->database()->{$this->collection};
	}

	protected static function &convertBsonDocToArray(?\MongoDB\Model\BSONDocument $doc)
	{
		if(!$doc)
		{
			$doc = null;
			return $doc;
		}

		$doc = $doc->getArrayCopy();

		if(Options::get(Options::AUTO_ID) && isset($doc['_id']))
		{
			$isObjectId = $doc['_id'] instanceof \MongoDB\BSON\ObjectId;

			if($isObjectId)
			{
				if(Options::has(Options::AUTO_ID_MAP_TIMESTAMP))
				{
					$doc[Options::get(Options::AUTO_ID_MAP_TIMESTAMP)]
						= $doc['_id']->getTimestamp();
				}
				else
				{
					$doc['_ts'] = $doc['_id']->getTimestamp();
				}
			}

			if(Options::has(Options::AUTO_ID_MAP_ID))
			{
				if($isObjectId)
				{
					$doc = [Options::get(Options::AUTO_ID_MAP_ID) => $doc['_id']->__toString()]
						+ $doc;
				}
				else
				{
					$doc = [Options::get(Options::AUTO_ID_MAP_ID) => $doc['_id']] + $doc;
				}
				unset($doc['_id']);
			}
			else
			{
				if($isObjectId)
				{
					$doc = ['_id' => $doc['_id']->__toString()] + $doc;
				}
			}
		}

		if(Options::get(Options::RETURN_OBJECTS))
		{
			$doc = (object)$doc;
		}

		return $doc;
	}

	protected static function convertBsonObjectIdToString($objectId): ?string
	{
		if($objectId instanceof \MongoDB\BSON\ObjectId)
		{
			return $objectId->__toString();
		}

		if(is_scalar($objectId))
		{
			return (string)$objectId;
		}

		return null;
	}

	protected static function &convertCursorToArray(\MongoDB\Driver\Cursor $cursor): array
	{
		$r = [];

		foreach($cursor as $o)
		{
			$r[] = &self::convertBsonDocToArray($o);
		}

		return $r;
	}

	public function &convertIdsToObjectIds(array &$ids): array
	{
		foreach($ids as &$id)
		{
			$id = $this->objectId($id);
		}

		return $ids;
	}

	public function count(array $filter = [], array $options = []): int
	{
		self::autoIdMapperInput($filter);
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		return $this->collection()->count($filter, $options);
	}

	final public function database(): \MongoDB\Database
	{
		return $this->client()->{$this->getDatabaseName()};
	}

	private static function defaultFindOptions(&$options): void
	{
		if(( $limit = Options::get(Options::DEFAULT_LIMIT) ) > 0 && !isset($options['limit']))
		{
			$options['limit'] = $limit;
		}
	}

	public function deleteAll(array $options = []): int
	{
		$this->log(__METHOD__, [
			'options' => $options
		]);

		if(( $res = $this->collection()->deleteMany([], $options) ))
		{
			return (int)$res->getDeletedCount();
		}

		return 0;
	}

	/**
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
	 * @return int (affected)
	 */
	public function deleteByIds(array $ids, array $options = []): int
	{
		$this->log(__METHOD__, [
			'count' => $ids,
			'options' => $options
		]);

		return $this->deleteMany([
			'_id' => [
				'$in' => $this->convertIdsToObjectIds($ids)
			]
		], $options);
	}

	/**
	 * @return int (affected)
	 */
	public function deleteMany(array $filter, array $options = []): int
	{
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		if(empty($filter)) // do not allow delete all
		{
			return 0;
		}

		self::autoIdMapperInput($filter);
		if(( $res = $this->collection()->deleteMany($filter, $options) ))
		{
			return (int)$res->getDeletedCount();
		}

		return 0;
	}

	/**
	 * @return int (affected)
	 */
	public function deleteOne(array $filter, array $options = []): int
	{
		self::autoIdMapperInput($filter);
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		if(( $res = $this->collection()->deleteOne($filter, $options) ))
		{
			return (int)$res->getDeletedCount();
		}

		return 0;
	}

	public function executeCommand(\MongoDB\Driver\Command $command, string $databaseName = null)
	{
		return $this->client()->getManager()->executeCommand(
			$databaseName ? $databaseName : $this->getDatabaseName(), $command);
	}

	/**
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

	public function findById($id, array $options = [])
	{
		$this->log(__METHOD__, [
			'id' => $id,
			'options' => $options
		]);

		return $this->findOne(['_id' => $this->objectId($id)], $options);
	}

	public function findByIds(array $ids, array $options = []): array
	{
		$this->log(__METHOD__, [
			'ids' => $ids,
			'options' => $options
		]);

		if(count($ids) === 1) // auto use single find
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

		foreach($cursor->toArray() as $a)
		{
			$collections[] = $a->name;
		}

		return $collections;
	}

	public function getDatabaseName(): string
	{
		if(!$this->database)
		{
			if(!Options::has(Options::DB))
			{
				throw new Exception('No database set in ' . static::class . ' constructor'
					. ' and missing default DB in options');
			}

			$this->setDatabaseName((string)Options::get(Options::DB));
		}

		return $this->database;
	}

	public function &getDatabases(): array
	{
		$dbs = [];

		/* @var $o \MongoDB\Model\DatabaseInfo */
		foreach($this->client()->listDatabases() as $o)
		{
			$dbs[] = $o->getName();
		}

		return $dbs;
	}

	public function getServerBuildInfo(): \MongoDB\Driver\Cursor
	{
		return $this->executeCommand(
			new Command([
				'buildinfo' => 1
			])
		);
	}

	public function getServerReplicaSetStatus(): \MongoDB\Driver\Cursor
	{
		return $this->executeCommand(
			new Command([
				'replSetGetStatus' => 1
			]),
			'admin'
		);
	}

	public function getServerStatus(): \MongoDB\Driver\Cursor
	{
		return $this->executeCommand(
			new Command([
				'serverStatus' => 1
			])
		);
	}

	public function getServerVersion(): string
	{
		return $this->getServerBuildInfo()->toArray()[0]->version ?? '';
	}

	public function getServers(): array
	{
		return $this->client()->getManager()->getServers();
	}

	public function has(array $filter, array $options = []): bool
	{
		$this->log(__METHOD__, [
			'filter' => $filter,
			'options' => $options
		]);

		return $this->count($filter, $options) > 0;
	}

	public function hasId($id): bool
	{
		$this->log(__METHOD__, [
			'id' => $id
		]);

		return $this->has(['_id' => $this->objectId($id)]);
	}

	/**
	 * @return array (IDs)
	 */
	public function insertMany(array $documents, array $options = []): array
	{
		$this->log(__METHOD__, [
			'documents' => $documents,
			'options' => $options
		]);

		$documents = $this->beforeInsertMany($documents);
		self::autoIdMapperInputArray($documents);
		$r = [];

		if(( $res = $this->collection()->insertMany($documents, $options) )
			&& ( $res = $res->getInsertedIds() ))
		{
			foreach($res as $objectId)
			{
				$r[] = self::convertBsonObjectIdToString($objectId);
			}
		}

		return $r;
	}

	/**
	 * @param array|object $document
	 * @param array $options
	 * @return string|null (ID)
	 */
	public function insertOne($document, array $options = []): ?string
	{
		$this->log(__METHOD__, [
			'document' => $document,
			'options' => $options
		]);

		$document = $this->beforeInsertOne($document);
		self::autoIdMapperInput($document);
		if(( $res = $this->collection()->insertOne($document, $options) ))
		{
			return self::convertBsonObjectIdToString($res->getInsertedId());
		}

		return null;
	}

	private function isCollectionAllowed(string $collection): bool
	{
		if(!count(Options::get(Options::COLLECTIONS))) // allow all, no collection restrictions
		{
			return true;
		}

		static $dbAllowAll;

		if($dbAllowAll === null)
		{
			$dbAllowAll = [];
			foreach(Options::get(Options::COLLECTIONS) as $rule)
			{
				if(($pos = strpos($rule, '*')) !== false)
				{
					$dbAllowAll[] = substr($rule, 0, $pos - 1);
				}
			}
		}

		$db = $this->getDatabaseName();

		if($dbAllowAll && in_array($db, $dbAllowAll)) // allow all in DB
		{
			return true;
		}

		return in_array(
			$db . '.' . $collection,
			Options::get(Options::COLLECTIONS)
		);
	}

	private function log(string $message, array $context): void
	{
		$context = [
			'db' => $this->getDatabaseName(),
			'collection' => $this->collection
		] + $context;

		\Exo\Factory::getInstance()->logger(self::LOG_CHANNEL)->debug($message, $context);
	}

	public function objectId($id)
	{
		if(!is_string($id) || strlen($id) !== 24)
		{
			return $id;
		}

		return new ObjectId($id);
	}

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
		catch(\MongoDB\Driver\Exception\ConnectionTimeoutException $ex)
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
	 * @return int (affected)
	 */
	public function replaceBulk(array $documents, array $write_options = ['ordered' => true],
		array $options = []): int
	{
		$this->log(__METHOD__, []);
		$documents = $this->beforeReplaceBulk($documents);
		return $this->bulkWrite('replaceOne', __METHOD__, $documents, $write_options, $options);
	}

	/**
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
		if(( $res = $this->collection()->replaceOne($filter, $document, $options) ))
		{
			return (int)$res->getModifiedCount();
		}

		return 0;
	}

	public function setDatabaseName(string $database): void
	{
		$this->database = $database;
	}

	/**
	 * @return int (affected)
	 */
	public function updateBulk(array $documents, array $write_options = ['ordered' => true],
		array $options = []): int
	{
		$this->log(__METHOD__, []);
		$documents = $this->beforeUpdateBulk($documents);
		return $this->bulkWrite('updateOne', __METHOD__, $documents, $write_options, $options);
	}

	/**
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
		if(($res = $this->collection()->updateMany($filter, [
			'$set' => $update
		], $options)))
		{
			return (int)$res->getModifiedCount();
		}
	}

	/**
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
		if(($res = $this->collection()->updateOne($filter, [
			'$set' => $update
		], $options)))
		{
			return (int)$res->getModifiedCount();
		}

		return 0;
	}

	public function value(string $property, array $filter)
	{
		$this->log(__METHOD__, [
			'property' => $property,
			'filter' => $filter
		]);

		$a = $this->findOne($filter);

		if($a)
		{
			if(is_object($a))
			{
				if(property_exists($a, $property))
				{
					return $a->{$property};
				}
			}
			else
			{
				if(array_key_exists($property, $a))
				{
					return $a[$property];
				}
			}
		}

		return null;
	}

	public function valueById($id, string $property)
	{
		$this->log(__METHOD__, [
			'id' => $id,
			'property' => $property
		]);

		return $this->value($property, ['_id' => $this->objectId($id)]);
	}
}