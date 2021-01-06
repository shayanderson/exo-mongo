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
class Options extends \Exo\Options
{
	/**
	 * Option names
	 */
	const KEY_AUTO_ID = 'Exo2.Mongo.autoId';
	const KEY_AUTO_ID_MAP_ID = 'Exo2.Mongo.autoIdMapId';
	const KEY_AUTO_ID_MAP_TIMESTAMP = 'Exo2.Mongo.autoIdMapTimestamp';
	const KEY_COLLECTIONS = 'Exo2.Mongo.collections';
	const KEY_DB = 'Exo2.Mongo.db';
	const KEY_DEFAULT_LIMIT = 'Exo2.Mongo.defaultLimit';
	const KEY_HOSTS = 'Exo2.Mongo.hosts';
	const KEY_PASSWORD = 'Exo2.Mongo.password';
	const KEY_RETURN_OBJECTS = 'Exo2.Mongo.returnObjects';
	const KEY_USERNAME = 'Exo2.Mongo.username';

	/**
	 * Options map
	 *
	 * @var array
	 */
	private $map = [];

	/**
	 * Init
	 */
	protected function __construct()
	{
		require_once PATH_VENDOR . 'MongoDB' . DIRECTORY_SEPARATOR . 'functions.php';

		$this->option(self::KEY_AUTO_ID)
			->default(false)
			->validator()
			->boolean()
			->type();

		$this->option(self::KEY_AUTO_ID_MAP_ID)
			->validator()
			->string()
			->alnum()
			->optional();

		$this->option(self::KEY_AUTO_ID_MAP_TIMESTAMP)
			->validator()
			->string()
			->alnum()
			->optional();

		$this->option(self::KEY_COLLECTIONS)
			->default([])
			->validator()
			->arrayType()
			->optional();

		$this->option(self::KEY_DB)
			->validator()
			->string();

		$this->option(self::KEY_DEFAULT_LIMIT)
			->default(0)
			->validator()
			->number()
			->integer()
			->optional();

		$this->option(self::KEY_HOSTS)
			->default(['127.0.0.1']) // with port: "127.0.0.1:27017"
			->validator()
			->arrayType();

		$this->option(self::KEY_PASSWORD)
			->validator()
			->string();

		$this->option(self::KEY_RETURN_OBJECTS)
			->default(false)
			->validator()
			->boolean()
			->type();

		$this->option(self::KEY_USERNAME)
			->validator()
			->string();
	}

	/**
	 * Read all
	 *
	 * @return void
	 */
	protected function read(array &$map): void
	{
		$map = $this->map;
	}

	/**
	 * Write key/value
	 *
	 * @return void
	 */
	protected function write(string $key, $value): bool
	{
		$this->map[$key] = $value;
		return true;
	}
}