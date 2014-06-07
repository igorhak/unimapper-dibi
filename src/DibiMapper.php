<?php

namespace UniMapper\Mapper;

use UniMapper\Exceptions\MapperException,
    UniMapper\Reflection\Entity\Property\Association\BelongsToMany,
    UniMapper\Reflection\Entity\Property\Association\HasMany,
    UniMapper\Reflection;

/**
 * Dibi mapper can be generally used to communicate between repository and
 * dibi database abstract layer.
 */
class DibiMapper extends \UniMapper\Mapper
{

    /** @var \DibiConnection $connection Dibi connection */
    protected $connection;

    /** @var array $modificators Dibi modificators */
    protected $modificators = array(
        "boolean" => "%b",
        "integer" => "%i",
        "string" => "%s",
        "NULL" => "NULL",
        "DateTime" => "%t",
        "array" => "%in",
        "double" => "%f"
    );

    public function __construct($name, \DibiConnection $connection)
    {
        parent::__construct($name);
        $this->connection = $connection;
    }

    public function mapValue(Reflection\Entity\Property $property, $data)
    {
        if ($data instanceof \DibiDateTime) {
            return new \DateTime($data);
        }
        return parent::mapValue($property, $data);
    }

    /**
     * Custom query
     *
     * @param string $resource
     * @param string $query
     * @param string $method
     * @param string $contentType
     * @param mixed  $data
     *
     * @return mixed
     *
     * @throws \UniMapper\Exceptions\MapperException
     */
    public function custom($resource, $query, $method, $contentType, $data)
    {
        if ($method === \UniMapper\Query\Custom::METHOD_RAW) {
            return $this->connection->query($query)->fetchAll();
        }

        throw new MapperException("Undefined custom method '" . $method . "' used!");
    }

    private function setConditions(\DibiFluent $fluent, array $conditions)
    {
        $i = 0;
        foreach ($conditions as $condition) {

            list($joiner, $query, $modificators) = $this->convertCondition($condition);

            array_unshift($modificators, $query);

            if ($joiner === "AND" || $i === 0) {
                call_user_func_array(array($fluent, "where"), $modificators);
            } else {
                call_user_func_array(array($fluent, "or"), $modificators);
            }
            $i++;
        }
    }

    private function convertCondition(array $condition)
    {
        if (is_array($condition[0])) {
            // Nested conditions

            list($nestedConditions, $joiner) = $condition;

            $i = 0;
            $query = "";
            $modificators = array();
            foreach ($nestedConditions as $nestedCondition) {
                list($conditionJoiner, $conditionQuery, $conditionModificators) = $this->convertCondition($nestedCondition);
                if ($i > 0) {
                    $query .= " " . $conditionJoiner . " ";
                }
                $query .= $conditionQuery;
                $modificators = array_merge($modificators, $conditionModificators);
                $i++;
            }
            return array(
                $joiner,
                "(" . $query . ")",
                $modificators
            );
        } else {
            // Simple condition

            list($columnName, $operator, $value, $joiner) = $condition;

            // Convert data type definition to dibi modificator
            $type = gettype($value);
            if ($type === "object") {
                $type = get_class($type);
            }
            if (!isset($this->modificators[$type])) {
                throw new MapperException("Unsupported value type " . $type . " given!");
            }

            // Get operator
            if ($operator === "COMPARE") {
                if ($this->connection->getDriver() instanceof \DibiPostgreDriver) {
                    $operator = "ILIKE";
                } elseif ($this->connection->getDriver() instanceof \DibiMySqlDriver) {
                    $operator = "LIKE";
                }
            }

            return array(
                $joiner,
                "%n %sql " . $this->modificators[$type],
                array(
                    $columnName,
                    $operator,
                    $value
                )
            );
        }
    }

    /**
     * Delete record by some conditions
     *
     * @param string $resource
     * @param array  $conditions
     */
    public function delete($resource, array $conditions)
    {
        $fluent = $this->connection->delete($resource);
        $this->setConditions($fluent, $conditions);
        $fluent->execute();
    }

    /**
     * Find single record identified by primary value
     *
     * @param string $resource
     * @param mixed  $primaryName
     * @param mixed  $primaryValue
     *
     * @return mixed
     */
    public function findOne($resource, $primaryName, $primaryValue)
    {
        return $this->connection->select("*")
            ->from("%n", $resource)
            ->where("%n = %s", $primaryName, $primaryValue) // @todo
            ->fetch();
    }

    /**
     * Find records
     *
     * @param string  $resource
     * @param array   $selection
     * @param array   $conditions
     * @param array   $orderBy
     * @param integer $limit
     * @param integer $offset
     * @param array   $associations
     *
     * @return array|false
     */
    public function findAll($resource, array $selection = [], array $conditions = [], array $orderBy = [], $limit = 0, $offset = 0, array $associations = [])
    {
        $fluent = $this->connection->select("[" . implode("],[", $selection) . "]")->from("%n", $resource);

        if (!empty($limit)) {
            $fluent->limit("%i", $limit);
        }

        if (!empty($offset)) {
            $fluent->offset("%i", $offset);
        }

        $this->setConditions($fluent, $conditions);

        foreach ($orderBy as $name => $direction) {
            $fluent->orderBy($name)->{$direction}();
        }

        $result = $fluent->fetchAll();
        if (count($result) === 0) {
            return false;
        }

        // Associations
        $associated = [];
        foreach ($associations as $propertyName => $association) {

            $primaryKeys = [];
            foreach ($result as $row) {
                $primaryKeys[] = $row->{$association->getPrimaryKey()};
            }

            if ($association instanceof BelongsToMany) {
                $associated[$propertyName] = $this->belongsToMany($association, $primaryKeys);
            } elseif ($association instanceof HasMany) {
                $associated[$propertyName] = $this->hasMany($association, $primaryKeys);
            } else {
                throw new MapperException("Unsupported association " . get_class($association) . "!");
            }
        }

        foreach ($result as $index => $item) {

            foreach ($associated as $propertyName => $associatedResult) {

                $primaryValue = $item->{$association->getPrimaryKey()}; // potencial future bug, association wrong?
                if (isset($associatedResult[$primaryValue])) {
                    $item[$propertyName] = $associatedResult[$primaryValue];
                }
            }
        }

        return $result;
    }

    private function belongsToMany(BelongsToMany $association, array $primaryKeys)
    {
        return $this->connection->select("*")
                ->from("%n", $association->getTargetResource())
                ->where("%n IN %l", $association->getTargetKey(), $primaryKeys)
                ->fetchAssoc($association->getTargetKey() . ",#");
    }

    private function hasMany(HasMany $association, array $primaryKeys)
    {
        $joinResult = $this->connection->select("%n,%n", $association->getJoinOriginKey(), $association->getJoinTargetKey())
            ->from("%n", $association->getJoinResource())
            ->where("%n IN %l", $association->getJoinOriginKey(), $primaryKeys)
            ->fetchAssoc($association->getJoinTargetKey() . "," . $association->getJoinOriginKey());

        $targetResult = $this->connection->select("*")
            ->from("%n", $association->getTargetResource())
            ->where("%n IN %l", $association->getTargetPrimaryKey(), array_keys($joinResult))
            ->fetchAssoc($association->getTargetPrimaryKey());

        $result = [];
        foreach ($joinResult as $targetKey => $join) {

            foreach ($join as $originKey => $data) {
                $result[$originKey][] = $targetResult[$targetKey];
            }
        }

        return $result;
    }

    public function count($resource, array $conditions)
    {
        $fluent = $this->connection->select("*")->from("%n", $resource);
        $this->setConditions($fluent, $conditions);
        return $fluent->count();
    }

    /**
     * Insert
     *
     * @param string $resource
     * @param array  $values
     *
     * @return mixed Primary value
     */
    public function insert($resource, array $values)
    {
        $this->connection->insert($resource, $values)->execute();
        return $this->connection->getInsertId();
    }

    /**
     * Update data by set of conditions
     *
     * @param string $resource
     * @param array  $values
     * @param array  $conditions
     */
    public function update($resource, array $values, array $conditions)
    {
        $fluent = $this->connection->update($resource, $values);
        $this->setConditions($fluent, $conditions);
        $fluent->execute();
    }

}