<?php

namespace Lum\DB\Mongo;

use \MongoDB\{
  BulkWriteResult,
  DeleteResult,
  InsertManyResult,
  InsertOneResult, 
  UpdateResult, 
};

/**
 * A class of static utility methods related to MongoDB Write Results.
 */
class ResultUtil
{
  static function results ($results, bool $isSave=false)
  {
    if (isset($results))
    {
      if (is_object($results) && is_callable([$results, 'isAcknowledged']))
      { // Output from a MongoDB write method.
        return $results;
      }
      elseif ($isSave && is_array($results) && isset($results[1]) && is_object($results[1])
        && is_callable([$results[1], 'isAcknowledged']))
      { // Output from Model::save()
        return $results[1];
      }
    }
  }

  static function ok ($results, 
    bool $unwrap=true, 
    bool $isSave=false): bool
  {
    if ($unwrap)
    {
      $results = static::results($results, $isSave);
    }
    if (isset($results))
    {
      return $results->isAcknowledged();
    }
    return false;
  }

  static function new_id ($results, bool $multiple=false)
  {
    $results = static::results($results, true);

    if (isset($results) && static::ok($results, false))
    {
      if ($results instanceof InsertOneResult)
      {
        $id = $results->getInsertedId();
        return $multiple ? [$id] : $id;
      }
      elseif ($results instanceof UpdateResult)
      {
        $id = $results->getUpsertedId();
        return $multiple ? [$id] : $id;
      }
      
      if ($multiple)
      { 
        if ($results instanceof InsertManyResult)
        {
          return $results->getInsertedIds();
        }
        elseif ($results instanceof BulkWriteResult)
        {
          $iids = $results->getInsertedIds();
          $uids = $results->getUpsertedIds();
          return array_merge($iids, $uids);
        }
      }
    }

    return $multiple ? [] : null;
  }

  static function new_ids ($results, bool $multiple=true)
  {
    return static::new_id($results, $multiple);
  }

  static function new_count ($results): int
  {
    $results = static::results($results, true);

    if (isset($results) && static::ok($results, false))
    {
      if ($results instanceof UpdateResult)
      {
        return $results->getUpsertedCount();
      }
      if ( $results instanceof InsertOneResult
        || $results instanceof InsertManyResult)
      {
        return $results->getInsertedCount();
      }
      if ($results instanceof BulkWriteResult)
      {
        $ic = $results->getInsertedCount();
        $uc = $results->getUpsertedCount();
        return $ic+$uc;
      }
    }

    return 0;
  }

  static function deleted_count ($results): int
  {
    $results = static::results($results, true);

    if (isset($results) && static::ok($results, false)
      && $results instanceof DeleteResult
      || $results instanceof BulkWriteResult)
    {
      return $results->getDeletedCount();
    }

    return 0;
  }

  static function is_deleted($results): bool
  {
    $count = static::deleted_count($results);
    return ($count > 0);
  }

}
