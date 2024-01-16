<?php

namespace Lum\DB\Mongo;

/**
 * MongoDB Simple connection library.
 */
class Simple
{
  protected $server;
  protected $db;
  protected $data;

  protected $mongo_server_key    = 'mongo.server';
  protected $mongo_db_key        = 'mongo.database';
  protected $mongo_cache_dbs     = 'mongo.cache.dbs';
  protected $mongo_cache_servers = 'mongo.cache.servers';

  // If you don't want to auto connect to the collection, set this to false.
  protected $auto_connect = true;

  // If you aren't using auto_connect, or need to reconnect, set this to true.
  protected $save_build_opts = false;
  protected $build_opts;

  public function __construct ($opts=[])
  {
    if (isset($opts['saveOpts']))
    {
      $this->save_build_opts = $opts['saveOpts'];
    }

    if ($this->save_build_opts)
    {
      $this->build_opts = $opts;
    }

    if (isset($opts['autoConnect']))
    {
      $this->auto_connect = $opts['autoConnect'];
    }

    if ($this->auto_connect)
    {
      $collection = $this->get_collection($opts);
      if (!isset($collection))
      {
        throw new \Exception("invalid collection, could not build.");
      }
    }
  }

  public function get_server ($opts=[])
  {
#    error_log("MongoSimple::get_server()");
    $core = \Lum\Core::getInstance();
    $msk  = $this->mongo_server_key;
    $msc  = $this->mongo_cache_servers;

#    error_log("looking for option '$msk'");

    if (isset($this->server))
    {
      return $this->server;
    }

    if (isset($this->build_opts) && count($opts) == 0)
    {
      $opts = $this->build_opts;
    }

    if (isset($opts['server']))
    {
      $server = $opts['server'];
    }
    elseif (isset($opts['dsn']))
    {
      $server = $opts['dsn'];
    }
    elseif (isset($core[$msk]))
    {
      $server = $core[$msk];
    }
    else
    {
      $server = 'mongodb://localhost:27017';
    }

#    error_log("connecting to server '$server'");

    if (isset($core[$msc], $core[$msc][$server]))
    {
      return $this->server = $core[$msc][$server];
    }

    $uriOpts = isset($opts['uriOptions']) ? $opts['uriOptions'] : [];
    $driOpts = isset($opts['driverOptions']) ? $opts['driverOptions'] : [];

    $this->server = new \MongoDB\Client($server, $uriOpts, $driOpts);

    if (isset($core[$msc]))
    {
      $core[$msc][$server] = $this->server;
    }
    else
    {
      $core[$msc] = [$server=>$this->server];
    }

    return $this->server;
  }

  public function get_db ($opts=[])
  {
    $core = \Lum\Core::getInstance();
    $mdk  = $this->mongo_db_key;
    $mdc  = $this->mongo_cache_dbs;

    if (isset($this->db))
    {
      return $this->db;
    }

    if (isset($this->build_opts) && count($opts) == 0)
    {
      $opts = $this->build_opts;
    }

    if (isset($opts['database']))
    {
      $db = $opts['database'];
    }
    elseif (property_exists($this, 'database') && isset($this->database))
    {
      $db = $this->database;
    }
    elseif (isset($core[$mdk]))
    {
      $db = $core[$mdk];
    }
    else
    {
      throw new \Exception("No database name could be found.");
    }

    if (isset($core[$mdc], $core[$mdc][$db]))
    {
      return $this->db = $core[$mdc][$db];
    }

    $server = $this->get_server($opts);

    $dbOpts = isset($opts['dbOpts']) ? $opts['dbOpts'] : [];

    $this->db = $server->selectDatabase($db, $dbOpts);
    if (isset($core[$mdc]))
    {
      $core[$mdc][$db] = $this->db;
    }
    else
    {
      $core[$mdc] = [$db=>$this->db];
    }
    return $this->db;
  }

  public function get_collection ($opts=[])
  {
    if (isset($this->data))
    {
      return $this->data;
    }

#    error_log("get_collection(".json_encode($opts).")");

    if (isset($this->build_opts) && count($opts) == 0)
    {
      $opts = $this->build_opts;
    }

#    error_log("get_collection::opts = ".json_encode($opts));

    if (isset($opts['collection']))
    {
      $collection = $opts['collection'];
    }
    elseif (isset($opts['table']))
    {
      $collection = $opts['table'];
    }
    elseif (property_exists($this, 'collection') && isset($this->collection))
    {
      $collection = $this->collection;
    }
    elseif (property_exists($this, 'table') && isset($this->table))
    {
      $collection = $this->table;
    }
    else
    {
      throw new \Exception("No collection name could be found.");
    }

    $db = $this->get_db($opts);

    if (isset($opts['collectionOpts']))
    {
      $colOpts = $opts['collectionOpts'];
    }
    elseif (isset($opts['tableOpts']))
    {
      $colOpts = $opts['tableOpts'];
    }
    else
    {
      $colOpts = [];
    }

    return $this->data = $db->selectCollection($collection, $colOpts);
  }
}

