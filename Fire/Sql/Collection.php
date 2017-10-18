<?php

namespace Fire\Sql;

use PDO;
use DateTime;
use Fire\Sql\Statement;
use Fire\Sql\Filter;

class Collection
{

    /**
     * The connection to the database.
     * @var PDO
     */
    private $_pdo;

    /**
     * The name of the collection.
     * @var string
     */
    private $_name;

    /**
     * Creates an instance of a new collection.
     * @param String $name The name of the collection
     * @param PDO $pdo The connection to the database
     */
    public function __construct($name, PDO $pdo)
    {
        $this->_name = $name;
        $this->_pdo = $pdo;
    }

    public function delete($id)
    {

    }

    public function find($filter = null, $offset = 0, $length = 10, $reverseOrder = true)
    {
        if (is_string($filter)) {
            return $this->_getObject($filter);
        } else if (is_object($filter)) {
            return $this->_getObjectsByFilter($filter, $offset, $length, $reverseOrder);
        } else if (is_null($filter)) {
            //return $this->_query->getAllDocuments($offset, $length, $reverseOrder);
        }
        return null;
    }

    public function insert($object)
    {
        return $this->_upsert($object, null);
    }

    public function update($id, $object)
    {
        $this->_upsert($object, $id);
    }

    private function _commitObject($id)
    {
        $update = Statement::get(
            'UPDATE_OBJECT_TO_COMMITTED',
            [
                '@id' => $this->_quote($id)
            ]
        );
        $this->_exec($update);
    }

    private function _exec($statement)
    {
        return $this->_pdo->exec($statement);
    }

    private function _generateAlphaToken()
    {
        $timestamp = strtotime($this->_generateTimestamp());
        $characters = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
        shuffle($characters);
        $randomString = '';
        foreach (str_split((string) $timestamp) as $num) {
            $randomString .= $characters[$num];
        }
        return $randomString;
    }

    private function _generateRevisionNumber()
    {
        return rand(1000001, 9999999);
    }

    private function _generateTimestamp()
    {
        $time = microtime(true);
        $micro = sprintf('%06d', ($time - floor($time)) * 1000000);
        $date = new DateTime(date('Y-m-d H:i:s.' . $micro, $time));
        return $date->format("Y-m-d H:i:s.u");
    }

    private function _generateUniqueId()
    {
        $rand = uniqid(rand(10, 99));
        $time = microtime(true);
        $micro = sprintf('%06d', ($time - floor($time)) * 1000000);
        $date = new DateTime(date('Y-m-d H:i:s.' . $micro, $time));
        return sha1($date->format('YmdHisu'));
    }

    private function _getObject($id, $revision = null)
    {
        if ($revision === null) {
            $select = Statement::get(
                'GET_CURRENT_OBJECT',
                [
                    '@id' => $this->_quote($id)
                ]
            );
            $record = $this->_query($select)->fetch();
            if ($record) {
                return json_decode($record['obj']);
            }

            return null;
        }
    }

    private function _getObjectOrigin($id)
    {
        $select = Statement::get(
            'GET_OBJECT_ORIGIN_DATE',
            [
                '@id' => $this->_quote($id)
            ]
        );
        $record = $this->_query($select)->fetch();
        return ($record) ? $record['updated'] : null;
    }

    private function _getObjectsByFilter(Filter $filterQuery, $offset, $length, $reverseOrder)
    {
        $filter = $filterQuery->filter();

        var_dump($filter);
        $props = [];
        $filters = []
        foreach ($filter->filters as $applyFilter) {
            $props[] = $applyFilter->prop;
        }

        $props = array_unique($props);
        $joins = [];
        foreach ($props as $prop)
        {
            $asTbl = $this->_generateAlphaToken();
            $joins[] =
                'JOIN(' .
                    'SELECT id, val as ' . $prop . ' ' .
                    'FROM \'__index\' ' .
                    'WHERE prop = \'' . $prop . '\'' .
                ') AS ' . $asTbl . ' ' .
                'ON A.id = ' . $asTbl . '.id';
        }

        $select = Statement::get(
            'GET_OBJECTS_BY_FILTER',
            [
                '@columns' => (count($props) > 0) ? ', ' . implode($props, ', ') : '',
                '@joinColumns' => (count($joins) > 0) ? implode($joins, ' ') . ' ' : '',
                '@collection' => $this->_quote($this->_name),
                '@type' => $this->_quote($filter->type),
                '@filters' => ($filters) ? '(' . $filters . ') ' : '',
                '@order' => $filter->order,
                '@reverse' => ($filter->reverse) ? 'DESC' : 'ASC',
                '@limit' => $filter->length,
                '@offset' => $filter->offset
            ]
        );
        echo $select;



        exit();

        // $filters = '';
        // $i = 0;
        // foreach ($filterModel->filters as $filter) {
        //     $filters .= Statement::get(
        //         'GET_LOGIC_FILTER',
        //         [
        //             '@expression' => ($i === 0) ? '' :  $filter->expression . ' ',
        //             '@comparison' => $filter->comparison,
        //             '@prop' => $this->_quote($filter->prop),
        //             '@val' => $this->_quote($filter->val)
        //         ]
        //     );
        //     if (count($filterModel->filters) > $i + 1) {
        //         $filters .= ' ';
        //     }
        //     $i++;
        // }
        // $select = Statement::get(
        //     'GET_OBJECTS_BY_FILTER',
        //     [
        //         '@collection' => $this->_quote($this->_name),
        //         '@filters' => $filters,
        //         '@order' => $filterModel->order,
        //         '@reverse' => ($filterModel->reverse) ? 'DESC' : 'ASC',
        //         '@offset' => $filterModel->offset,
        //         '@length' => $filterModel->length,
        //     ]
        // );
    }

    private function _isPropertyIndexable($property)
    {
        $indexBlacklist = ['__id', '__revision', '__updated', '__origin'];
        return !in_array($property, $indexBlacklist);
    }

    public function _isValueIndexable($value)
    {
        return (
            is_string($value)
            || is_null($value)
            || is_bool($value)
            || is_integer($value)
        );
    }

    private function _query($statement)
    {
        return $this->_pdo->query($statement);
    }

    private function _quote($value)
    {
        return $this->_pdo->quote($value);
    }

    private function _updateObjectIndexes($object)
    {
        //delete all indexed references to this object
        $update = Statement::get(
            'DELETE_OBJECT_INDEX',
            [
                '@id' => $this->_quote($object->__id)
            ]
        );
        //parse each property of the object an attempt to index each value
        foreach (get_object_vars($object) as $property => $value) {
            if (
                $this->_isPropertyIndexable($property)
                && $this->_isValueIndexable($value)
            ) {
                $insert = Statement::get(
                    'INSERT_OBJECT_INDEX',
                    [
                        '@type' => $this->_quote('value'),
                        '@prop' => $this->_quote($property),
                        '@val' => $this->_quote($value),
                        '@collection' => $this->_quote($this->_name),
                        '@id' => $this->_quote($object->__id),
                        '@origin' => $this->_quote($object->__origin)
                    ]
                );
                $update .= $insert;
            }
        }
        //add the object registry index
        $insert = Statement::get(
            'INSERT_OBJECT_INDEX',
            [
                '@type' => $this->_quote('registry'),
                '@prop' => $this->_quote(''),
                '@val' => $this->_quote(''),
                '@collection' => $this->_quote($this->_name),
                '@id' => $this->_quote($object->__id),
                '@origin' => $this->_quote($object->__origin)
            ]
        );
        $update .= $insert;
        //execute all the sql to update indexes.
        $this->_exec($update);
    }

    private function _upsert($object, $id = null)
    {
        $object = $this->_writeObjectToDb($object, $id);
        $this->_updateObjectIndexes($object);
        $this->_commitObject($object->__id);
        return $object;
    }

    private function _writeObjectToDb($object, $id)
    {
        $objectId = (!is_null($id)) ? $id : $this->_generateUniqueId();
        $origin = $this->_getObjectOrigin($objectId);
        $object->__id = $objectId;
        $object->__revision = $this->_generateRevisionNumber();
        $object->__updated = $this->_generateTimestamp();
        $object->__origin = ($origin) ? $origin : $object->__updated;

        //insert into database
        $insert = Statement::get(
            'INSERT_OBJECT',
            [
                '@collection' => $this->_quote($this->_name),
                '@id' => $this->_quote($object->__id),
                '@revision' => $this->_quote($object->__revision),
                '@committed' => $this->_quote(0),
                '@updated' => $this->_quote($object->__updated),
                '@origin' => $this->_quote($object->__origin),
                '@obj' => $this->_quote(json_encode($object))
            ]
        );
        $this->_exec($insert);
        return $object;
    }
}
