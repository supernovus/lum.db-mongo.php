<?php

namespace Lum\DB\Mongo;

use \MongoDB\BSON\ObjectId;

const GET_DOC_DEF_LOWERCASE = 1;
const GET_DOC_DEF_UPPERCASE = 2;
const GET_DOC_ALL_LOWERCASE = 3;
const GET_DOC_ALL_UPPERCASE = 4;

/**
 * A Trait that adds a public `getDoc()` method and a few related properties.
 * 
 * It is meant to be consumed by classes extending the `Model` abstract class.
 * Use in any class other than a child of `Model` will likely end in your
 * untimely demise. Don't do it!
 * 
 * Supports a few optional properties that your class can use to customize
 * behaviours of the `getDoc()` method.
 * 
 * ## `protected int $get_doc_force_case`
 * 
 *  A set of namespace constants are available:
 * 
 *  - `GET_DOC_DEF_LOWERCASE [1]` → Identifiers with no column to lowercase.
 *  - `GET_DOC_DEF_UPPERCASE [2]` → Identifiers with no column to uppercase.
 *  - `GET_DOC_ALL_LOWERCASE [3]` → All string identifiers to lowercase.
 *  - `GET_DOC_ALL_UPPERCASE [4]` → All string identifiers to uppercase.
 * 
 *  Default is `0`, which doesn't make any changes to the identifier values.
 * 
 * ## `protected ?string $get_doc_identity_delimiter`
 * 
 * If set, then this will be used as a delimiter to specify the column/field
 * name as a part of the identifier values.
 * 
 * For example, if set to `':'`, then an identity of `'email:me@test.com'`
 * would use `'email'` as the column name, and `me@test.com` as the actual
 * identifier value. 
 * 
 * The split/explode call is limited to two segments, so any further 
 * delimiters will simply be included in the identifier value.
 * 
 * Default is `null` which disables the feature.
 * 
 * ## `protected string $get_doc_fields_property`
 * 
 * Defines the name of an optional property that can contain one or more
 * columns/fields to check for the identifier value in.
 * 
 * The target property may be a `string` in the case of a single field/column,
 * or an `array` of strings if there are multiple.
 * 
 * Default is `get_doc_identity_fields`; if the target property is not defined, 
 * it defaults to `null` which will only include the primary key.
 */
trait GetDoc
{
  /**
   * A protected property that stores cached documents.
   * @var array
   */
  protected $get_doc_cache = [];

  // A cheap way to try to ensure the class extends `Model`.
  abstract public function findOne($find=[], $fo=[], $co=[]);

  /**
   * Get a document given an identifier value.
   * 
   * @param mixed $identity The identifier value.
   * 
   * This may come in a multitude of forms. 
   * 
   * @param mixed $column 
   * @param array $fo 
   * @param array $co 
   * @return mixed 
   */
  public function getDoc($identity, $column=null, $fo=[], $co=[])
  {
    if (is_array($identity))
    {
      $cacheKey = json_encode($identity);
    }
    else
    {
      $cacheKey = (string)$identity;
    }

    if (is_string($identity))
    {
      $forceCase = property_exists($this, 'get_doc_force_case')
        ? $this->get_doc_force_case
        : 0;

      $colChar = property_exists($this, 'get_doc_identity_delimiter')
        ? $this->get_doc_identity_delimiter
        : null;

      if ($colChar && str_contains($identity, $colChar))
      {
        $f = explode($colChar, $identity, 2);
        $column   = $f[0];
        $identity = $f[1];
      }
      elseif (is_null($column))
      { // No explicit column specified, use defaults only.
        if ($forceCase === GET_DOC_DEF_LOWERCASE)
        {
          $identity = strtolower($identity);
        }
        elseif ($forceCase === GET_DOC_DEF_UPPERCASE)
        {
          $identity = strtoupper($identity);
        }
      }

      if ($forceCase === GET_DOC_ALL_LOWERCASE)
      {
        $identity = strtolower($identity);
      }
      elseif ($forceCase === GET_DOC_ALL_UPPERCASE)
      {
        $identity = strtoupper($identity);
      }
    }
    elseif (isset($column))
    {
      $cacheKey = $column.'_'.$cacheKey;
    }

    if (isset($this->get_doc_cache[$cacheKey]))
    { // It's already been cached, return that copy.
      return $this->get_doc_cache[$cacheKey];
    }

    if (isset($column))
    { // A specific column was requested.
      return $this->getDocWith($cacheKey, $column, $identity, $fo, $co);
    }

    $fprop = property_exists($this, 'get_doc_fields_property')
      ? $this->get_doc_fields_property
      : 'get_doc_identity_fields';
  
    $fields = property_exists($this, $fprop)
      ? $this->$fprop
      : null;

    if (is_null($fields))
    {
      $fields = [$this->primary_key];
    }
    elseif (is_array($fields) && !in_array($this->primary_key, $fields))
    {
      array_unshift($fields, $this->primary_key);
    }
    elseif ($fields !== $this->primary_key)
    {
      $fields = [$this->primary_key, $fields];
    }

    foreach ($fields as $field)
    {
      $user = $this->getDocWith($cacheKey, $field, $identity, $fo, $co);
      if (isset($user))
      {
        return $user;
      }
    }

    // If we reached here, nothing matched.
    return null;
  }

  protected function getDocWith(
    string $cacheKey, 
    string $field, 
    mixed $value, 
    array $fo, 
    array $co)
  {
    if ($this->useObjectId($field) && Util::canObjectId($value))
    { // Make sure the value actually is an id.
      $value = Util::objectId($value);
    }

    return $this->get_doc_cache[$cacheKey]
         = $this->findOne([$field=>$value], $fo, $co);
  }

}