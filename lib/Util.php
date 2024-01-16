<?php

namespace Lum\DB\Mongo;

use \MongoDB\BSON;
use \MongoDB\BSON\ObjectId;
use \MongoDB\Model\{BSONArray,BSONDocument};

class Util
{
  static function toArray ($data, array $opts=[])
  {
#    error_log("Util::toArray(data, ".json_encode($opts).")");

    $passthrough
      = isset($opts['passthrough']) 
      ? $opts['passthrough'] 
      : false;

    if ($passthrough
      && ($data instanceof BSONDocument || $data instanceof BSONArray))
    { // This is a super shortcut, we're passing through to getArrayCopy().
      return $data->getArrayCopy();
    }
 
    $doId  = isset($opts['objectId']) ? $opts['objectId'] : false;
    $idStr = isset($opts['idString']) ? $opts['idString'] : true;

    $doArr
      = isset($opts['bsonArray'])
      ? $opts['bsonArray']
      : true;

    $doObj
      = isset($opts['bsonObject'])
      ? $opts['bsonObject']
      : true;

    $recursive
      = isset($opts['recursive'])
      ? $opts['recursive']
      : false;

    $array = [];
    foreach ($data as $key => $val)
    {
      if ($doId && $val instanceof ObjectId)
      { 
        if ($idStr)
        { // Stringify ObjectId instance.
          $array[$key] = (string)$val;
        }
        else
        { // Use an Extended JSON representation.
          $array[$key] = ['$oid'=>(string)$val];
        }
      }
      elseif ($doArr && $val instanceof BSONArray)
      { // Serializing BSONArray specifically.
        $array[$key] 
          = $recursive 
          ? static::toArray($val, $opts) 
          : $val->getArrayCopy();
      }
      elseif ($doObj && $val instanceof BSONDocument)
      { // Serializing BSONDocument specifically.
        $array[$key] 
          = $recursive 
          ? static::toArray($val, $opts)
          : $val->getArrayCopy();
      }
      else
      { 
        if ($recursive && is_array($val))
        {
          $array[$key] = static::toArray($val, $opts);
        }
        else
        {
          $array[$key] = $val;
        }
      }
    }
    return $array;
  }

  /**
   * Convert input into JSON-serialized string.
   *
   * You'd think simply passing a BSON document to json_encode() would work,
   * but it sadly doesn't always. So this is a wrapper.
   */
  static function toJSON ($input, $opts=[])
  {
    $legacy = isset($opts['legacy'])
      ? $opts['legacy']
      : (!function_exists('\MongoDB\BSON\toCanonicalExtendedJSON'));

    $relaxed = isset($opts['relaxed'])
      ? $opts['relaxed']
      : (isset($opts['canonical']) ? !$opts['canonical'] : true);

    if (!is_iterable($input)) return $input;

    if ($input instanceof BSONDocument || $input instanceof BSONArray 
      || $input instanceof BSON\Type)
    {
      $bson = BSON\fromPHP($input);
      if ($legacy)
      { // Old legacy format, not recommended.
        $json = BSON\toJSON($bson);
      }
      elseif ($relaxed)
      {
        $json = BSON\toRelaxedExtendedJSON($bson);
      }
      else
      {
        $json = BSON\toCanonicalExtendedJSON($bson);
      }
    }
    elseif (is_array($input) || is_object($input))
    { // Assume it's a PHP Array or Object with keys in Extended JSON format.
      $json = json_encode($input);
    }
    elseif (is_string($input))
    { // If a string was passed, assume it is a JSON string already.
      $json = $input;
    }

    return $json;
  }

  static function idString ($id): string
  {
    if (is_string($id))
    { // Simplest, it's already a string.
      return $id;
    }
    elseif ($id instanceof ObjectId)
    { // The _id property in it's native form.
      return (string)$id;
    }
    elseif (is_object($id) && isset($id->_id))
    { // A document or sub-document with an _id property.
      return static::idString($id->_id);
    }
    elseif (is_array($id) && isset($id['_id']))
    { // An array document with an '_id' attribute.
      return static::idString($id['_id']);
    }
    elseif (is_array($id) && isset($id['$oid']))
    { // Strict JSON Representation.
      return static::idString($id['$oid']);
    }
    else
    { // Don't know what to do with that.
      throw new \Exception("Could not find an 'id' in the passed object: "
        .serialize($id));
    }
  }

  static function objectId ($id): ObjectId
  {
    if ($id instanceof ObjectId)
    { // It's already what we want.
      return $id;
    }
    elseif (is_object($id) && isset($id->_id) && $id->_id instanceof ObjectId)
    { // It's a document with an _id property.
      return $id->_id;
    }
    else
    { // It's something else, get the id string and return an ObjectId.
      return new ObjectId(static::idString($id));
    }
  }

  static function isSame($first, $second): bool
  {
    if ($first === $second) return true;
    $id1 = static::idString($first);
    $id2 = static::idString($second);
    return ($id1 === $id2);
  }

  static function isAssoc ($what): bool
  {
    return (($what instanceof BSONDocument)
      || (is_array($what) && !array_is_list($what)));
  }

  static function isLinear($what): bool
  {
    return (($what instanceof BSONArray)
      || (is_array($what) && array_is_list($what)));
  }

  static function canObjectId($id): bool
  {
    return ($id instanceof ObjectId
      || (static::isAssoc($id) && is_string($id['$oid']))
      || (is_string($id) && ctype_xdigit($id))
    );
  }

}
