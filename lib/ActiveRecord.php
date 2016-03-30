<?php
/**
 * ActiveRecord.php
 *
 * @package axiles89\sharding
 * @date: 01.03.2016 16:59
 * @author: Kyshnerev Dmitriy <dimkysh@mail.ru>
 */

namespace axiles89\sharding;


use yii\base\InvalidConfigException;
use yii\db\BaseActiveRecord;
use yii\db\Expression;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;

/**
 * Class ActiveRecord
 * @package axiles89\sharding
 */
abstract class ActiveRecord extends BaseActiveRecord
{
    /**
     * ���� �� �������� ������ �������
     * @throws InvalidConfigException
     */
    public static function shardingColumn() {
        throw new InvalidConfigException('The shardingColumn() method of sharding db ActiveRecord has to be implemented by child classes and return string.');
    }

    /**
     * ��� �������� (���� �������� � �������)
     * @throws InvalidConfigException
     */
    public static function shardingType() {
        throw new InvalidConfigException('The shardingType() method of sharding db ActiveRecord has to be implemented by child classes and return string.');
    }

    /**
     * @return null|object
     * @throws InvalidConfigException
     */
    public static function getDb() {
        return \Yii::$app->get('sharding');
    }

    /**
     * ��������� ����� ������� �� ��������� ('PostTag' => 'post_tag')
     * @return string
     */
    public static function tableName() {
        return '{{%' . Inflector::camel2id(StringHelper::basename(get_called_class()), '_') . '}}';
    }

    /**
     * @throws InvalidConfigException
     */
    public static function primaryKey() {
        throw new InvalidConfigException('The primaryKey() method of sharding db ActiveRecord has to be implemented by child classes.');
    }

    /**
     * @return object
     * @throws InvalidConfigException
     */
    public static function find() {
        return \Yii::createObject(ActiveQuery::className(), [get_called_class()]);
    }

    /**
     * @param $shardDb
     * @return mixed
     * @throws InvalidConfigException
     */
    public static function getTableSchema($shardDb) {
        $schema = \Yii::$app->get($shardDb)->getSchema()->getTableSchema(static::tableName());
        if ($schema !== null) {
            return $schema;
        } else {
            throw new InvalidConfigException("The table does not exist in {$shardDb}: " . static::tableName());
        }
    }

    /**
     * ��������� ������� ��� ����������� ������� findOne, findAll
     * @param mixed $condition
     * @return mixed
     * @throws InvalidConfigException
     */
    protected static function findByCondition($condition) {
        $query = static::find();

        if (!ArrayHelper::isAssociative($condition)) {
            // query by primary key
            $primaryKey = static::primaryKey();

            if (isset($primaryKey[0])) {
                $pk = $primaryKey[0];
                if (!empty($query->join) || !empty($query->joinWith)) {
                    $pk = static::tableName() . '.' . $pk;
                }
                $condition = [$pk => $condition];
            } else {
                throw new InvalidConfigException('"' . get_called_class() . '" must have a primary key.');
            }
        }

        return $query->andWhere($condition);
    }

    /**
     * @param bool|true $runValidation
     * @param null $attributes
     * @return bool
     * @throws InvalidConfigException
     */
    public function insert($runValidation = true, $attributes = null) {
        if ($runValidation && !$this->validate($attributes)) {
            \Yii::info('Model not inserted due to validation error.', __METHOD__);
            return false;
        }

        if (!$this->beforeSave(true)) {
            return false;
        }

        $values = $this->getDirtyAttributes($attributes);

        if (!isset($values[static::shardingColumn()]) or !$values[static::shardingColumn()]) {
            throw new InvalidConfigException('Please set the sharding columns.');
        }

        $db = static::getDb();
        if (!isset($db->shard[static::shardingType()])) {
            throw new InvalidConfigException('The sharding component for this Active Record model not found');
        }

        // �������� ����� ����������� ����� ��� insert ����� ������
        $shardType = $db->shard[static::shardingType()];
        $coordinator = \Yii::$app->$shardType['coordinator'];
        $shardDb = $coordinator->getShard($shardType['db'], $values[static::shardingColumn()]);

        if (!$shardDb) {
            throw new InvalidConfigException('The shard for this query not found');
        }

        if (($primaryKeys = \Yii::$app->get($shardDb)->schema->insert($this->tableName(), $values)) === false) {
            return false;
        }

        // ��������� �������� ���������� ����� � ������
        foreach ($primaryKeys as $name => $value) {
            $id = $this->getTableSchema($shardDb)->columns[$name]->phpTypecast($value);
            $this->setAttribute($name, $id);
            $values[$name] = $id;
        }

        // ��������� old attributes
        $changedAttributes = array_fill_keys(array_keys($values), null);
        $this->setOldAttributes($values);
        $this->afterSave(true, $changedAttributes);

        return true;
    }

    /**
     * @param bool|true $runValidation
     * @param null $attributeNames
     * @return bool|int
     * @throws \yii\db\StaleObjectException
     */
    public function update($runValidation = true, $attributeNames = null) {
        if ($runValidation && !$this->validate($attributeNames)) {
            Yii::info('Model not updated due to validation error.', __METHOD__);
            return false;
        }

        return $this->updateInternal($attributeNames);
    }


    /**
     * ���������� ��� ���������� ���� ���������, � ����� � updateInternal ������ ��� ���������� ����������� ���������� ������
     * @param array $attributes
     * @param string $condition
     * @param array $params
     * @return mixed
     * @throws InvalidConfigException
     */
    public static function updateAll($attributes, $condition = '', $params = []) {
        $db = static::getDb();

        if (!isset($db->shard[static::shardingType()])) {
            throw new InvalidConfigException('The sharding component for this Active Record model not found');
        }

        // �������� ������ ���� ��� ��� ����� ��� ������� ���� ����������
        $valueKey = HelperCoordinator::getInstance()->getData($condition, $params, static::shardingColumn());
        $shardType = $db->shard[static::shardingType()];
        $coordinator = \Yii::$app->$shardType['coordinator'];
        $shardDb = $coordinator->getShard($shardType['db'], $valueKey);

        if (!$shardDb) {
            $shardDb = $shardType['db'];
        }

        // ������ ������ ��� ������� �����
        $builder = static::getDb()->getQueryBuilder($shardDb);
        list($sql, $params) = $builder->update(static::tableName(), $attributes, $condition, $params);
        $command = static::getDb()->createCommand($shardDb, $sql, $params);

        return $command->execute();

    }

    /**
     * @param array $counters
     * @param string $condition
     * @param array $params
     * @return mixed
     * @throws InvalidConfigException
     */
    public static function updateAllCounters($counters, $condition = '', $params = []) {
        $db = static::getDb();

        if (!isset($db->shard[static::shardingType()])) {
            throw new InvalidConfigException('The sharding component for this Active Record model not found');
        }

        // �������� ������ ���� ��� ��� ����� ��� ������� ���� ����������
        $helper = HelperCoordinator::getInstance()->getData($condition, $params, static::shardingColumn());
        $shardType = $db->shard[static::shardingType()];
        $coordinator = \Yii::$app->$shardType['coordinator'];
        $shardDb = $coordinator->getShard($shardType['db'], $helper);

        if (!$shardDb) {
            $shardDb = $shardType['db'];
        }

        $n = 0;

        foreach ($counters as $name => $value) {
            $counters[$name] = new Expression("[[$name]]+:bp{$n}", [":bp{$n}" => $value]);
            $n++;
        }

        // ������ ������ ��� ������� �����
        $builder = static::getDb()->getQueryBuilder($shardDb);
        list($sql, $params) = $builder->update(static::tableName(), $counters, $condition, $params);
        $command = static::getDb()->createCommand($shardDb, $sql, $params);

        return $command->execute();
    }

    /**
     * ���������� ��� �������� ���� ���������, � ����� ��� ������ delete � ���������� ActiveRecord
     * @param string $condition
     * @param array $params
     * @return mixed
     * @throws InvalidConfigException
     */
    public static function deleteAll($condition = '', $params = []) {
        $db = static::getDb();

        if (!isset($db->shard[static::shardingType()])) {
            throw new InvalidConfigException('The sharding component for this Active Record model not found');
        }

        // �������� ������ ���� ��� ��� ����� ��� ������� ���� ����������
        $valueKey = HelperCoordinator::getInstance()->getData($condition, $params, static::shardingColumn());
        $shardType = $db->shard[static::shardingType()];
        $coordinator = \Yii::$app->$shardType['coordinator'];
        $shardDb = $coordinator->getShard($shardType['db'], $valueKey);

        if (!$shardDb) {
            $shardDb = $shardType['db'];
        }

        // ������ ������ ��� ������� �����
        $builder = static::getDb()->getQueryBuilder($shardDb);
        list($sql, $params) = $builder->delete(static::tableName(), $condition, $params);
        $command = static::getDb()->createCommand($shardDb, $sql, $params);

        return $command->execute();
    }


    /**
     * @throws InvalidConfigException
     */
    public function attributes() {
        throw new InvalidConfigException('The attributes() method of sharding db ActiveRecord has to be implemented by child classes.');
    }

}