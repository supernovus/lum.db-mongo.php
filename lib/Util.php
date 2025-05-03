<?php

namespace Lum\DB\Mongo;

use DateTime;
use \MongoDB\BSON;
use \MongoDB\BSON\ObjectId;
use \MongoDB\Model\{BSONArray,BSONDocument};

class Util
{
  
  // This would be so much nicer in JS...
  static function ejsonStrEnc()
  {
    return [
      '$numberDecimal' => BSON\Decimal128::class,
      '$numberDouble'  => fn($v) => floatval($v),
      '$numberLong'    => BSON\Int64::class,
      '$numberInt'     => fn($v) => intval($v, 10),
      '$oid'           => BSON\ObjectId::class,
    ];
  }

  // Ditto obviously ;p
  static function ejsonKeyEnc()
  {
    return [
      '$maxKey' => BSON\MaxKey::class,
      '$minKey' => BSON\MinKey::class,
    ];
  }

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
   * If EJSON has been sent to a web service, we need to ensure
   * the Extended syntax gets converted back into MongoDB objects.
   * 
   * @param array $data   EJSON data decoded as a PHP array.
   * @param array $opts   (Optional) Reserved for future use.
   * @return mixed The value after all decoding has been done.
   */
  static function toMongoDB (array $data, array $opts=[])
  {
    if (isset($data['$binary']) 
      && is_array($data['$binary'])
      && is_string($data['$binary']['base64']))
    { // Limited support for this, no sub-types.
      return new BSON\Binary(base64_decode($data['$binary']['base64']));
    }

    if (isset($data['$date']))
    { // DateTime is easy
      if (is_string($data['$date']))
      {
        return new BSON\UTCDateTime(new DateTime($data['$date']));
      }
      elseif (is_array($data['$date']) 
        && is_string($data['$date']['$numberLong']))
      {
        $ts = new BSON\Int64($data['$date']['$numberLong']);
        return new BSON\UTCDateTime($ts);
      }
      else
      {
        error_log("Invalid date format: ".serialize($data));
        return null;
      }
    }

    if (isset($data['$regularExpression']) 
      && is_array($data['$regularExpression'])
      && is_string($data['$regularExpression']['pattern']))
    {
      $regex = $data['$regularExpression']['pattern'];
      $reopt = $data['$regularExpression']['options'] ?? '';
      return new BSON\Regex($regex, $reopt);
    }

    if (isset($data['$timestamp'])
      && is_array($data['$timestamp'])
      && is_int($data['$timestamp']['t'])
      && is_int($data['$timestamp']['i']))
    {
      return new BSON\Timestamp(
        $data['$timestamp']['i'],
        $data['$timestamp']['t']);
    }

    $encs = self::ejsonStrEnc();

    foreach ($encs as $code => $handler)
    {
      if (isset($data[$code]) && is_string($data[$code]))
      {
        if (is_callable($handler))
        {
          return $handler($data[$code]);
        }
        else
        {
          return new $handler($data[$code]);
        }
      }
    }

    $encs = self::ejsonKeyEnc();

    foreach ($encs as $code => $handler)
    {
      if (isset($data[$code]) && $data[$code] === 1)
      {
        return new $handler();
      }
    }

    // If we reached here, the array wasn't any built-in MongoDB type.

    foreach ($data as $key => $val)
    {
      if (is_array($val))
      {
        $data[$key] = self::toMongoDB($val, $opts);
      }
    }

    return $data;
  }

  /**
   * Convert input into JSON-serialized string.
   *
   * You'd think simply passing a BSON document to json_encode() would work,
   * but it sadly doesn't always. So this is a wrapper.
   * 
   * TODO: 
   * - Move to \MongoDB\BSON\Document for conversion purposes.
   * - Remove all legacy stuff (prep for driver/library v2.x)
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
