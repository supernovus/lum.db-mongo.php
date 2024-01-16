<?php

namespace Lum\DB\Mongo;

/**
 * Utility functions for MongoDB Patches.
 * 
 * TODO: Currently does not support Aggregate pipelines.
 */
class PatchUtils
{
  const UPDATE_OPS =
  [
    '$set', '$unset', '$rename', '$setOnInsert',       // Main
    '$inc', '$min', '$max', '$mul',                    // Numeric
    '$push', '$pop', '$pull', '$pullAll', '$addToSet', // Array
    '$bit', '$currentDate',                            // More
  ];

  const EXACT_MATCH = 0;
  const STARTS_WITH = 1;
  const CONTAINS    = 2;
  const REGEX       = 3;

  /**
   * Check if an array has any of our supported patch operators.
   */
  public static function isPatch (array $array, bool $aggregate=false): bool
  {
    foreach (static::UPDATE_OPS as $op)
    {
      if (isset($array[$op]) && is_array($array[$op]))
      {
        return true;
      }
    }

    return false;
  }

  /**
   * Fix attempts to patch reserved/readonly fields.
   *
   * By default we look for the field name using str_starts_with()
   * We can use str_contains() or preg_match() instead using options.
   * We can also simply check for the exact field name, which is not really
   * recommended as nested fields will ruin your day.
   *
   * @param array &$patch    The patch document we're working on.
   * @param array $reserved  The fields that are reserved.
   *
   * @param int $mode  The mode to match with:
   *
   *
   * @param array|int $opts  Options for advanced features.
   * 
   *  'fatal' (bool)   Throw an exception if reserved fields found?  [false]
   *  'log'   (bool)   Report removed fields to error log?           [true]
   *  'all'   (string) Key to set to true if all fields removed.     ['*']
   *  'mode'  (int)    The mode to match reserved fields using.      [1]
   * 
   *  You can use one of the class constants to set the 'mode' option:
   * 
   *    PatchUtils::EXACT_MATCH [0]
   *    PatchUtils::STARTS_WITH [1] (default)
   *    PatchUtils::CONTAINS    [2]
   *    PatchUtils::REGEX       [3]
   *
   * @return array Associative array of fields removed in each op.
   * 
   * The basic format is simple:
   * 
   *   [$op=>[$field=>$value]]
   * 
   * If all fields in an op were removed, then the 'all' key will be
   * set to ``true``. A simple example:
   * 
   *   [
   *     "$set"=>["hash"=>"foo","token"=>"bar","*"=>true],
   *     "$unset"=>[]
   *   ]
   * 
   * Will be an empty array if nothing was removed.
   * 
   */
  public static function removeReserved (
    array &$patch,
    array $reserved,
    int   $mode = self::STARTS_WITH,
    array $opts = [],
  ) : array
  {
    $fatal = $opts['fatal'] ?? false;
    $log   = $opts['log']   ?? true;
    $all   = $opts['all']   ?? '*';
    
    if (isset($opts['mode']))
    {
      $mode = intval($opts['mode']);
    }

    $status = [];
    $removeOps = [];

    foreach (static::UPDATE_OPS as $op)
    {
      if (isset($patch[$op]) && is_array($patch[$op]))
      {
        $removeFields = [];
        foreach ($patch[$op] as $key => $val)
        {
          $found = false;
          if ($mode === self::EXACT_MATCH)
          { // Exact match mode is dead simple, but not very secure.
            $found = in_array($key, $reserved);
          }
          else
          { // One of the modes that needs to loop through the reserved keys.
            foreach ($reserved as $rkey)
            {
              if ($mode === self::STARTS_WITH)
              {
                $found = str_starts_with($key, $rkey);
              }
              elseif ($mode === self::CONTAINS)
              {
                $found = str_contains($key, $rkey);
              }
              elseif($mode === self::REGEX)
              {
                $found = preg_match($rkey, $key);
              }
            } // foreach reserved
          }
          if ($found)
          { // The key matched a reserved field.
            $msg = "Reserved field '$key' found in '$op'";
            if ($fatal)
            {
              throw new MongoPatchException($msg);
            }
            if ($log)
            {
              error_log($msg);
            }
            $removeFields[$key] = $val;
          }
        } // foreach array[op]

        if (count($removeFields) > 0)
        { // Fields were set to be removed.
          foreach ($removeFields as $key => $val)
          {
            unset($patch[$op][$key]);
          }

          $status[$op] = $removeFields;

          if (count($patch[$op]) == 0)
          { // Removed all fields for this operator.
            $removeOps[] = $op;
            $status[$op][$all] = true;
          }
        }

      } // if op
    } // foreach ops

    if (count($removeOps) > 0)
    { // Ops were set to be moved.
      foreach ($removeOps as $op)
      {
        unset($patch[$op]);
      }
    }

    return $status;
  } // removeReserved()

} // class PatchUtils

/**
 * An exception specifically for PatchUtils errors.
 */
class MongoPatchException extends \Exception {}
