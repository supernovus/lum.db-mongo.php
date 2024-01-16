<?php

namespace Lum\DB\Mongo;

class Results implements \Iterator, \Countable
{
  use \Lum\DB\ResultToArray, \Lum\Data\JSON;

  public $parent;
  protected $find_query = [];
  protected $find_opts  = [];
  protected $class_opts = [];
  protected $cursor;
  protected $iterator;

  public function __construct ($opts=[])
  {
    $this->class_opts = $opts;

    if (isset($opts['parent']))
    {
      $this->parent = $opts['parent'];
    }
    if (isset($opts['find']))
    {
      $this->find_query = $opts['find'];
    }
    if (isset($opts['findopts']))
    {
      $this->find_opts = $opts['findopts'];
    }
  }

  public function getCursor ()
  {
    if (!isset($this->cursor))
    {
      $collection = $this->parent->get_collection();
      $this->cursor = $collection->find($this->find_query, $this->find_opts);
    }
    return $this->cursor;
  }

  public function getIterator ()
  {
    if (!isset($this->iterator))
    {
      $this->iterator = new \IteratorIterator($this->getCursor());
    }
    return $this->iterator;
  }

  public function count (): int
  {
    return $this->rowCount($this->parent->results_use_filtered_count);
  }

  public function rowCount ($filterCount=false)
  {
    $count_opts = [];
    if ($filterCount)
    {
      foreach (['limit','skip'] as $opt)
      {
        if (isset($this->find_opts[$opt]))
        {
          $count_opts[$opt] = $this->find_opts[$opt];
        }
      }
    }
    return $this->parent->rowCount($this->find_query, $count_opts);
  }

  public function rewind (): void
  {
    if (isset($this->iterator))
    { // MongoDB doesn't like that, restart the iteration.
      unset($this->iterator, $this->cursor);
    }
    $this->getIterator()->rewind();
  }

  public function current (): mixed
  {
    return $this->parent->wrapRow($this->getIterator()->current(), $this->class_opts);
  }

  public function next (): void
  {
    $this->getIterator()->next();
  }

  public function key (): mixed
  {
    return $this->getIterator()->key();
  }

  public function valid (): bool
  {
    return $this->getIterator()->valid();
  }

}