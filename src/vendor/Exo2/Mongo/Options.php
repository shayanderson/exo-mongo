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

/**
 * Options
 *
 * @author Shay Anderson
 */
class Options extends \Exo\Options\Singleton
{
	/**
	 * Option names
	 */
	const AUTO_ID = 'Exo2.Mongo.autoId';
	const AUTO_ID_MAP_ID = 'Exo2.Mongo.autoIdMapId';
	const AUTO_ID_MAP_TIMESTAMP = 'Exo2.Mongo.autoIdMapTimestamp';
	const COLLECTIONS = 'Exo2.Mongo.collections';
	const DB = 'Exo2.Mongo.db';
	const DEFAULT_LIMIT = 'Exo2.Mongo.defaultLimit';
	const HOSTS = 'Exo2.Mongo.hosts';
	const PASSWORD = 'Exo2.Mongo.password';
	const RETURN_OBJECTS = 'Exo2.Mongo.returnObjects';
	const USERNAME = 'Exo2.Mongo.username';

	/**
	 * Init
	 */
	protected function __init()
	{
		require_once PATH_VENDOR . 'MongoDB' . DIRECTORY_SEPARATOR . 'functions.php';

		$this->option(self::AUTO_ID)
			->boolean()
			->type();

		$this->option(self::AUTO_ID_MAP_ID)
			->string()
			->alnum()
			->optional();

		$this->option(self::AUTO_ID_MAP_TIMESTAMP)
			->string()
			->alnum()
			->optional();

		$this->option(self::COLLECTIONS)
			->arrayType()
			->optional();

		$this->option(self::DB)
			->string();

		$this->option(self::DEFAULT_LIMIT)
			->number()
			->integer()
			->optional();

		$this->option(self::HOSTS)
			->arrayType();

		$this->option(self::PASSWORD)
			->string();

		$this->option(self::RETURN_OBJECTS)
			->boolean()
			->type();

		$this->option(self::USERNAME)
			->string();

		$this->set([
			self::AUTO_ID => false,
			self::COLLECTIONS => [],
			self::DEFAULT_LIMIT => 0,
			self::HOSTS  => ['127.0.0.1'], // with port: "127.0.0.1:27017"
			self::RETURN_OBJECTS => false
		]);
	}
}