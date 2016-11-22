<?php
namespace ModelHistory\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Query;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\I18n\Date;
use Cake\I18n\Time;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Cake\Validation\Validator;
use ModelHistory\Model\Entity\ModelHistory;
use ModelHistory\Model\Filter\Filter;

/**
 * ModelHistory Model
 */
class ModelHistoryTable extends Table
{

    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config)
    {
        $this->table('model_history');
        $this->displayField('id');
        $this->primaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('Users', [
            'foreignKey' => 'user_id'
        ]);
        $this->schema()->columnType('data', 'json');
        $this->schema()->columnType('context', 'json');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator)
    {
        $validator->add('data', 'custom', [
            'rule' => function ($value, $context) {
                if ($context['data']['action'] != ModelHistory::ACTION_COMMENT) {
                    return true;
                }
                return !empty($value['comment']);
            },
            'message' => __d('model_history', 'comment_empty')
        ]);
        return $validator;
    }

    /**
     * Add a record to the ModelHistory
     *
     * @param EntityInterface $entity Entity
     * @param string $action One of ModelHistory::ACTION_*
     * @param string $userId User ID to assign this history entry to
     * @param array $options Additional options
     * @return ModelHistory
     */
    public function add(EntityInterface $entity, $action, $userId = null, array $options = [])
    {
        $options = Hash::merge([
            'dirtyFields' => null,
            'data' => null
        ], $options);

        if (!$options['data']) {
            $options['data'] = $entity->toArray();
        }

        $saveFields = [];
        $fieldConfig = TableRegistry::get($entity->source())->getFields();

        foreach ($fieldConfig as $fieldName => $data) {
            if ($data['searchable'] === true && isset($options['data'][$fieldName])) {
                if ($data['obfuscated'] === true) {
                    $options['data'][$fieldName] = '****************';
                }
                $saveFields[$fieldName] = $options['data'][$fieldName];
            }
        }

        if (empty($saveFields)) {
            return false;
        }
        $options['data'] = $saveFields;

        if ($action === ModelHistory::ACTION_DELETE) {
            $options['data'] = $entity->toArray();
        }

        if ($action === ModelHistory::ACTION_UPDATE && $options['dirtyFields']) {
            $newData = [];
            foreach ($options['dirtyFields'] as $field) {
                if (isset($options['data'][$field])) {
                    $newData[$field] = $options['data'][$field];
                }
            }
            $options['data'] = $newData;
        }

        $context = null;
        if (method_exists($entity, 'getHistoryContext')) {
            $context = $entity->getHistoryContext();
        }
        $contextSlug = null;
        if (method_exists($entity, 'getHistoryContextSlug')) {
            $contextSlug = $entity->getHistoryContextSlug();
        }
        $contextType = null;
        if (method_exists($entity, 'getHistoryContextType')) {
            $contextType = $entity->getHistoryContextType();
        }

        $entry = $this->newEntity([
            'model' => $this->getEntityModel($entity),
            'foreign_key' => $entity->id,
            'action' => $action,
            'data' => $options['data'],
            'context_type' => $contextType,
            'context' => $context,
            'context_slug' => $contextSlug,
            'user_id' => $userId,
            'revision' => $this->getNextRevisionNumberForEntity($entity)
        ]);
        $this->save($entry);
        return $entry;
    }

    /**
    * Transforms data fields to human readable form
    *
    * @param  array   $history  Data
    * @param  string  $model    Model name
    * @return array
    */
    protected function _transformDataFields(array $history, $model)
    {
        $fieldConfig = TableRegistry::get($model)->getFields();
        foreach ($history as $index => $entity) {
            $entityData = $entity->data;
            foreach ($entityData as $field => $value) {
                if (!isset($fieldConfig[$field]) || $fieldConfig[$field]['searchable'] !== true) {
                    continue;
                }
                if (is_callable($fieldConfig[$field]['displayParser'])) {
                    $callback = $fieldConfig[$field]['displayParser'];
                    $entityData[$field] = $callback($field, $value);
                    continue;
                }
                $filterClass = Filter::get($fieldConfig[$field]['type']);
                $entityData[$field] = $filterClass->display($field, $value, $model);
            }
            $history[$index]->data = $entityData;
        }
        return $history;
    }

    /**
     * Add comment
     *
     * @param EntityInterface $entity Entity to add the comment to
     * @param string $comment Comment
     * @param string $userId User which wrote the note
     * @return ModelHistory
     */
    public function addComment(EntityInterface $entity, $comment, $userId = null)
    {
        return $this->add($entity, ModelHistory::ACTION_COMMENT, $userId, [
            'data' => [
                'comment' => $comment
            ]
        ]);
    }

    /**
     * Handles the revision sequence
     *
     * @param EntityInterface $entity Entity to get the revision number for
     * @return int
     */
    public function getNextRevisionNumberForEntity(EntityInterface $entity)
    {
        $revision = 1;
        $last = $this->find()
            ->select('revision')
            ->where([
                'model' => $this->getEntityModel($entity),
                'foreign_key' => $entity->id
            ])
            ->order(['revision DESC'])
            ->hydrate(false)
            ->first();

        if (isset($last['revision'])) {
            $revision = $last['revision'] + 1;
        }
        return $revision;
    }

    /**
     * Extracts the string to be saved to the model field from an entity
     *
     * @param EntityInterface $entity Entity
     * @return string
     */
    public function getEntityModel(EntityInterface $entity)
    {
        $source = $entity->source();
        if (substr($source, -5) == 'Table') {
            $source = substr($source, 0, -5);
        }
        return $source;
    }

    /**
     * getEntityWithHistory function
     *
     * @param string $model Model
     * @param string $foreignKey ForeignKey
     * @param array $options Options
     * @return void
     */
    public function getEntityWithHistory($model, $foreignKey, array $options = [])
    {
        $Table = TableRegistry::get($model);
        $userFields = $Table->getUserNameFields();
        $options = Hash::merge([
            'contain' => [
                'ModelHistory' => [
                    'fields' => [
                        'id',
                        'user_id',
                        'action',
                        'revision',
                        'created',
                        'model',
                        'foreign_key',
                        'data'
                    ],
                    'sort' => ['ModelHistory.revision DESC'],
                    'Users' => [
                        'fields' => $userFields
                    ]
                ]
            ]
        ], $options);
        $entity = $Table->get($foreignKey, $options);

        return $entity;
    }

    /**
     * get Model History
     *
     * @param string $model         model name
     * @param string $foreignKey    foreign key
     * @param int    $itemsToShow   Amount of items to be shown
     * @param int    $page          Current position
     * @return array
     */
    public function getModelHistory($model, $foreignKey, $itemsToShow, $page, array $conditions = [])
    {
        $conditions = Hash::merge([
            'model' => $model,
            'foreign_key' => $foreignKey
        ], $conditions);

        $history = $this->find()
            ->where($conditions)
            ->order(['revision' => 'DESC'])
            ->contain(['Users'])
            ->limit($itemsToShow)
            ->page($page)
            ->toArray();

        return $this->_transformDataFields($history, $model);
    }

    /**
     * get Model History entries count
     *
     * @param string $model         model name
     * @param string $foreignKey    foreign key
     * @return int
     */
    public function getModelHistoryCount($model, $foreignKey, array $conditions = [])
    {
        $conditions = Hash::merge([
            'model' => $model,
            'foreign_key' => $foreignKey
        ], $conditions);
        return $this->find()
            ->where($conditions)
            ->count();
    }

    /**
     * Builds a diff for a given history entry
     *
     * @param  ModelHistory  $historyEntry  ModelHistory Entry to build diff for
     * @return array
     */
    public function buildDiff(ModelHistory $historyEntry)
    {
        if ($historyEntry->revision == 1) {
            return [];
        }

        $previousRevisions = $this->find()
            ->where([
                'model' => $historyEntry->model,
                'foreign_key' => $historyEntry->foreign_key,
                'revision <' => $historyEntry->revision
            ])
            ->order(['revision' => 'DESC'])
            ->toArray();

        $diffOutput = [
            'changed' => [],
            'changedBefore' => [],
            'unchanged' => []
        ];

        // 1. Get old values for changed fields in passed entry, ignore arrays
        foreach ($historyEntry->data as $fieldName => $newValue) {
            foreach ($previousRevisions as $revision) {
                if (isset($revision->data[$fieldName])) {
                    if (is_array($revision->data[$fieldName])) {
                        continue 2;
                    }
                    $diffOutput['changed'][$fieldName] = [
                        'old' => $revision->data[$fieldName],
                        'new' => $newValue
                    ];
                    continue 2;
                }
            }
        }

        $currentEntity = TableRegistry::get($historyEntry->model)->get($historyEntry->foreign_key);

        // 2. Try to get old values for any other fields defined in searchableFields and

        foreach (TableRegistry::get($historyEntry->model)->getFields() as $fieldName => $data) {
            foreach ($previousRevisions as $revisionIndex => $revision) {
                if (!isset($revision->data[$fieldName])) {
                    continue;
                }
                if (is_array($revision->data[$fieldName]) || isset($diffOutput['changed'][$fieldName])) {
                    continue 2;
                }
                if ($currentEntity->{$fieldName} instanceof Time || $currentEntity->{$fieldName} instanceof Date) {
                    $timeFormat = $currentEntity->{$fieldName}->format('Y-m-d') . 'T' . $currentEntity->{$fieldName}->format('H:i:s');
                    if ($timeFormat != $revision->data[$fieldName]) {
                        $diffOutput['changedBefore'][$fieldName] = [
                            'old' => $revision->data[$fieldName],
                            'new' => $timeFormat
                        ];
                        continue 2;
                    }
                }
                if ($revision->data[$fieldName] != $currentEntity->{$fieldName}) {
                    $diffOutput['changedBefore'][$fieldName] = [
                        'old' => $revision->data[$fieldName],
                        'new' => $currentEntity->{$fieldName}
                    ];
                    continue 2;
                }
            }
        }

        // 3. Get all unchanged fields

        foreach (TableRegistry::get($historyEntry->model)->getFields() as $fieldName => $data) {
            foreach ($previousRevisions as $revision) {
                if (!isset($revision->data[$fieldName])) {
                    continue;
                }
                if (is_array($revision->data[$fieldName]) || isset($diffOutput['changed'][$fieldName]) || isset($diffOutput['changedBefore'][$fieldName])) {
                    continue 2;
                }
                $diffOutput['unchanged'][$fieldName] = $currentEntity->{$fieldName};
                continue 2;
            }
        }

        return $diffOutput;
    }

}
